<?php

/**
 * =============================================================================
 * watsonx.php — IBM watsonx.ai Granite API Helper
 * =============================================================================
 * Handles:
 *   - IBM IAM token retrieval and caching
 *   - watsonx.ai text generation API calls
 *   - Structured prompt building for travel itineraries
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

class WatsonxAI
{
    // -----------------------------------------------------------------------
    // Token cache (file-based to survive across requests)
    // -----------------------------------------------------------------------
    private static string $tokenCacheFile;
    private static ?string $cachedToken   = null;
    private static int     $tokenExpiry   = 0;

    public function __construct()
    {
        // Store token cache in a writable temp-like folder
        self::$tokenCacheFile = sys_get_temp_dir() . '/watsonx_token_cache.json';
    }

    // -----------------------------------------------------------------------
    // Get a valid IBM IAM Bearer Token (auto-refresh when expired)
    // -----------------------------------------------------------------------
    public function getAccessToken(): string
    {
        // 1. Check in-memory cache first
        if (self::$cachedToken && time() < self::$tokenExpiry) {
            return self::$cachedToken;
        }

        // 2. Check file cache
        if (file_exists(self::$tokenCacheFile)) {
            $cache = json_decode(file_get_contents(self::$tokenCacheFile), true);
            if ($cache && isset($cache['token'], $cache['expiry']) && time() < (int)$cache['expiry']) {
                self::$cachedToken = $cache['token'];
                self::$tokenExpiry = (int)$cache['expiry'];
                return self::$cachedToken;
            }
        }

        // 3. Fetch a new token from IBM IAM
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => IBM_IAM_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ibm:params:oauth:grant-type:apikey',
                'apikey'     => WATSONX_API_KEY,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("cURL error while fetching IAM token: $curlError");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['errorMessage'] ?? $data['errorDescription'] ?? 'Unknown IAM error';
            throw new RuntimeException("IBM IAM token error ($httpCode): $msg");
        }

        // Cache the token; IBM tokens expire in ~3600s — we refresh 5 min early
        self::$cachedToken = $data['access_token'];
        self::$tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 300;

        // Persist to file cache
        file_put_contents(self::$tokenCacheFile, json_encode([
            'token'  => self::$cachedToken,
            'expiry' => self::$tokenExpiry,
        ]));

        return self::$cachedToken;
    }

    // -----------------------------------------------------------------------
    // Core text generation call to watsonx.ai
    // -----------------------------------------------------------------------
    public function generate(string $prompt, array $options = []): string
    {
        $token      = $this->getAccessToken();
        $endpoint   = WATSONX_URL . '/ml/v1/text/generation?version=2023-05-29';

        $payload = [
            'model_id'   => WATSONX_MODEL_ID,
            'project_id' => WATSONX_PROJECT_ID,
            'input'      => $prompt,
            'parameters' => [
                'max_new_tokens'     => $options['max_tokens']   ?? WATSONX_MAX_TOKENS,
                'temperature'        => $options['temperature']  ?? WATSONX_TEMPERATURE,
                'top_p'              => $options['top_p']        ?? WATSONX_TOP_P,
                'top_k'              => $options['top_k']        ?? WATSONX_TOP_K,
                'repetition_penalty' => $options['rep_penalty']  ?? WATSONX_REPETITION_PENALTY,
                'stop_sequences'     => [],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint, 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_TIMEOUT        => 120, // AI generation can be slow
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("cURL error during generation: $curlError");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $data['errors'][0]['message']
                ?? $data['error']
                ?? 'Unknown watsonx.ai error';
            throw new RuntimeException("watsonx.ai API error ($httpCode): $msg");
        }

        $text = $data['results'][0]['generated_text'] ?? '';

        if (empty($text)) {
            throw new RuntimeException('watsonx.ai returned an empty response.');
        }

        return trim($text);
    }

    // -----------------------------------------------------------------------
    // Build a structured prompt for travel itinerary generation
    // -----------------------------------------------------------------------
    public function buildTravelPrompt(array $travelData): string
    {
        // Safely extract and sanitize all fields
        $destination   = sanitize($travelData['destination']   ?? 'Unknown Destination');
        $startDate     = sanitize($travelData['start_date']    ?? 'Not specified');
        $endDate       = sanitize($travelData['end_date']      ?? 'Not specified');
        $travellers    = sanitize($travelData['travellers']    ?? '1');
        $travelType    = sanitize($travelData['travel_type']   ?? 'Solo');
        $budget        = sanitize($travelData['budget']        ?? 'Mid-range');
        $currency      = sanitize($travelData['currency']      ?? 'INR');
        $budgetAmount  = sanitize($travelData['budget_amount'] ?? 'Not specified');
        $interests     = is_array($travelData['interests'])
            ? implode(', ', array_map('sanitize', $travelData['interests']))
            : sanitize($travelData['interests'] ?? 'General sightseeing');
        $dietPref      = sanitize($travelData['diet']          ?? 'No preference');
        $mobility      = sanitize($travelData['mobility']      ?? 'No restrictions');
        $origin        = sanitize($travelData['origin']        ?? 'India');
        $language      = sanitize($travelData['language']      ?? 'English');
        $specialReq    = sanitize($travelData['special_req']   ?? 'None');

        // Calculate trip duration
        $days = 3;
        if ($startDate !== 'Not specified' && $endDate !== 'Not specified') {
            $start = strtotime($startDate);
            $end   = strtotime($endDate);
            if ($start && $end && $end > $start) {
                $days = (int) ceil(($end - $start) / 86400);
            }
        }

        return <<<PROMPT
<|system|>
{$this->getSystemPrompt()}
<|user|>
Please generate a complete travel itinerary with the following details:

**TRAVEL DETAILS:**
- Destination: {$destination}
- Travelling From: {$origin}
- Travel Dates: {$startDate} to {$endDate} ({$days} days)
- Number of Travellers: {$travellers}
- Travel Type: {$travelType}
- Budget Category: {$budget}
- Estimated Budget: {$budgetAmount} {$currency}
- Interests: {$interests}
- Dietary Preference: {$dietPref}
- Mobility/Accessibility: {$mobility}
- Preferred Language: {$language}
- Special Requirements: {$specialReq}

Generate a complete, detailed, day-wise itinerary for exactly {$days} days following
the output structure defined in your instructions. Be specific with place names,
timings, estimated costs in {$currency}, and practical tips.
<|assistant|>
PROMPT;
    }

    // -----------------------------------------------------------------------
    // System prompt assembled from AGENT_INSTRUCTIONS constant
    // -----------------------------------------------------------------------
    private function getSystemPrompt(): string
    {
        return AGENT_INSTRUCTIONS;
    }
}
