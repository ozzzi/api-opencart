<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Tools\Contracts\ToolInterface;

/**
 * Returns live product data (price, availability, attributes, URL) from OpenCart.
 *
 * Grounding rule (FR-4.3): the LLM MUST call this tool before making a final
 * recommendation for any specific product — the search index may contain
 * stale price or stock data.
 */
final class GetProductDetailsTool implements ToolInterface
{
    public function __construct(
        private readonly OpenCartCatalogInterface $catalog,
    ) {
    }

    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getDescription(): string
    {
        return 'Fetch live product details (current price, special price, stock availability, '
            .'attributes, and direct URL) from the store database. '
            .'You MUST call this tool before recommending any specific product to a customer — '
            .'the search index may contain outdated price or stock information. '
            .'Do not suggest out-of-stock products.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'product_id' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'The numeric product ID from the store catalog.',
                ],
                'lang' => [
                    'type'        => 'string',
                    'enum'        => ['ru', 'uk'],
                    'description' => 'Language for product name and attributes. Defaults to session language.',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $productId = (int) $arguments['product_id'];
        $lang = isset($arguments['lang']) ? (string) $arguments['lang'] : ($session->language ?? 'ru');

        $details = $this->catalog->getProductDetails($productId, $lang);

        if ($details === null) {
            return json_encode(
                ['found' => false, 'product_id' => $productId],
                JSON_UNESCAPED_UNICODE,
            );
        }

        return json_encode(
            array_merge(['found' => true], $details),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
