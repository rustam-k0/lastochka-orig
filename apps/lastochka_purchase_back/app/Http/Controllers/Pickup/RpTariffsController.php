<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use Electrohub\DeliveryProviders\Exceptions\DeliveryProviderException;
use Electrohub\DeliveryProviders\Exceptions\IntegrationNotConfiguredException;
use Electrohub\DeliveryProviders\Services\RussianPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RpTariffsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'shopId' => ['required', 'integer', 'min:1'],
            'index'  => ['required', 'string', 'regex:/^\d{6}$/'],
            'weight' => ['nullable', 'integer', 'min:1'],
        ]);

        $shopId = (int) $request->input('shopId');

        try {
            $svc = RussianPostService::forShop($shopId);
        } catch (IntegrationNotConfiguredException $e) {
            return response()->json(['error' => 'integration_not_configured', 'message' => $e->getMessage()], 404);
        }

        $index  = (string) $request->input('index');
        $weight = (int) $request->input('weight', 300);

        try {
            $tariffs = $svc->getTariffsForIndex($index, $weight);
        } catch (DeliveryProviderException $e) {
            return response()->json(['error' => 'provider_error', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $tariffs]);
    }
}
