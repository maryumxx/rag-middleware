<?php

declare(strict_types=1);

namespace RAG;

class CohereClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.cohere.com/v2';

    public function __construct()
    {
        $key = $_ENV['COHERE_API_KEY'] ?? getenv('COHERE_API_KEY');
        if (empty($key)) {
            throw new \RuntimeException('COHERE_API_KEY environment variable is not set.');
        }
        $this->apiKey = $key;
    }

    /**
     * Embed an array of texts.
     *
     * @param  string[] $texts
     * @param  string   $inputType  'search_document' or 'search_query'
     * @return float[][]            Array of embedding vectors
     */
    public function embed(array $texts, string $inputType): array
    {
        if (empty($texts)) {
            return [];
        }

        $payload = [
            'model'      => 'embed-english-v3.0',
            'texts'      => array_values($texts),
            'input_type' => $inputType,
        ];

        $response = $this->post('/embed', $payload);

        // Cohere v2 API returns embeddings nested under embeddings.float
        $embeddings = $response['embeddings']['float']
                   ?? $response['embeddings']
                   ?? null;

        if (!is_array($embeddings)) {
            throw new \RuntimeException('Cohere embed response missing "embeddings.float" key.');
        }

        return $embeddings;
    }

    /**
     * Chat with the model.
     *
     * @param  string                            $systemPrompt
     * @param  array{role:string,content:string}[] $history
     * @param  string                            $message
     * @return string
     */
    public function chat(string $systemPrompt, array $history, string $message): string
    {
        // Cohere v2: system prompt is the first message with role "system"
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add history
        foreach ($history as $turn) {
            $role = ($turn['role'] === 'user') ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => (string)($turn['content'] ?? '')];
        }

        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];

        $payload = [
            'model'    => 'command-r-08-2024',
            'messages' => $messages,
        ];

        $response = $this->post('/chat', $payload);

        $answer = $response['message']['content'][0]['text']
                  ?? $response['text']
                  ?? null;

        if ($answer === null) {
            throw new \RuntimeException('Cohere chat response missing expected text field.');
        }

        return $answer;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * POST to the Cohere API with exponential backoff on 429 responses.
     *
     * @param  string  $path
     * @param  mixed[] $payload
     * @return mixed[]
     */
    private function post(string $path, array $payload): array
    {
        $url     = $this->baseUrl . $path;
        $body    = json_encode($payload, JSON_THROW_ON_ERROR);
        $delays  = [1, 2, 4]; // seconds between retries
        $attempt = 0;

        while (true) {
            [$status, $responseBody] = $this->httpPost($url, $body);

            if ($status === 200) {
                return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($status === 429 && $attempt < count($delays)) {
                sleep($delays[$attempt]);
                $attempt++;
                continue;
            }

            throw new \RuntimeException(
                "Cohere API error {$status} on {$path}: " . substr($responseBody, 0, 300)
            );
        }
    }

    /**
     * Find a usable CA certificate bundle on the current system.
     * Returns null if none found (curl will fall back to no verification).
     */
    private function findCaBundle(): ?string
    {
        // 1. Explicit path in environment (highest priority)
        $env = $_ENV['CURL_CA_BUNDLE'] ?? getenv('CURL_CA_BUNDLE');
        if ($env && file_exists($env)) {
            return $env;
        }

        // 2. php.ini curl.cainfo
        $ini = ini_get('curl.cainfo');
        if ($ini && file_exists($ini)) {
            return $ini;
        }

        // 3. Common Windows locations (PHP zip install, XAMPP, etc.)
        $windowsPaths = [
            'C:\\php\\cacert.pem',
            'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
            'C:\\wamp64\\bin\\php\\cacert.pem',
        ];
        foreach ($windowsPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // 4. Common Linux/Mac locations
        $unixPaths = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
            '/etc/ssl/cert.pem',
        ];
        foreach ($unixPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Low-level cURL POST. Returns [http_status, response_body].
     *
     * @return array{int, string}
     */
    private function httpPost(string $url, string $body): array
    {
        $ch = curl_init($url);

        // Locate a CA bundle — handles Windows (no built-in bundle) and Linux/Mac
        $caBundle = $this->findCaBundle();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
        ];

        if ($caBundle !== null) {
            $opts[CURLOPT_CAINFO]         = $caBundle;
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            // Last-resort fallback for local dev when no CA bundle is found
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        // Force Google DNS — fixes "Could not resolve host" on Windows
        // where PHP's curl may not use the system DNS resolver.
        $opts[CURLOPT_DNS_SERVERS] = '8.8.8.8,8.8.4.4';

        curl_setopt_array($ch, $opts);

        $response   = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('cURL error contacting Cohere: ' . $curlError);
        }

        return [$httpStatus, (string) $response];
    }
}
