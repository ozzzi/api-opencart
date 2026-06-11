<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

interface OpenCartCatalogInterface
{
    /**
     * Returns one index document per configured language.
     * Returns an empty array if the product does not exist.
     *
     * @return list<array{product_id:int,lang:string,name:string,description:string,attributes:string,category:string,price:float,in_stock:bool,url:string,image:string}>
     */
    public function getProductDocuments(int $productId): array;

    /**
     * Returns live product details for the assistant tools.
     * Returns null when the product does not exist.
     *
     * @return array{product_id:int,name:string,description:string,price:float,special_price:float|null,in_stock:bool,quantity:int,categories:list<string>,attributes:list<array{name:string,value:string}>,url:string,image:string}|null
     */
    public function getProductDetails(int $productId, string $lang = 'ru'): ?array;
}
