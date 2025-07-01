<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

interface StopWord
{
    /**
     * @param List<non-empty-string> $stopWords
     * @param array<string, string> $options
     * @return void
     */
    public function addStopWords(array $stopWords, array $options): void;
}
