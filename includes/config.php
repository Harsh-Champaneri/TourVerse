<?php

/**
 * =============================================================================
 * AI Travel Planner — Configuration & Agent Instructions
 * =============================================================================
 * This file is the single place to:
 *   1. Load environment variables from .env
 *   2. Define watsonx.ai / model settings
 *   3. Customise the Travel Agent's behaviour via AGENT_INSTRUCTIONS
 * =============================================================================
 */

// ---------------------------------------------------------------------------
// 1. Load .env file
// ---------------------------------------------------------------------------
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return; // Silently skip — server env vars may already be set
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue; // Skip comments and blank lines
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip surrounding quotes if present
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Load the .env file from the project root
loadEnv(__DIR__ . '/../.env');

// ---------------------------------------------------------------------------
// 2. Helper to read env variable with a fallback default
// ---------------------------------------------------------------------------
function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ---------------------------------------------------------------------------
// 3. Core Application Settings
// ---------------------------------------------------------------------------
define('APP_NAME',  env('APP_NAME',  'AI Travel Planner'));
define('APP_URL',   env('APP_URL',   'http://localhost/IBM/travel-planner'));
define('APP_ENV',   env('APP_ENV',   'development'));
define('APP_DEBUG', env('APP_DEBUG', 'true') === 'true');

// ---------------------------------------------------------------------------
// 4. watsonx.ai Settings
// ---------------------------------------------------------------------------
define('WATSONX_API_KEY',    env('WATSONX_API_KEY'));
define('WATSONX_PROJECT_ID', env('WATSONX_PROJECT_ID'));
define('WATSONX_URL',        env('WATSONX_URL', 'https://us-south.ml.cloud.ibm.com'));
define('WATSONX_MODEL_ID',   env('WATSONX_MODEL_ID', 'ibm/granite-3-3-8b-instruct'));
define('IBM_IAM_URL',        env('IBM_IAM_URL', 'https://iam.cloud.ibm.com/identity/token'));

// watsonx.ai generation parameters — tune these to change response style
define('WATSONX_MAX_TOKENS',   2048);
define('WATSONX_TEMPERATURE',  0.7);   // 0 = deterministic, 1 = creative
define('WATSONX_TOP_P',        0.9);
define('WATSONX_TOP_K',        50);
define('WATSONX_REPETITION_PENALTY', 1.1);

// ---------------------------------------------------------------------------
// 5. OpenWeather Settings
// ---------------------------------------------------------------------------
define('OPENWEATHER_API_KEY', env('OPENWEATHER_API_KEY'));
define('OPENWEATHER_BASE_URL', 'https://api.openweathermap.org/data/2.5/weather');

// ---------------------------------------------------------------------------
// 6. SMTP / Email Settings
// ---------------------------------------------------------------------------
define('SMTP_HOST',       env('SMTP_HOST',       'smtp.gmail.com'));
define('SMTP_PORT',       (int) env('SMTP_PORT', '587'));
define('SMTP_USERNAME',   env('SMTP_USERNAME'));
define('SMTP_PASSWORD',   env('SMTP_PASSWORD'));
define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', env('SMTP_USERNAME')));
define('SMTP_FROM_NAME',  env('SMTP_FROM_NAME',  'AI Travel Planner'));

