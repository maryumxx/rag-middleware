<?php
// Quick diagnostic — run with: php test.php
// Delete this file before going to production.

require_once __DIR__ . '/vendor/autoload.php';

// Load .env
$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$key = $_ENV['COHERE_API_KEY'] ?? 'NOT SET';
echo "API Key loaded: " . (strlen($key) > 8 ? substr($key, 0, 6) . '...' . substr($key, -4) : $key) . "\n";

// Try a raw curl call identical to what CohereClient does
$url  = 'https://api.cohere.com/v2/embed';
$body = json_encode([
    'model'      => 'embed-english-v3.0',
    'texts'      => ['test'],
    'input_type' => 'search_query',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ],
    CURLOPT_VERBOSE        => true,
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $status\n";
if ($error) echo "cURL Error: $error\n";
if ($response) echo "Response (first 200 chars): " . substr($response, 0, 200) . "\n";
