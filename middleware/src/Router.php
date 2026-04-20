<?php

declare(strict_types=1);

namespace RAG;

class Router
{
    private string $secret;

    public function __construct()
    {
        $secret = $_ENV['ALLOWED_PLUGIN_SECRET'] ?? getenv('ALLOWED_PLUGIN_SECRET');
        if (empty($secret)) {
            throw new \RuntimeException('ALLOWED_PLUGIN_SECRET environment variable is not set.');
        }
        $this->secret = $secret;
    }

    public function dispatch(): void
    {
        $this->sendCorsHeaders();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path   = '/' . ltrim((string) $path, '/');

        // Preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        try {
            match (true) {
                $method === 'POST' && $path === '/ingest' => $this->handleIngest(),
                $method === 'POST' && $path === '/chat'   => $this->handleChat(),
                $method === 'GET'  && $path === '/status' => $this->handleStatus(),
                $method === 'GET'  && $path === '/health' => $this->handleHealth(),
                default                                   => $this->json(['error' => 'Not found'], 404),
            };
        } catch (\Throwable $e) {
            error_log('[RAG Router] Unhandled exception: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => 'Internal server error.'], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Route handlers
    // -------------------------------------------------------------------------

    private function handleIngest(): void
    {
        $this->requireSecret();

        // Validate uploaded file
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['success' => false, 'error' => 'PDF file is required.'], 400);
            return;
        }

        $file = $_FILES['pdf'];

        if ($file['size'] > 20 * 1024 * 1024) {
            $this->json(['success' => false, 'error' => 'File exceeds 20 MB limit.'], 400);
            return;
        }

        if (!$this->isPdf($file['tmp_name'])) {
            $this->json(['success' => false, 'error' => 'Only PDF files are accepted.'], 400);
            return;
        }

        $siteId = trim($_POST['site_id'] ?? '');
        if (!$this->isValidUuid($siteId)) {
            $this->json(['success' => false, 'error' => 'site_id must be a valid UUID.'], 400);
            return;
        }

        // Extract text
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($file['tmp_name']);
        $text   = $pdf->getText();

        if (empty(trim($text))) {
            $this->json(['success' => false, 'error' => 'Could not extract text from PDF.'], 422);
            return;
        }

        // Chunk
        $chunker = new Chunker();
        $chunks  = $chunker->chunk($text);

        if (empty($chunks)) {
            $this->json(['success' => false, 'error' => 'PDF produced no usable text chunks.'], 422);
            return;
        }

        // Embed in batches of 96
        $cohere     = new CohereClient();
        $embeddings = [];
        foreach (array_chunk($chunks, 96) as $batch) {
            $batchEmbeds = $cohere->embed($batch, 'search_document');
            $embeddings  = array_merge($embeddings, $batchEmbeds);
        }

        // Store
        $neon  = new NeonClient();
        $count = $neon->storeChunks($siteId, $chunks, $embeddings);

        $this->json(['success' => true, 'chunks_stored' => $count]);
    }

    private function handleChat(): void
    {
        $this->requireSecret();

        $body = $this->readJsonBody();

        $siteId  = trim($body['site_id'] ?? '');
        $message = trim($body['message'] ?? '');
        $history = $body['history'] ?? [];

        if (!$this->isValidUuid($siteId)) {
            $this->json(['success' => false, 'error' => 'site_id must be a valid UUID.'], 400);
            return;
        }

        if ($message === '') {
            $this->json(['success' => false, 'error' => 'message must not be empty.'], 400);
            return;
        }

        if (mb_strlen($message) > 500) {
            $this->json(['success' => false, 'error' => 'message must be 500 characters or fewer.'], 400);
            return;
        }

        if (!is_array($history)) {
            $history = [];
        }

        // Rate limit
        $neon = new NeonClient();

        if (!$neon->checkRateLimit($siteId)) {
            $this->json(['success' => false, 'error' => 'Rate limit exceeded. Try again tomorrow.'], 429);
            return;
        }

        $cohere = new CohereClient();

        // Embed query
        $queryEmbeddings = $cohere->embed([$message], 'search_query');
        $queryVector     = $queryEmbeddings[0];

        // Retrieve top-5 chunks
        $contextChunks = $neon->similaritySearch($siteId, $queryVector, 5);

        // Build system prompt
        $contextLines = [];
        foreach ($contextChunks as $idx => $chunk) {
            $contextLines[] = '[' . ($idx + 1) . '] ' . $chunk;
        }
        $contextBlock = implode("\n\n", $contextLines);

        $systemPrompt = <<<PROMPT
You are a helpful customer service assistant for this business. Answer the user's
question using ONLY the context provided below. If the answer is not in the context,
say "I don't have that information — please contact us directly."
Be concise, friendly, and professional. Do not make up information.

Context:
{$contextBlock}
PROMPT;

        // Keep last 10 turns of history
        $trimmedHistory = array_slice($history, -10);

        // Chat
        $answer = $cohere->chat($systemPrompt, $trimmedHistory, $message);

        // Increment rate limit after successful response
        $neon->incrementRateLimit($siteId);

        $this->json(['answer' => $answer, 'sources_used' => count($contextChunks)]);
    }

    private function handleStatus(): void
    {
        $this->requireSecret();

        $siteId = trim($_GET['site_id'] ?? '');

        if (!$this->isValidUuid($siteId)) {
            $this->json(['error' => 'site_id must be a valid UUID.'], 400);
            return;
        }

        $neon      = new NeonClient();
        $status    = $neon->getStatus($siteId);
        $remaining = $neon->getRateLimitRemaining($siteId);

        $this->json([
            'chunks'               => $status['chunks'],
            'last_updated'         => $status['last_updated'],
            'rate_limit_remaining' => $remaining,
        ]);
    }

    private function handleHealth(): void
    {
        $cohereOk = false;
        $neonOk   = false;

        try {
            $cohere   = new CohereClient();
            $result   = $cohere->embed(['health check'], 'search_query');
            $cohereOk = !empty($result);
        } catch (\Throwable $e) {
            error_log('[RAG Health] Cohere check failed: ' . $e->getMessage());
        }

        try {
            $neon   = new NeonClient();
            $neonOk = $neon->ping();
        } catch (\Throwable $e) {
            error_log('[RAG Health] Neon check failed: ' . $e->getMessage());
        }

        $this->json(['status' => 'ok', 'cohere' => $cohereOk, 'neon' => $neonOk]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a file is a PDF by reading its magic bytes (%PDF-).
     * Works without the fileinfo extension.
     */
    private function isPdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 5);
        fclose($handle);
        return $header === '%PDF-';
    }

    private function requireSecret(): void
    {
        $header = $_SERVER['HTTP_X_PLUGIN_SECRET'] ?? '';
        if (!hash_equals($this->secret, $header)) {
            error_log('[RAG Auth] 403 Forbidden — secret mismatch. Received: "' . substr($header, 0, 8) . '..." Expected first 8: "' . substr($this->secret, 0, 8) . '..."');
            $this->json(['error' => 'Forbidden.'], 403);
            exit;
        }
        error_log('[RAG Auth] Secret OK for ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
    }

    /** @return mixed[] */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function sendCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Plugin-Secret');
        header('Content-Type: application/json; charset=utf-8');
    }

    /** @param mixed[] $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
