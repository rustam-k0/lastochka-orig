<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;

/**
 * Добавить штрихкод к платформенной карточке (§8.3, блок «Штрихкоды»).
 * Проверяет, что EAN не занят другой карточкой (инвариант UNIQUE ean).
 */
final class AddPlatformProductBarcode
{
    public function __invoke($_, array $args): PlatformProduct
    {
        $platform = PlatformProduct::findOrFail($args['platformProductId']);
        $ean = preg_replace('/\D/', '', (string) $args['ean']);

        if ($ean === '') {
            throw new \GraphQL\Error\Error('Штрихкод должен содержать цифры');
        }

        $owner = PlatformProductBarcode::where('ean', $ean)->first();
        if ($owner) {
            if ($owner->platform_product_id === $platform->id) {
                return $platform->fresh(['barcodes', 'offers']);
            }
            throw new \GraphQL\Error\Error(
                "Штрихкод {$ean} уже принадлежит карточке #{$owner->platform_product_id}"
            );
        }

        PlatformProductBarcode::create([
            'platform_product_id' => $platform->id,
            'ean' => $ean,
            'is_primary' => $platform->barcodes()->count() === 0,
        ]);

        $platform->touch();

        return $platform->fresh(['barcodes', 'offers']);
    }
}
