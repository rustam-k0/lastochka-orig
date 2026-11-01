<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;

/**
 * Сделать штрихкод основным (§8.3). Инвариант «ровно один primary» — в модели.
 */
final class SetPrimaryPlatformProductBarcode
{
    public function __invoke($_, array $args): PlatformProduct
    {
        $barcode = PlatformProductBarcode::findOrFail($args['barcodeId']);
        $platform = PlatformProduct::findOrFail($barcode->platform_product_id);

        $platform->setPrimaryBarcode((int) $barcode->id);

        return $platform->fresh(['barcodes', 'offers']);
    }
}
