<?php

/**
 * =============================================================================
 * api/send-email.php — Email Travel Itinerary with Auto-Generated PDF
 * =============================================================================
 * Workflow:
 *   1. Parse POST payload (email address + itinerary text + travel_data)
 *   2. Validate SMTP configuration
 *   3. Auto-generate the itinerary PDF in memory using the shared pdf-builder
 *      (same function used by the download endpoint — no file saved to disk)
 *   4. If PDF generation fails → return error; do NOT send without attachment
 *   5. Build a clean, minimal HTML email (no decorative marketing content)
 *   6. Attach the PDF via PHPMailer::addStringAttachment() — no temp file
 *   7. Send via SMTP and return JSON response
 *
 * Dependencies:
 *   - includes/pdf-builder.php  (generateItineraryPdfBinary, buildPdfHtml)
 *   - includes/config.php       (APP_NAME, SMTP_*, sanitize())
 *   - vendor/phpmailer/phpmailer
 * =============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method Not Allowed.']);
  exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/pdf-builder.php';

// ── Load PHPMailer ───────────────────────────────────────────────────────────
$phpMailerLoaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
  require_once __DIR__ . '/../vendor/autoload.php';
  $phpMailerLoaded = true;
} elseif (file_exists(__DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
  require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
  require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
  require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
  $phpMailerLoaded = true;
}

if (!$phpMailerLoaded) {
  jsonResponse([
    'success' => false,
    'error'   => 'PHPMailer not found. Run: composer require phpmailer/phpmailer',
  ], 503);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

try {
  // ── Parse request body ───────────────────────────────────────────────────
  $rawBody = file_get_contents('php://input');
  $data    = json_decode($rawBody, true);

  if (!$data) {
    jsonResponse(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
  }

  $toEmail    = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
  $itinerary  = trim($data['itinerary'] ?? '');
  $travelData = $data['travel_data'] ?? [];

  if (!$toEmail) {
    jsonResponse(['success' => false, 'error' => 'A valid email address is required.'], 400);
  }
  if (empty($itinerary)) {
    jsonResponse(['success' => false, 'error' => 'Itinerary content is required.'], 400);
  }

  // Extract all travel data fields
  $destination  = sanitize($travelData['destination']   ?? 'Your Destination');
  $startDate    = sanitize($travelData['start_date']    ?? '');
  $endDate      = sanitize($travelData['end_date']      ?? '');
  $travellers   = sanitize((string)($travelData['travellers'] ?? '1'));
  $travelType   = sanitize($travelData['travel_type']   ?? '');
  $budget       = sanitize($travelData['budget']        ?? '');
  $budgetAmount = sanitize((string)($travelData['budget_amount'] ?? ''));
  $currency     = sanitize($travelData['currency']      ?? 'INR');
  $origin       = sanitize($travelData['origin']        ?? '');

  // ── Check SMTP config ────────────────────────────────────────────────────
  if (empty(SMTP_USERNAME) || SMTP_USERNAME === 'your_email@gmail.com') {
    jsonResponse([
      'success' => false,
      'error'   => 'SMTP credentials are not configured. Please update your .env file.',
    ], 503);
  }

  // ── Generate PDF in memory ───────────────────────────────────────────────
  // Uses the same generateItineraryPdfBinary() function as the download
  // endpoint — no temporary file is written to disk.
  $pdfBytes = generateItineraryPdfBinary(
    $destination,
    $startDate,
    $endDate,
    $travellers,
    $travelType,
    $budget,
    $budgetAmount,
    $currency,
    $origin,
    $itinerary
  );

  // If PDF generation failed, refuse to send the email without an attachment
  if ($pdfBytes === null) {
    jsonResponse([
      'success' => false,
      'error'   => 'Unable to generate itinerary PDF. Please try again.',
    ], 500);
  }

  // ── Build email HTML ─────────────────────────────────────────────────────
  $emailHtml = buildEmailHtml(
    $destination,
    $startDate,
    $endDate,
    $travellers,
    $travelType,
    $budget,
    $budgetAmount,
    $currency,
    $origin
  );

  // ── PDF filename for attachment ──────────────────────────────────────────
  // Format: Destination_Travel_Itinerary.pdf  e.g. Paris_Travel_Itinerary.pdf
  $pdfFilename = preg_replace('/[^A-Za-z0-9_]/', '_', str_replace(' ', '_', $destination))
    . '_Travel_Itinerary.pdf';

  // ── Plain-text fallback ──────────────────────────────────────────────────
  $altBody  = "Your AI Travel Itinerary for {$destination} is attached as a PDF.\n\n";
  $altBody .= "Destination   : {$destination}\n";
  $altBody .= $origin ? "Departing From: {$origin}\n" : '';
  if ($startDate && $endDate) {
    $altBody .= 'Travel Dates  : ' . date('d M Y', strtotime($startDate))
      . ' to ' . date('d M Y', strtotime($endDate)) . "\n";
  }
  $altBody .= "Travellers    : {$travellers}\n";
  $altBody .= "Travel Type   : {$travelType}\n";
  $altBody .= "Budget        : {$budget}" . ($budgetAmount ? " ({$currency} {$budgetAmount})" : '') . "\n\n";
  $altBody .= "The complete itinerary PDF is attached to this email.\n\n";
  $altBody .= "Generated by " . APP_NAME . " using IBM watsonx.ai Granite\n";
  $altBody .= "Developed by Harsh Champaneri\n";
  $altBody .= "© " . date('Y') . " AI Travel Planner. All rights reserved.\n";

  // ── Send via PHPMailer ───────────────────────────────────────────────────
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host       = SMTP_HOST;
  $mail->SMTPAuth   = true;
  $mail->Username   = SMTP_USERNAME;
  $mail->Password   = SMTP_PASSWORD;
  $mail->SMTPSecure = SMTP_PORT === 465
    ? PHPMailer::ENCRYPTION_SMTPS
    : PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = SMTP_PORT;
  $mail->CharSet    = 'UTF-8';
  $mail->isHTML(true);

  $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
  $mail->addAddress($toEmail);
  $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

  $mail->Subject = "Your Travel Itinerary: {$destination} | " . APP_NAME;
  $mail->Body    = $emailHtml;
  $mail->AltBody = $altBody;

  // Attach the PDF directly from memory — no temp file ever written to disk
  $mail->addStringAttachment(
    $pdfBytes,
    $pdfFilename,
    PHPMailer::ENCODING_BASE64,
    'application/pdf'
  );

  $mail->send();

  jsonResponse([
    'success' => true,
    'message' => "Itinerary sent successfully to {$toEmail}! Check your inbox.",
  ]);
} catch (MailException $e) {
  $msg = APP_DEBUG
    ? 'Mail error: ' . $e->getMessage()
    : 'Failed to send email. Please check your SMTP settings.';
  jsonResponse(['success' => false, 'error' => $msg], 500);
} catch (Throwable $e) {
  $msg = APP_DEBUG ? $e->getMessage() : 'An unexpected error occurred.';
  jsonResponse(['success' => false, 'error' => $msg], 500);
}

// =============================================================================
// EMAIL TEMPLATE — Booking.com / Airbnb style: plain, minimal, B&W
// =============================================================================

/**
 * Build a clean, professional transactional email modelled on Booking.com /
 * Airbnb itinerary confirmation emails.
 *
 * Design rules:
 *   ─ Pure white card, 600 px max-width, light grey outer background
 *   ─ Thin top accent bar (solid dark) instead of a gradient hero
 *   ─ All colours: #000000 (text), #333333 (body), #666666 (muted), #f5f5f5 (alt rows)
 *   ─ Only tables + inline CSS — no <style> blocks, no classes
 *   ─ Compatible with Gmail, Outlook 2016+, Yahoo Mail, Apple Mail
 *   ─ No emoji in subject or body text that may break Outlook rendering
 *     (only safe HTML entity &#128206; is used for the paperclip)
 *   ─ PDF is the document; email is just a short notification
 */
