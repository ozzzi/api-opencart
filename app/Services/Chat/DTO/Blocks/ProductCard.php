<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO\Blocks;

/** One product card, built entirely from live catalog data. */
final readonly class ProductCard
{
    /** @param list<ProductAttribute> $attributes */
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?string $image,
        public ProductPrice $price,
        public bool $inStock,
        public string $availability,
        public array $attributes,
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
            'price' => $this->price->toArray(),
            'in_stock' => $this->inStock,
            'availability' => $this->availability,
            'attributes' => array_map(
                static fn (ProductAttribute $attribute): array => $attribute->toArray(),
                $this->attributes,
            ),
        ];
    }
}
