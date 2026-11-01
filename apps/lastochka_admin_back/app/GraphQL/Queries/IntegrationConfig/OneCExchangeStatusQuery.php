<?php

namespace App\GraphQL\Queries\IntegrationConfig;

use App\Models\OneCExchangeStatus;
use App\Models\Shop;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Auth;

class OneCExchangeStatusQuery
{
    public function __invoke($_, array $args): array
    {
        $shopId = $args['shop_id'];

        $this->authorizeShopAccess($shopId);

        $shop = Shop::findOrFail($shopId);

        $statuses = OneCExchangeStatus::where('shop_id', $shopId)->get();

        $exchangeUrl = rtrim(config('services.1c_parser.url', ''), '/') . '/1c-exchange/' . $shop->code;

        $result = [
            'exchange_url' => $exchangeUrl,
            'data_types' => [],
        ];

        $dataTypes = [
            'catalog_import',
            'offers_import',
            'rests_import',
            'orders_import',
            'orders_export',
        ];

        foreach ($dataTypes as $type) {
            $status = $statuses->firstWhere('data_type', $type);

            $result['data_types'][] = [
                'data_type' => $type,
                'last_exchange_at' => $status?->last_exchange_at?->toIso8601String(),
                'success' => $status?->success ?? null,
                'error' => $status?->error,
                'records_count' => $status?->records_count ?? 0,
                'duration_seconds' => $status?->duration_seconds,
                'counters' => $status?->counters ? json_encode($status->counters) : null,
                'warnings' => $status?->warnings ? json_encode($status->warnings) : null,
                'status_label' => $status?->status_label ?? 'no_data',
                'stale_threshold_hours' => OneCExchangeStatus::STALE_THRESHOLDS[$type] ?? 2,
            ];
        }

        return $result;
    }

    private function authorizeShopAccess(int $shopId): void
    {
        $user = Auth::user();

        if ($user->isGlobalScope()) {
            return;
        }

        if (!$user->shops()->where('shop_id', $shopId)->exists()) {
            throw new Error('Нет доступа к этому магазину');
        }
    }
}