function buildEmailHtml(
  string $destination,
  string $startDate,
  string $endDate,
  string $travellers,
  string $travelType,
  string $budget,
  string $budgetAmount,
  string $currency,
  string $origin
): string {
  $appName   = APP_NAME;
  $year      = date('Y');
  $generated = date('F j, Y \a\t g:i A');

  // ── Formatted dates ───────────────────────────────────────────────────────
  $fmtStart = $startDate ? date('d M Y', strtotime($startDate)) : '&mdash;';
  $fmtEnd   = $endDate   ? date('d M Y', strtotime($endDate))   : '&mdash;';
  $dateStr  = ($startDate && $endDate) ? "{$fmtStart} &ndash; {$fmtEnd}" : '&mdash;';

  // ── Duration ─────────────────────────────────────────────────────────────
  $duration = '&mdash;';
  if ($startDate && $endDate) {
    $days     = (int) ceil((strtotime($endDate) - strtotime($startDate)) / 86400);
    $nights   = max(0, $days - 1);
    $duration = $days   . ' Day'   . ($days   !== 1 ? 's' : '')
      . ' / ' . $nights . ' Night' . ($nights !== 1 ? 's' : '');
  }

  // ── Budget display ────────────────────────────────────────────────────────
  $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $budgetLine = $h($budget);
  if ($budgetAmount) {
    $budgetLine .= ' &mdash; ' . $h($currency) . ' ' . $h($budgetAmount);
  }

  // ── Table row helper: label (grey bg) | value (white bg) ─────────────────
  // Both cells share the same bottom border; last row omits it via caller.
  $row = fn(string $label, string $value, bool $last = false) =>
  '<tr>'
    . '<td width="38%" style="padding:11px 16px;'
    .   ($last ? '' : 'border-bottom:1px solid #e8e8e8;')
    .   'font-size:13px;font-weight:600;color:#333333;background:#f5f5f5;'
    .   'vertical-align:top;">' . $h($label) . '</td>'
    . '<td style="padding:11px 16px;'
    .   ($last ? '' : 'border-bottom:1px solid #e8e8e8;')
    .   'font-size:13px;color:#000000;vertical-align:top;">' . $value . '</td>'
    . '</tr>';

  // ── Conditional rows ──────────────────────────────────────────────────────
  $originRow = $origin ? $row('Travelling From', $h($origin)) : '';

  // Last data row before "Generated On" — no bottom border on the very last
  $generatedRow = $row('Generated On', $h($generated), true);

  return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
  <title>Travel Itinerary &mdash; {$destination}</title>
</head>
<!--
  Outer table: light grey page background
  Inner card:  white, 600 px, thin 1 px border, no border-radius (Outlook)
-->
<body style="margin:0;padding:0;background-color:#f0f0f0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background-color:#f0f0f0;padding:32px 0;">
  <tr>
    <td align="center" style="padding:0 16px;">

      <!-- ══════════════ EMAIL CARD ══════════════ -->
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
             style="width:100%;max-width:600px;background-color:#ffffff;
                    border:1px solid #dddddd;border-collapse:collapse;">

        <!-- ── TOP ACCENT BAR ──────────────────────────────── -->
        <tr>
          <td style="background-color:#111111;padding:0;height:4px;font-size:1px;line-height:1px;">
            &nbsp;
          </td>
        </tr>

        <!-- ── HEADER ──────────────────────────────────────── -->
        <tr>
          <td style="padding:28px 32px 20px 32px;border-bottom:1px solid #e8e8e8;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <p style="margin:0 0 4px 0;font-size:11px;font-weight:700;color:#888888;
                     letter-spacing:1.5px;text-transform:uppercase;
                     font-family:Arial,Helvetica,sans-serif;">
                    AI Travel Planner
                  </p>
                  <p style="margin:0;font-size:22px;font-weight:700;color:#111111;
                     font-family:Arial,Helvetica,sans-serif;line-height:1.3;">
                    Your travel itinerary is ready
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ── GREETING ────────────────────────────────────── -->
        <tr>
          <td style="padding:24px 32px 20px 32px;">
            <p style="margin:0 0 12px 0;font-size:15px;font-weight:600;color:#111111;
               font-family:Arial,Helvetica,sans-serif;">
              Hello,
            </p>
            <p style="margin:0 0 10px 0;font-size:14px;color:#333333;line-height:1.7;
               font-family:Arial,Helvetica,sans-serif;">
              Thank you for using <strong style="color:#111111;">{$appName}</strong>.
              Your personalized travel itinerary for
              <strong style="color:#111111;">{$destination}</strong>
              has been generated successfully.
            </p>
            <p style="margin:0;font-size:14px;color:#333333;line-height:1.7;
               font-family:Arial,Helvetica,sans-serif;">
              The complete itinerary is attached to this email as a PDF.
              Please open the attachment to view your full travel plan.
            </p>
          </td>
        </tr>

        <!-- ── SECTION LABEL: TRIP DETAILS ─────────────────── -->
        <tr>
          <td style="padding:0 32px 8px 32px;">
            <p style="margin:0;font-size:11px;font-weight:700;color:#888888;
               letter-spacing:1.5px;text-transform:uppercase;
               font-family:Arial,Helvetica,sans-serif;">
              Trip Details
            </p>
          </td>
        </tr>

        <!-- ── TRIP DETAILS TABLE ──────────────────────────── -->
        <tr>
          <td style="padding:0 32px 24px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;border:1px solid #e8e8e8;width:100%;">
              {$row('Destination',$h($destination))}
              {$originRow}
              {$row('Travel Dates',$dateStr)}
              {$row('Duration',$duration)}
              {$row('Travellers',$h($travellers))}
              {$row('Trip Type',$h($travelType ?: '—'))}
              {$row('Budget',$budgetLine ?: '&mdash;')}
              {$generatedRow}
            </table>
          </td>
        </tr>

        <!-- ── PDF ATTACHED BOX ────────────────────────────── -->
        <tr>
          <td style="padding:0 32px 24px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;border:1px solid #dddddd;
                          border-left:3px solid #111111;background-color:#f9f9f9;">
              <tr>
                <td style="padding:14px 18px;">
                  <p style="margin:0 0 6px 0;font-size:13px;font-weight:700;color:#111111;
                     font-family:Arial,Helvetica,sans-serif;">
                    PDF Attached &mdash; {$destination} Travel Itinerary
                  </p>
                  <p style="margin:0;font-size:13px;color:#444444;line-height:1.65;
                     font-family:Arial,Helvetica,sans-serif;">
                    The attached PDF contains your complete itinerary, including:
                    Destination Overview, Day-wise Itinerary, Hotel Recommendations,
                    Budget Breakdown, Tourist Attractions, Local Food Guide,
                    Packing Checklist, Travel Tips, Safety Guidelines,
                    and Eco-Friendly Suggestions.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ── CLOSING ─────────────────────────────────────── -->
        <tr>
          <td style="padding:0 32px 28px 32px;border-top:1px solid #e8e8e8;">
            <p style="margin:16px 0 10px 0;font-size:14px;color:#333333;line-height:1.7;
               font-family:Arial,Helvetica,sans-serif;">
              Thank you for choosing <strong style="color:#111111;">{$appName}</strong>.
              We wish you a safe and memorable journey.
            </p>
            <p style="margin:0;font-size:14px;font-weight:600;color:#111111;
               font-family:Arial,Helvetica,sans-serif;">
              Happy Travelling!
            </p>
          </td>
        </tr>

        <!-- ── FOOTER ──────────────────────────────────────── -->
        <tr>
          <td style="padding:18px 32px;background-color:#f5f5f5;
              border-top:1px solid #e8e8e8;text-align:center;">
            <p style="margin:0 0 4px 0;font-size:13px;font-weight:700;color:#333333;
               font-family:Arial,Helvetica,sans-serif;">
              {$appName}
            </p>
            <p style="margin:0 0 4px 0;font-size:12px;color:#666666;
               font-family:Arial,Helvetica,sans-serif;">
              Powered by IBM watsonx.ai Granite
              &nbsp;&bull;&nbsp;
              Developed by Harsh Rakeshkumar Champaneri
            </p>
            <p style="margin:0 0 10px 0;font-size:11px;color:#888888;line-height:1.6;
               font-family:Arial,Helvetica,sans-serif;">
              This itinerary is AI-generated for planning purposes only. Please verify all hotel
              availability, transport schedules, weather conditions, visa requirements,
              and travel advisories before travelling.
            </p>
            <p style="margin:0;font-size:11px;color:#aaaaaa;
               font-family:Arial,Helvetica,sans-serif;">
              &copy; {$year} TourVerse. All rights reserved.
            </p>
          </td>
        </tr>

        <!-- ── BOTTOM ACCENT BAR ───────────────────────────── -->
        <tr>
          <td style="background-color:#111111;padding:0;height:3px;font-size:1px;line-height:1px;">
            &nbsp;
          </td>
        </tr>

      </table>
      <!-- /email card -->

    </td>
  </tr>
</table>

</body>
</html>
HTML;
}
