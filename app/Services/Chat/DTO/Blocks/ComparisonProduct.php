<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/** A column header in a comparison block: identity only, no facts. */
final readonly class ComparisonProduct
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?string $image,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'image' => $this->image,
        ];
    }
}
