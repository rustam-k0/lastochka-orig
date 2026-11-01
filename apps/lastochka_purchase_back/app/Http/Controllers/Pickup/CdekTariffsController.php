<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use Electrohub\DeliveryProviders\Exceptions\DeliveryProviderException;
use Electrohub\DeliveryProviders\Exceptions\IntegrationNotConfiguredException;
use Electrohub\DeliveryProviders\Services\SdekDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CdekTariffsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'shopId'        => ['required', 'integer', 'min:1'],
            'code'          => ['nullable', 'string', 'max:100'],
            'city'          => ['nullable', 'string', 'max:255'],
            'weight'        => ['nullable', 'integer', 'min:1'],
            'dimensions'    => ['nullable', 'string', 'regex:/^\d+x\d+x\d+$/i'],
            'declaredValue' => ['nullable', 'numeric', 'min:0'],
        ]);

        $shopId = (int) $request->input('shopId');

        try {
            $svc = SdekDeliveryService::forShop($shopId);
        } catch (IntegrationNotConfiguredException $e) {
            return response()->json(['error' => 'integration_not_configured', 'message' => $e->getMessage()], 404);
        }

        // Resolve city code
        $toCityCode = null;
        $code       = $request->input('code');
        $city       = $request->input('city');

        try {
            if ($code !== null && $code !== '') {
                if (ctype_digit($code)) {
                    $toCityCode = (int) $code;
                } else {
                    // code is a PVZ code like "MKHCH19" — get city code from it
                    $points = $svc->getDeliveryPoints(code: $code);
                    $toCityCode = $points[0]['location']['city_code'] ?? null;
                }
            } elseif ($city !== null && $city !== '') {
                $suggestions = $svc->getSuggestions($city, 'ru');
                foreach ($suggestions as $s) {
                    if (
                        isset($s['country_code'], $s['name'], $s['code'])
                        && $s['country_code'] === 'RU'
                        && mb_strtolower($s['name']) === mb_strtolower($city)
                    ) {
                        $toCityCode = $s['code'];
                        break;
                    }
                }
            }
        } catch (DeliveryProviderException $e) {
            return response()->json(['error' => 'provider_error', 'message' => $e->getMessage()], 502);
        }

        if ($toCityCode === null) {
            return response()->json([
                'error'   => 'city_not_resolved',
                'message' => 'Не удалось определить код города получателя. Передайте параметр code (числовой код СДЭК или код ПВЗ) или city (точное название города).',
            ], 400);
        }

        // Parse dimensions
        $dimensionsRaw = $request->input('dimensions', '20x15x10');
        $dims          = array_map('intval', explode('x', strtolower($dimensionsRaw)));
        $length        = $dims[0] ?? 20;
        $width         = $dims[1] ?? 15;
        $height        = $dims[2] ?? 10;

        $weight  = (int) $request->input('weight', 300);
        $package = [
            'weight' => $weight,
            'length' => $length,
            'width'  => $width,
            'height' => $height,
        ];

        // Resolve sender city code from credentials
        /** @var \App\Models\IntegrationConfig $cfg */
        $cfg = \App\Models\IntegrationConfig::where([
            'shop_id'          => $shopId,
            'integration_type' => 'delivery_cdek',
            'is_active'        => true,
        ])->first();

        $credentials     = $cfg ? ($cfg->credentials ?? []) : [];
        $senderCityCode  = (int) ($credentials['cdek']['sender_city_code'] ?? 0);
        $defaultTariff   = (int) ($credentials['cdek']['default_tariff_code'] ?? 138);
        $warehouseOnly   = (bool) ($credentials['cdek']['warehouse_only'] ?? true);

        try {
            $resp = $svc->calculateTariffList(
                from: ['code' => $senderCityCode],
                to: ['code'   => $toCityCode],
                packages: [$package],
            );
        } catch (DeliveryProviderException $e) {
            return response()->json(['error' => 'provider_error', 'message' => $e->getMessage()], 502);
        }

        $tariffs = collect($resp['tariff_codes'] ?? [])->map(fn (array $t) => [
            'code'              => $t['tariff_code'],
            'name'              => $t['tariff_name'],
            'price'             => $t['delivery_sum'],
            'currency'          => 'RUB',
            'deliveryDays'      => [
                'minDays' => $t['period_min'] ?? null,
                'maxDays' => $t['period_max'] ?? null,
            ],
            'isAvailable'       => true,
            'isDefault'         => $t['tariff_code'] === $defaultTariff,
            'unavailableReason' => null,
        ]);

        if ($warehouseOnly) {
            $filtered = $tariffs->filter(fn (array $t) =>
                str_ends_with(mb_strtolower($t['name']), 'склад-склад')
                && !str_contains(mb_strtolower($t['name']), 'постамат')
            );
            if ($filtered->isNotEmpty()) {
                $tariffs = $filtered->values();
            }
        }

        return response()->json(['data' => $tariffs->all()]);
    }
}
