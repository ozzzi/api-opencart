<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

final class TextChunker
{
    /**
     * Split text into overlapping word-based chunks, each prefixed with $prefix
     * so every chunk stays self-contained for embedding/retrieval.
     *
     * Each element: ['index' => int, 'text' => string]
     *
     * @return list<array{index:int,text:string}>
     */
    public function chunk(string $prefix, string $body, int $chunkSize, int $overlap): array
    {
        $words = preg_split('/\s+/u', mb_trim($body), flags: PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return [['index' => 0, 'text' => mb_trim($prefix)]];
        }

        $step = max(1, $chunkSize - $overlap);
        $total = count($words);
        $chunks = [];
        $chunkIndex = 0;

        for ($start = 0; $start < $total; $start += $step) {
            $slice = array_slice($words, $start, $chunkSize);
            $text = $prefix.' '.implode(' ', $slice);

            $chunks[] = ['index' => $chunkIndex, 'text' => mb_trim($text)];
            $chunkIndex++;

            // If the remaining words fit entirely in this chunk, we're done.
            if ($start + $chunkSize >= $total) {
                break;
            }
        }

        return $chunks;
    }
}
