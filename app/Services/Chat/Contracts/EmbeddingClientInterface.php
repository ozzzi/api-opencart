<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

interface EmbeddingClientInterface
{
    /**
     * Generate embeddings for one or more texts.
     *
     * @param  list<string>          $texts
     * @return list<list<float>>     One vector per input text, in the same order.
     */
    public function embed(array $texts): array;

    public function getModel(): string;

    public function getDimensions(): int;
}
