<?php

namespace App\Http\Middleware;

use App\Models\IntegrationConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResolveShopContext
{
    public function handle(Request $request, Closure $next)
    {
        $shopCode = $request->route('shop_code');

        if (!$shopCode) {
            abort(404, 'Shop code required');
        }

        $config = IntegrationConfig::where('integration_type', '1c')
            ->where('shop_code', $shopCode)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            Log::channel('1c-exchange')->warning('Shop config not found or inactive', [
                'shop_code' => $shopCode,
            ]);
            abort(404, 'Shop not found or inactive');
        }

        app()->instance('current_shop_id', $config->shop_id);
        app()->instance('current_shop_code', $shopCode);
        app()->instance('current_shop_config', $config);

        $shopPath = storage_path("1cExchange/{$shopCode}");
        if (!is_dir($shopPath)) {
            mkdir($shopPath, 0755, true);
        }
        config(['protocolExchange1C.inputPath' => $shopPath]);

        Log::channel('1c-exchange')->info('Shop context resolved', [
            'shop_code' => $shopCode,
            'shop_id' => $config->shop_id,
            'input_path' => $shopPath,
        ]);

        return $next($request);
    }
}
