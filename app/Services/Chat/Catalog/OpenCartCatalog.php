<?php

declare(strict_types=1);

namespace App\Services\Chat\Catalog;

use App\Models\OpenCart\OcAttributeDescription;
use App\Models\OpenCart\OcCategoryDescription;
use App\Models\OpenCart\OcProduct;
use App\Models\OpenCart\OcProductAttribute;
use App\Models\OpenCart\OcProductDescription;
use App\Models\OpenCart\OcProductSpecial;
use App\Models\OpenCart\OcProductToCategory;
use App\Models\OpenCart\OcUrlAlias;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;

/**
 * Read-only facade over the OpenCart 2.3 database.
 *
 * Encapsulates language_id mapping, SEO URL resolution, and active special-price
 * resolution so callers work in terms of chat-system language codes ('ru','uk').
 *
 * Used by:
 *   - IndexProductJob (bulk indexing for all configured languages)
 *   - GetProductDetailsTool / CompareProductsTool (live data per language)
 */
final class OpenCartCatalog implements OpenCartCatalogInterface
{
    /**
     * Returns one index document per configured language.
     * Returns an empty array if the product does not exist.
     *
     * @return list<array{product_id:int,lang:string,name:string,description:string,attributes:string,category:string,price:float,in_stock:bool,url:string,image:string}>
     */
    public function getProductDocuments(int $productId): array
    {
        $product = OcProduct::find($productId);

        if ($product === null) {
            return [];
        }

        $inStock = $product->status === 1 && $product->quantity > 0;
        $price = $this->resolvePrice($product);
        $url = $this->resolveUrl($productId);
        $image = (string) ($product->image ?? '');

        /** @var array<string, int> $langMap */
        $langMap = config('opencart.language_map', []);
        $documents = [];

        foreach ($langMap as $langCode => $langId) {
            $desc = OcProductDescription::where('product_id', $productId)
                ->where('language_id', $langId)
                ->first();

            if ($desc === null) {
                continue;
            }

            $documents[] = [
                'product_id'  => $productId,
                'lang'        => $langCode,
                'name'        => $this->plainText($desc->name),
                'description' => $this->plainText($desc->description),
                'attributes'  => $this->buildAttributesText($productId, $langId),
                'category'    => $this->resolvePrimaryCategory($productId, $langId),
                'price'       => $price,
                'in_stock'    => $inStock,
                'url'         => $url,
                'image'       => $image,
            ];
        }

        return $documents;
    }

    /**
     * Returns live product details for the assistant tools.
     * Returns null when the product does not exist.
     *
     * @return array{product_id:int,name:string,description:string,price:float,special_price:float|null,in_stock:bool,quantity:int,categories:list<string>,attributes:list<array{name:string,value:string}>,url:string,image:string}|null
     */
    public function getProductDetails(int $productId, string $lang = 'ru'): ?array
    {
        $product = OcProduct::find($productId);

        if ($product === null) {
            return null;
        }

        $langId = $this->langId($lang);

        $desc = OcProductDescription::where('product_id', $productId)
            ->where('language_id', $langId)
            ->first();

        if ($desc === null) {
            return null;
        }

        $regularPrice = (float) $product->price;
        $special = $this->resolveSpecial($product);

        return [
            'product_id'    => $productId,
            'name'          => $this->plainText($desc->name),
            'description'   => $this->plainText($desc->description),
            'price'         => $regularPrice,
            'special_price' => $special,
            'in_stock'      => $product->status === 1 && $product->quantity > 0,
            'quantity'      => (int) $product->quantity,
            'categories'    => $this->resolveCategoryNames($productId, $langId),
            'attributes'    => $this->buildAttributesList($productId, $langId),
            'url'           => $this->resolveUrl($productId),
            'image'         => (string) ($product->image ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Reduce OpenCart rich-text to indexable / promptable plain text.
     *
     * OpenCart 2.3 stores markup HTML-encoded (`&lt;p&gt;`, `&quot;`), so a bare
     * strip_tags() leaves both the markup and entity noise in place — tokens like
     * "quot", "lt" and "nbsp" then pollute BM25 and the embedding text alike.
     * Tags are stripped before and after decoding to handle either encoding.
     */
    private function plainText(?string $value): string
    {
        $text = strip_tags((string) $value);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        return mb_trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    private function resolvePrice(OcProduct $product): float
    {
        return $this->resolveSpecial($product) ?? (float) $product->price;
    }

    private function resolveSpecial(OcProduct $product): ?float
    {
        $special = OcProductSpecial::where('product_id', $product->product_id)
            ->active()
            ->first();

        return $special ? (float) $special->price : null;
    }

    private function resolveUrl(int $productId): string
    {
        $keyword = OcUrlAlias::forProduct($productId)->value('keyword');
        $storeUrl = mb_rtrim((string) config('opencart.store_url'), '/');

        if ($keyword) {
            return $storeUrl.'/'.$keyword;
        }

        return $storeUrl.'/index.php?route=product/product&product_id='.$productId;
    }

    private function resolvePrimaryCategory(int $productId, int $langId): string
    {
        $categoryId = OcProductToCategory::where('product_id', $productId)
            ->value('category_id');

        if ($categoryId === null) {
            return '';
        }

        return $this->plainText(OcCategoryDescription::where('category_id', $categoryId)
            ->where('language_id', $langId)
            ->value('name'));
    }

    /** @return list<string> */
    private function resolveCategoryNames(int $productId, int $langId): array
    {
        $categoryIds = OcProductToCategory::where('product_id', $productId)
            ->pluck('category_id')
            ->all();

        if ($categoryIds === []) {
            return [];
        }

        return OcCategoryDescription::whereIn('category_id', $categoryIds)
            ->where('language_id', $langId)
            ->pluck('name')
            ->map($this->plainText(...))
            ->all();
    }

    private function buildAttributesText(int $productId, int $langId): string
    {
        $list = $this->buildAttributesList($productId, $langId);

        return implode(', ', array_map(
            fn (array $a): string => "{$a['name']}: {$a['value']}",
            $list,
        ));
    }

    /** @return list<array{name:string,value:string}> */
    private function buildAttributesList(int $productId, int $langId): array
    {
        $rows = OcProductAttribute::where('product_id', $productId)
            ->where('language_id', $langId)
            ->get();

        $result = [];

        foreach ($rows as $attr) {
            $name = OcAttributeDescription::where('attribute_id', $attr->attribute_id)
                ->where('language_id', $langId)
                ->value('name');

            if ($name !== null) {
                $result[] = [
                    'name'  => $this->plainText($name),
                    'value' => $this->plainText($attr->text),
                ];
            }
        }

        return $result;
    }

    private function langId(string $lang): int
    {
        /** @var array<string, int> $langMap */
        $langMap = config('opencart.language_map', []);

        return $langMap[$lang] ?? array_values($langMap)[0] ?? 1;
    }
}