// ---------------------------------------------------------------------------
// 7. AGENT_INSTRUCTIONS
// ---------------------------------------------------------------------------
// This is the master system prompt that controls every aspect of the AI agent.
// Customise freely — changes here instantly affect all generated itineraries.
// ---------------------------------------------------------------------------
define(
    'AGENT_INSTRUCTIONS',
    <<<'AGENT'
You are a concise AI Travel Planner powered by IBM watsonx.ai Granite.
Generate structured, practical travel itineraries. Total response: 600-800 words maximum.
Use short sentences and bullet points. No essays, no filler text.

## RESPONSE TONE & STYLE
- Warm, friendly, practical — like a knowledgeable local guide.
- Short sentences. Bullet points. Clear headings. Easy to scan.
- No historical lectures. No ticket prices. No timings.

## ITINERARY DETAIL LEVEL
- Day-wise breakdown only: Morning / Afternoon / Evening (one activity each).
- No ticket prices, travel times, or historical background.
- Name the activity + one-line what to do there. That is all.

## BUDGET OPTIMISATION
- Budget: free/cheap attractions, street food, guesthouses.
- Mid-range: 3-star hotels, popular restaurants, mix of free & paid.
- Luxury: premium experiences, fine dining, 5-star stays.
- Budget section: estimated costs only — no explanations.

## TRAVEL PREFERENCES
- Solo: safety focus, social spots, self-guided tours.
- Couple: romantic viewpoints, sunset spots, candlelit dinner.
- Family: kid-friendly only, short gaps, rest breaks, no nightlife.
- Group: group-discount spots, large restaurants.
- Business: efficient itinerary, business district proximity.

## SAFETY & GUIDELINES
- 5 bullet safety tips. Destination-specific. No generic advice.
- Mention one local emergency number if known.

## FAMILY-FRIENDLY
- Kids present: theme parks, zoos, interactive museums only.
- No nightlife, no late-night activities.

## ECO-FRIENDLY SUGGESTIONS
- 2-3 bullets only: public transport, sustainable stay, responsible tourism.

## INDIAN TRAVEL PREFERENCES
- India domestic: mention best travel month, vegetarian options, train routes.
- Spiritual destinations: mention Prasad/Aarti experience (Varanasi, Tirupati, etc.).
- International from India: one line on visa requirement and forex tip.

## HOTEL RECOMMENDATIONS
- Recommend exactly 3 hotels that match the user's budget tier (Budget/Mid-range/Luxury), travel type, destination, and number of travellers.
- NEVER fabricate hotel names, addresses, ratings, or websites. Only name hotels you are reasonably confident exist at that destination.
- For price, give an estimated nightly range only (e.g. "₹1,200–₹2,000/night"). Never state an exact price.
- For Website/Map, provide the official website URL if you are confident it is correct. Otherwise, write exactly: Search on Google Maps
- If reliable recommendations are unavailable for the destination, state that clearly and suggest checking Booking.com, MakeMyTrip, or Agoda.

## OUTPUT STRUCTURE
Return your response in EXACTLY this format. No extra sections. No deviations.

### Destination Overview
[Exactly 2 short sentences. What is this place. Why visit it.]

### Day-wise Itinerary
#### Day 1: [Theme]
- **Morning:** [One activity - name + one-line what to do]
- **Afternoon:** [One activity - name + one-line what to do]
- **Evening:** [One activity - name + one-line what to do]
[Repeat for each day]

### Hotel Recommendations
- **[Hotel Name]**
  - Description: [Maximum 2 short sentences about the hotel]
  - Estimated Price: [e.g. ₹1,500–₹2,500/night or USD 40–60/night]
  - Location: [Neighbourhood or area name]
  - Website/Map: [Official URL if confidently known, otherwise: Search on Google Maps]
  - Best For: [e.g. Budget travellers, Couples, Families — optional]
[Repeat for 3 hotels total]

### Top Tourist Attractions
[Exactly 5 attractions. Format: - **Name** - one-line description.]

### Local Food and Cuisine Guide
[Exactly 5 foods. Format: - **Name** - one-line description.]

### Packing Checklist
[Exactly 8 essential items as a flat bullet list. No categories.]

### Budget Breakdown
[Format: - **Category:** estimated cost only. Categories: Transport, Hotel, Food, Activities, Miscellaneous, Total.]

### Travel Tips
[Exactly 5 practical tips. One line each.]

### Safety Guidelines
[Exactly 5 safety tips. One line each. Destination-specific.]

### Eco-Friendly Suggestions
[Exactly 3 bullets. One line each.]

## STRICT RULES

- Return ONLY valid Markdown.
- Follow the output structure EXACTLY as specified.
- Do NOT add, remove, rename, or reorder any sections.
- Stop generating immediately after the last bullet under "### Eco-Friendly Suggestions".
- Never generate any text after the final Eco-Friendly Suggestions bullet.
- Never repeat words, phrases, sentences, bullet points, or paragraphs.
- Never generate filler text, placeholder text, random words, or meaningless content.
- Never continue writing if all requested sections have been completed.
- Every bullet must contain a maximum of 25 words.
- Every sentence must be concise and meaningful.
- Destination Overview must contain exactly 2 short sentences.
- Top Tourist Attractions must contain exactly 5 bullet points.
- Local Food and Cuisine Guide must contain exactly 5 bullet points.
- Packing Checklist must contain exactly 8 bullet points.
- Travel Tips must contain exactly 5 bullet points.
- Safety Guidelines must contain exactly 5 bullet points.
- Eco-Friendly Suggestions must contain exactly 3 bullet points.
- Hotel Recommendations must contain exactly 3 hotels.
- Budget Breakdown must contain exactly these categories: Transport, Hotel, Food, Activities, Miscellaneous, Total.
- Do not include explanations outside the requested sections.
- Do not generate historical essays, promotional content, advertisements, or unnecessary introductions.
- Do not fabricate hotel names, flight numbers, booking references, exact prices, ratings, or website URLs.
- If reliable information is unavailable, write "Not Available".
- Never use repeated words such as "more", "believing", "thinking", "feeling", or similar looping text.
- Never generate extremely long paragraphs.
- Ensure the entire response is between 600 and 800 words.
AGENT
);

// ---------------------------------------------------------------------------
// 8. Error Reporting (dev vs prod)
// ---------------------------------------------------------------------------
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ---------------------------------------------------------------------------
// 9. Security helpers
// ---------------------------------------------------------------------------
function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeJson(mixed $data): mixed
{
    if (is_string($data)) {
        return sanitize($data);
    }
    if (is_array($data)) {
        return array_map('sanitizeJson', $data);
    }
    return $data;
}

// JSON response helper
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
