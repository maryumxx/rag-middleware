<?php

declare(strict_types=1);

namespace RAG;

class NeonClient
{
    private \PDO $pdo;

    public function __construct()
    {
        $dsn = $_ENV['NEON_CONNECTION_STRING'] ?? getenv('NEON_CONNECTION_STRING');
        if (empty($dsn)) {
            throw new \RuntimeException('NEON_CONNECTION_STRING environment variable is not set.');
        }

        // Parse postgresql:// DSN into PDO pgsql: DSN
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            throw new \RuntimeException('Invalid NEON_CONNECTION_STRING format.');
        }

        $host     = $parsed['host'] ?? '';
        $port     = $parsed['port'] ?? 5432;
        $dbname   = ltrim($parsed['path'] ?? '/neondb', '/');
        $user     = rawurldecode($parsed['user'] ?? '');
        $password = rawurldecode($parsed['pass'] ?? '');
        $query    = $parsed['query'] ?? '';

        $sslmode = 'require';
        if (preg_match('/sslmode=([a-z-]+)/', $query, $m)) {
            $sslmode = $m[1];
        }

        $pdoDsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

        $this->pdo = new \PDO($pdoDsn, $user, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 10,
        ]);
    }

    /**
     * Delete existing chunks for a site, then bulk-insert new ones.
     *
     * @param  string     $siteId
     * @param  string[]   $chunks     Chunk texts
     * @param  float[][]  $embeddings Corresponding embedding vectors
     * @return int Number of rows stored
     */
    public function storeChunks(string $siteId, array $chunks, array $embeddings): int
    {
        $this->deleteChunks($siteId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rag_chunks (site_id, chunk_index, chunk_text, embedding)
             VALUES (:site_id, :chunk_index, :chunk_text, :embedding)'
        );

        $count = 0;
        foreach ($chunks as $i => $text) {
            $vector = $this->formatVector($embeddings[$i]);
            $stmt->execute([
                ':site_id'     => $siteId,
                ':chunk_index' => $i,
                ':chunk_text'  => $text,
                ':embedding'   => $vector,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Run a pgvector cosine-similarity search and return top-k chunk texts.
     *
     * @param  string  $siteId
     * @param  float[] $queryVector
     * @param  int     $k
     * @return string[]
     */
    public function similaritySearch(string $siteId, array $queryVector, int $k = 5): array
    {
        $vector = $this->formatVector($queryVector);

        $stmt = $this->pdo->prepare(
            'SELECT chunk_text
               FROM rag_chunks
              WHERE site_id = :site_id
           ORDER BY embedding <=> :embedding
              LIMIT :k'
        );

        $stmt->bindValue(':site_id',   $siteId,  \PDO::PARAM_STR);
        $stmt->bindValue(':embedding', $vector,  \PDO::PARAM_STR);
        $stmt->bindValue(':k',         $k,       \PDO::PARAM_INT);
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'chunk_text');
    }

    /**
     * Delete all chunks for a given site_id.
     */
    public function deleteChunks(string $siteId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rag_chunks WHERE site_id = :site_id');
        $stmt->execute([':site_id' => $siteId]);
    }

    /**
     * Return status info for a site_id.
     *
     * @return array{chunks: int, last_updated: string|null}
     */
    public function getStatus(string $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS chunks, MAX(created_at) AS last_updated
               FROM rag_chunks
              WHERE site_id = :site_id'
        );
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch();

        return [
            'chunks'       => (int) ($row['chunks'] ?? 0),
            'last_updated' => $row['last_updated'] ?? null,
        ];
    }

    /**
     * Check whether this site is within the daily rate limit.
     *
     * @param  string $siteId
     * @param  int    $max    Maximum requests per day
     * @return bool   true if the request is allowed
     */
    public function checkRateLimit(string $siteId, int $max = 200): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT count FROM rate_limits WHERE site_id = :site_id AND date = CURRENT_DATE'
        );
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch();

        return ($row === false || (int) $row['count'] < $max);
    }

    /**
     * Increment (or create) today's rate-limit counter for this site.
     */
    public function incrementRateLimit(string $siteId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (site_id, date, count)
                  VALUES (:site_id, CURRENT_DATE, 1)
             ON CONFLICT (site_id, date)
             DO UPDATE SET count = rate_limits.count + 1'
        );
        $stmt->execute([':site_id' => $siteId]);
    }

    /**
     * Return how many requests remain today for a site.
     */
    public function getRateLimitRemaining(string $siteId, int $max = 200): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count FROM rate_limits WHERE site_id = :site_id AND date = CURRENT_DATE'
        );
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch();

        $used = ($row !== false) ? (int) $row['count'] : 0;
        return max(0, $max - $used);
    }

    /**
     * Quick connectivity check (SELECT 1).
     */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a PHP float array into the pgvector literal format: '[0.1,0.2,...]'
     *
     * @param  float[] $vector
     */
    private function formatVector(array $vector): string
    {
        return '[' . implode(',', array_map(
            static fn(float $v): string => number_format($v, 8, '.', ''),
            $vector
        )) . ']';
    }
}
