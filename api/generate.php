<?php

/**
 * =============================================================================
 * api/generate.php — Generate Travel Itinerary via watsonx.ai
 * =============================================================================
 * Accepts POST JSON with travel details, returns the AI-generated itinerary.
 * =============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/watsonx.php';

try {
    // -----------------------------------------------------------------------
    // 1. Parse & validate request body
    // -----------------------------------------------------------------------
    $rawBody = file_get_contents('php://input');
    $data    = json_decode($rawBody, true);

    if (!$data || !is_array($data)) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
    }

    // Required field validation
    $required = ['destination', 'start_date', 'end_date', 'travellers', 'travel_type', 'budget'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(['success' => false, 'error' => "Missing required field: $field"], 400);
        }
    }

    // Sanitize the entire input array
    $travelData = sanitizeJson($data);

    // Validate date range
    $startTs = strtotime($travelData['start_date']);
    $endTs   = strtotime($travelData['end_date']);

    if (!$startTs || !$endTs) {
        jsonResponse(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.'], 400);
    }

    if ($endTs <= $startTs) {
        jsonResponse(['success' => false, 'error' => 'End date must be after start date.'], 400);
    }

    $days = (int) ceil(($endTs - $startTs) / 86400);
    if ($days > 30) {
        jsonResponse(['success' => false, 'error' => 'Trip duration cannot exceed 30 days.'], 400);
    }

    // -----------------------------------------------------------------------
    // 2. Ensure watsonx.ai credentials are configured
    // -----------------------------------------------------------------------
    if (empty(WATSONX_API_KEY) || WATSONX_API_KEY === 'your_ibm_cloud_api_key_here') {
        jsonResponse([
            'success' => false,
            'error'   => 'IBM watsonx.ai API key is not configured. Please check your .env file.',
        ], 503);
    }

    if (empty(WATSONX_PROJECT_ID) || WATSONX_PROJECT_ID === 'your_watsonx_project_id_here') {
        jsonResponse([
            'success' => false,
            'error'   => 'IBM watsonx.ai Project ID is not configured. Please check your .env file.',
        ], 503);
    }

    // -----------------------------------------------------------------------
    // 3. Generate itinerary via watsonx.ai
    // -----------------------------------------------------------------------
    $watsonx = new WatsonxAI();
    $prompt  = $watsonx->buildTravelPrompt($travelData);
    $itinerary = $watsonx->generate($prompt, [
        'max_tokens'  => 2500,
        'temperature' => 0.72,
    ]);

    // -----------------------------------------------------------------------
    // 4. Return structured response
    // -----------------------------------------------------------------------
    jsonResponse([
        'success'     => true,
        'itinerary'   => $itinerary,
        'destination' => sanitize($travelData['destination']),
        'days'        => $days,
        'travel_data' => [
            'destination'   => sanitize($travelData['destination']),
            'start_date'    => sanitize($travelData['start_date']),
            'end_date'      => sanitize($travelData['end_date']),
            'travellers'    => sanitize((string)$travelData['travellers']),
            'travel_type'   => sanitize($travelData['travel_type']),
            'budget'        => sanitize($travelData['budget']),
            'budget_amount' => sanitize((string)($travelData['budget_amount'] ?? '')),
            'currency'      => sanitize($travelData['currency'] ?? 'INR'),
            'origin'        => sanitize($travelData['origin'] ?? 'India'),
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'model'        => WATSONX_MODEL_ID,
    ]);
} catch (RuntimeException $e) {
    // Known runtime errors (API failures, config issues)
    $message = APP_DEBUG ? $e->getMessage() : 'An error occurred while generating your itinerary. Please try again.';
    jsonResponse(['success' => false, 'error' => $message], 500);
} catch (Throwable $e) {
    // Catch-all for unexpected errors
    $message = APP_DEBUG ? $e->getMessage() : 'An unexpected error occurred.';
    jsonResponse(['success' => false, 'error' => $message], 500);
}
