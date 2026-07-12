<?php

/**
 * =============================================================================
 * api/generate-pdf.php — Download Travel Itinerary as PDF
 * =============================================================================
 * PDF engine : Dompdf 3.x  (all generation logic lives in pdf-builder.php)
 * Fallback   : Browser print dialog (HTML response with window.print())
 *
 * This file is intentionally slim — it only:
 *   1. Parses the POST payload
 *   2. Calls generateItineraryPdfBinary() from the shared builder
 *   3. Streams the PDF to the browser (or falls back to HTML print)
 *
 * Do NOT duplicate PDF logic here. Edit includes/pdf-builder.php instead.
 * =============================================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/pdf-builder.php';

// ── Parse POST body ──────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);
if (!$data) {
    $data = $_POST;
}

$itinerary  = $data['itinerary'] ?? '';
$travelData = is_string($data['travel_data'] ?? null)
    ? json_decode($data['travel_data'], true)
    : ($data['travel_data'] ?? []);

$destination  = sanitize($travelData['destination']   ?? 'Travel Itinerary');
$startDate    = sanitize($travelData['start_date']    ?? '');
$endDate      = sanitize($travelData['end_date']      ?? '');
$travellers   = sanitize((string)($travelData['travellers'] ?? '1'));
$travelType   = sanitize($travelData['travel_type']   ?? '');
$budget       = sanitize($travelData['budget']        ?? '');
$budgetAmount = sanitize((string)($travelData['budget_amount'] ?? ''));
$currency     = sanitize($travelData['currency']      ?? 'INR');
$origin       = sanitize($travelData['origin']        ?? '');

$filename = 'itinerary-'
    . preg_replace('/[^a-z0-9-]/', '-', strtolower($destination))
    . '-' . date('Ymd') . '.pdf';

// ── Attempt Dompdf PDF generation ────────────────────────────────────────────
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

if ($pdfBytes !== null) {
    // Stream the generated PDF directly to the browser
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfBytes;
    exit;
}

// ── HTML print fallback (Dompdf unavailable or render error) ─────────────────
$htmlContent = buildPdfHtml(
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

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="' . $filename . '"');
echo $htmlContent . '<script>window.onload=function(){window.print();};</script>';
exit;
