<?php

declare(strict_types=1);

namespace RAG;

class Chunker
{
    /**
     * Split text into overlapping chunks on sentence boundaries.
     *
     * @param string $text    Raw input text
     * @param int    $words   Target words per chunk
     * @param int    $overlap Overlap words between consecutive chunks
     * @return string[]
     */
    public function chunk(string $text, int $words = 400, int $overlap = 50): array
    {
        // Normalise whitespace
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        // Split into sentences. A sentence ends with . ! ? followed by whitespace or EOL.
        $sentences = preg_split(
            '/(?<=[.!?])\s+/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($sentences === false || count($sentences) === 0) {
            return [$text];
        }

        $chunks       = [];
        $currentWords = [];
        $currentCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = preg_split('/\s+/', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
            if ($sentenceWords === false) {
                continue;
            }
            $sentenceCount = count($sentenceWords);

            // If adding this sentence would exceed the target, flush first.
            if ($currentCount > 0 && ($currentCount + $sentenceCount) > $words) {
                $chunks[] = implode(' ', $currentWords);

                // Seed next chunk with overlap words from the END of the current chunk.
                $overlapWords = array_slice($currentWords, -$overlap);
                $currentWords = $overlapWords;
                $currentCount = count($overlapWords);
            }

            $currentWords = array_merge($currentWords, $sentenceWords);
            $currentCount += $sentenceCount;
        }

        // Flush remaining words.
        if ($currentCount > 0) {
            $chunks[] = implode(' ', $currentWords);
        }

        return $chunks;
    }
}
