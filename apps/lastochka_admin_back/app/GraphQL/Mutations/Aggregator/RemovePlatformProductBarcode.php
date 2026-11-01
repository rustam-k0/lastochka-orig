<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Models\PlatformProductBarcode;

/**
 * Удалить штрихкод платформенной карточки (§8.3). Если удалили primary —
 * назначаем primary первому из оставшихся.
 */
final class RemovePlatformProductBarcode
{
    public function __invoke($_, array $args): array
    {
        $barcode = PlatformProductBarcode::findOrFail($args['barcodeId']);
        $platformId = $barcode->platform_product_id;
        $wasPrimary = $barcode->is_primary;

        $barcode->delete();

        if ($wasPrimary) {
            $next = PlatformProductBarcode::where('platform_product_id', $platformId)
                ->orderBy('id')
                ->first();
            $next?->update(['is_primary' => true]);
        }

        return ['code' => 200, 'message' => 'OK'];
    }
}
