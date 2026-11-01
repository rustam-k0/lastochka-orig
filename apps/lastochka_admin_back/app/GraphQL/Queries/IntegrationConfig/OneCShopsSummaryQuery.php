<?php

namespace App\GraphQL\Queries\IntegrationConfig;

use App\Models\IntegrationConfig;
use App\Models\OneCExchangeStatus;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;

class OneCShopsSummaryQuery
{
    public function __invoke($_, array $args): array
    {
        $user = Auth::user();
        if ($user->isGlobalScope()) {
            $shops = Shop::orderBy('title')->get();
        } else {
            $shopIds = $user->shops()->pluck('shop_id');
            $shops = Shop::whereIn('id', $shopIds)->orderBy('title')->get();
        }

        $configs = IntegrationConfig::where('integration_type', '1c')
            ->whereIn('shop_id', $shops->pluck('id'))
            ->get()->keyBy('shop_id');

        $statuses = OneCExchangeStatus::whereIn('shop_id', $shops->pluck('id'))
            ->get()->groupBy('shop_id');

        return $shops->map(function (Shop $shop) use ($configs, $statuses) {
            $config = $configs->get($shop->id);
            $shopStatuses = $statuses->get($shop->id, collect());
            return [
                'shop' => $shop,
                'has_config' => $config !== null,
                'is_active' => $config?->is_active ?? false,
                'last_exchange_at' => $shopStatuses->max('last_exchange_at')?->toIso8601String(),
                'has_errors' => $shopStatuses->contains(fn($s) => !$s->success),
            ];
        })->toArray();
    }
}
