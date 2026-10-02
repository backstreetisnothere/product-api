<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Single source of truth for cache keys and tags.
 *
 * PSR-6 forbids the characters {}()/\@: in keys and tags, so "." is used as separator.
 *
 * Invalidation matrix (what a write must invalidate):
 *
 *   product created        -> merchantProducts
 *   product updated        -> product, merchantProducts
 *   product deleted        -> product, stock, merchantProducts
 *   price changed          -> product, merchantProducts   (price is embedded into product views)
 *   stock changed          -> stock
 *   warehouse created      -> merchantWarehouses
 *   warehouse updated/del. -> warehouse, merchantWarehouses
 *   merchant deactivated   -> merchant
 */
final class CacheTags
{
    private function __construct()
    {
    }

    public static function merchant(string $merchantId): string
    {
        return 'merchant.'.$merchantId;
    }

    public static function merchantProducts(string $merchantId): string
    {
        return 'merchant.'.$merchantId.'.products';
    }

    public static function merchantWarehouses(string $merchantId): string
    {
        return 'merchant.'.$merchantId.'.warehouses';
    }

    public static function product(string $productId): string
    {
        return 'product.'.$productId;
    }

    public static function stock(string $productId): string
    {
        return 'stock.'.$productId;
    }

    public static function warehouse(string $warehouseId): string
    {
        return 'warehouse.'.$warehouseId;
    }

    public static function productKey(string $merchantId, string $productId): string
    {
        return \sprintf('product.%s.%s', $merchantId, $productId);
    }

    public static function productListKey(string $merchantId, string $queryHash): string
    {
        return \sprintf('products.%s.%s', $merchantId, $queryHash);
    }

    public static function stockKey(string $merchantId, string $productId): string
    {
        return \sprintf('stock.%s.%s', $merchantId, $productId);
    }

    public static function warehouseKey(string $merchantId, string $warehouseId): string
    {
        return \sprintf('warehouse.%s.%s', $merchantId, $warehouseId);
    }

    public static function warehouseListKey(string $merchantId): string
    {
        return \sprintf('warehouses.%s', $merchantId);
    }

    public static function merchantIdentityByPrefixKey(string $prefix): string
    {
        return 'identity.prefix.'.$prefix;
    }

    public static function merchantIdentityByIdKey(string $merchantId): string
    {
        return 'identity.id.'.$merchantId;
    }
}
