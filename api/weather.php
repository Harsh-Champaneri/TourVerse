<?php

/**
 * =============================================================================
 * api/weather.php — Fetch Current Weather via OpenWeather API
 * =============================================================================
 * Returns current weather conditions for a destination city.
 * =============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

try {
    // Validate input
    $city = sanitize(trim($_GET['city'] ?? ''));

    if (empty($city)) {
        jsonResponse(['success' => false, 'error' => 'City parameter is required.'], 400);
    }

    // Check API key — only fall back to mock when the key is genuinely absent
    if (empty(OPENWEATHER_API_KEY)) {
        jsonResponse([
            'success' => false,
            'error'   => 'OpenWeather API key is not configured.',
            'mock'    => true,
            'data'    => getMockWeather($city),
        ]);
    }

    // Build OpenWeather API request
    $url = OPENWEATHER_BASE_URL . '?' . http_build_query([
        'q'     => $city,
        'appid' => OPENWEATHER_API_KEY,
        'units' => 'metric',  // Celsius
        'lang'  => 'en',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException("Weather API cURL error: $curlError");
    }

    $weatherData = json_decode($response, true);

    if ($httpCode === 404 || ($weatherData['cod'] ?? '') == '404') {
        jsonResponse(['success' => false, 'error' => "City '$city' not found. Try a more specific name."], 404);
    }

    if ($httpCode !== 200) {
        $msg = $weatherData['message'] ?? "OpenWeather API error ($httpCode)";
        throw new RuntimeException($msg);
    }

    // Format and return the weather data
    jsonResponse([
        'success' => true,
        'data'    => formatWeather($weatherData),
    ]);
} catch (RuntimeException $e) {
    $message = APP_DEBUG ? $e->getMessage() : 'Unable to fetch weather data.';
    jsonResponse(['success' => false, 'error' => $message], 500);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => 'Unexpected error fetching weather.'], 500);
}

// ---------------------------------------------------------------------------
// Helper: Format the raw OpenWeather response into a clean structure
// ---------------------------------------------------------------------------
function formatWeather(array $data): array
{
    return [
        'city'        => $data['name'] ?? 'Unknown',
        'country'     => $data['sys']['country'] ?? '',
        'temperature' => round($data['main']['temp'] ?? 0),
        'feels_like'  => round($data['main']['feels_like'] ?? 0),
        'humidity'    => $data['main']['humidity'] ?? 0,
        'description' => ucfirst($data['weather'][0]['description'] ?? ''),
        'icon'        => $data['weather'][0]['icon'] ?? '01d',
        'icon_url'    => 'https://openweathermap.org/img/wn/' . ($data['weather'][0]['icon'] ?? '01d') . '@2x.png',
        'wind_speed'  => round(($data['wind']['speed'] ?? 0) * 3.6, 1), // m/s to km/h
        'visibility'  => isset($data['visibility']) ? round($data['visibility'] / 1000, 1) . ' km' : 'N/A',
        'pressure'    => $data['main']['pressure'] ?? 0,
        'temp_min'    => round($data['main']['temp_min'] ?? 0),
        'temp_max'    => round($data['main']['temp_max'] ?? 0),
        'sunrise'     => isset($data['sys']['sunrise']) ? date('h:i A', $data['sys']['sunrise']) : 'N/A',
        'sunset'      => isset($data['sys']['sunset'])  ? date('h:i A', $data['sys']['sunset'])  : 'N/A',
        'lat'         => $data['coord']['lat'] ?? null,
        'lon'         => $data['coord']['lon'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Helper: Return mock weather data when API key is not configured
// ---------------------------------------------------------------------------
function getMockWeather(string $city): array
{
    return [
        'city'        => $city,
        'country'     => 'IN',
        'temperature' => 28,
        'feels_like'  => 31,
        'humidity'    => 65,
        'description' => 'Partly cloudy',
        'icon'        => '02d',
        'icon_url'    => 'https://openweathermap.org/img/wn/02d@2x.png',
        'wind_speed'  => 14.4,
        'visibility'  => '10 km',
        'pressure'    => 1013,
        'temp_min'    => 24,
        'temp_max'    => 32,
        'sunrise'     => '06:15 AM',
        'sunset'      => '06:45 PM',
        'lat'         => 20.5937,
        'lon'         => 78.9629,
        'mock'        => true,
    ];
}
