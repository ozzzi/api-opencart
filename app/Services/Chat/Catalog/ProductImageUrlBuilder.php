<?php

declare(strict_types=1);

namespace App\Services\Chat\Catalog;

/**
 * Builds absolute product image URLs from the relative path OpenCart stores in
 * `product.image` (e.g. "catalog/demo/hp_1.jpg").
 *
 * Card images must come from the store's own domain and be produced by the
 * backend — a model-authored image URL is an injection and tracking vector
 * (task-structured-output.md §2.7).
 *
 * Strategy is configurable (`opencart.image.strategy`): 'original' always resolves,
 * 'cache' points at OpenCart's resized derivative, which only exists if the
 * storefront has already generated that size.
 */
final class ProductImageUrlBuilder
{
    /** Returns null when there is no usable image, so the widget draws a placeholder. */
    public function build(?string $relativePath): ?string
    {
        $path = mb_trim((string) $relativePath, " \t\n\r\0\x0B/");

        if ($path === '') {
            return null;
        }

        /** @var list<string> $ignored */
        $ignored = config('opencart.image.ignore', []);

        if (in_array(basename($path), $ignored, strict: true)) {
            return null;
        }

        $storeUrl = mb_rtrim((string) config('opencart.store_url'), '/');

        if (config('opencart.image.strategy') === 'cache') {
            return $storeUrl.'/image/cache/'.$this->encodePath($this->toCachePath($path));
        }

        return $storeUrl.'/image/'.$this->encodePath($path);
    }

    /**
     * Mirror OpenCart's resize naming: "catalog/demo/hp_1.jpg" with a 500x500 size
     * becomes "catalog/demo/hp_1-500x500.jpg". Extensionless paths are left alone.
     */
    private function toCachePath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $path;
        }

        $width = (int) config('opencart.image.width', 500);
        $height = (int) config('opencart.image.height', 500);

        $withoutExtension = mb_substr($path, 0, -(mb_strlen($extension) + 1));

        return "{$withoutExtension}-{$width}x{$height}.{$extension}";
    }

    /**
     * Encode each segment separately so slashes survive. OpenCart filenames
     * routinely contain spaces and Cyrillic, which would otherwise break the URL.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
