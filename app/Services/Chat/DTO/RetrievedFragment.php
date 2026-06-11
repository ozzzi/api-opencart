<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class RetrievedFragment
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $source,
        public string $id,
        public string $content,
        public float $score,
        public array $metadata = [],
    ) {
    }
}
