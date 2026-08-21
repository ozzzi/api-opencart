<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\OpenCartCatalogInterface;
use App\Services\Chat\Presentation\BlockCollector;
use App\Services\Chat\Presentation\ProductCardMapper;
use App\Services\Chat\Tools\Contracts\ToolInterface;

/**
 * Renders product cards for the customer.
 *
 * Separate from search_products on purpose: search returns candidates for the model
 * to reason over, while this tool is the curation step — the model picks which of
 * them are worth showing, and only here do we fetch live data and emit a card
 * (task-structured-output.md §2.3).
 */
final class ShowProductsTool implements ToolInterface
{
    private const int MAX_PRODUCTS = 4;

    public function __construct(
        private readonly OpenCartCatalogInterface $catalog,
        private readonly ProductCardMapper $mapper,
        private readonly BlockCollector $blocks,
    ) {
    }

    public function getName(): string
    {
        return 'show_products';
    }

    public function getDescription(): string
    {
        return 'Display product cards to the customer. Call this once you have decided which 1–4 '
            .'products are worth showing. The store renders each card with live price, availability, '
            .'image and link, so do NOT repeat price, characteristics, image or URL in your reply — '
            .'write only why each product suits the customer. Products that are out of stock are '
            .'removed automatically.';
    }

    /** @return array<string, mixed> */
    public function getParameterSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'product_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer', 'minimum' => 1],
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_PRODUCTS,
                    'description' => 'IDs of the 1–4 products to show, in the order they should appear.',
                ],
            ],
            'required' => ['product_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string
    {
        $productIds = array_slice(
            array_map('intval', (array) $arguments['product_ids']),
            0,
            self::MAX_PRODUCTS,
        );

        $lang = (string) config('opencart.card_language', 'uk');

        $shown = [];
        $notFound = [];
        $outOfStock = [];

        foreach ($productIds as $productId) {
            $details = $this->catalog->getProductDetails($productId, $lang);

            if ($details === null) {
                $notFound[] = $productId;

                continue;
            }

            // Never advertise what cannot be bought (FR-4.4).
            if ($details['in_stock'] === false) {
                $outOfStock[] = $productId;

                continue;
            }

            $shown[] = $details;
        }

        if ($shown === []) {
            return json_encode([
                'shown' => false,
                'reason' => 'no_available_products',
                'out_of_stock_ids' => $outOfStock,
                'not_found_ids' => $notFound,
            ], JSON_UNESCAPED_UNICODE);
        }

        $this->blocks->push($this->mapper->toProductsBlock($shown));

        // Deliberately compact: ids and names only. The model needs enough to refer
        // to a product in prose, and nothing it could paraphrase into a stale fact.
        return json_encode([
            'shown' => true,
            'items' => array_map(static fn (array $p): array => [
                'product_id' => $p['product_id'],
                'name' => $p['name'],
            ], $shown),
            'out_of_stock_ids' => $outOfStock,
            'not_found_ids' => $notFound,
            'note' => 'Cards with price, availability, image and link are already displayed to the '
                .'customer. Refer to these products by name only; do not restate their facts.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
