<?php

declare(strict_types=1);

namespace App\Services\Chat\Search;

final class QueryTerms
{
    /**
     * Lower-cased, split on non-letters, dropping tokens shorter than 3
     * characters and the configured stop words.
     *
     * @return list<string> Sorted and de-duplicated, so it can be compared
     *                      against the stored terms of the previous query.
     */
    public static function significant(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        /** @var list<string> $stopWords */
        $stopWords = (array) config('bot.clarification.stop_words', []);

        $terms = array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) >= 3
                && ! in_array($token, $stopWords, strict: true),
        );

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }
}
