<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

final class Hit
{
    /**
     * @param string $id
     * @param array<int, array<string, mixed>> $document
     * @param float|null $score
     * @param string|null $highlight
     */
    public function __construct(
        public readonly string $id,
        public readonly array $document,
        public readonly ?float $score = null,
        public readonly ?string $highlight = null
    ) {
    }
}
