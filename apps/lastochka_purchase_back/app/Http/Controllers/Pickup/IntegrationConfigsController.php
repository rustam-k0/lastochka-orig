<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationConfigsController extends Controller
{
    public function show(Request $request, int $shopId): JsonResponse
    {
        $request->validate([
            'types'   => ['nullable', 'array'],
            'types.*' => ['string', 'max:100'],
        ]);

        $types = $request->input('types', []);

        $query = IntegrationConfig::query()
            ->where('shop_id', $shopId);

        if (!empty($types)) {
            $query->whereIn('integration_type', $types);
        }

        $configs = $query->get(['integration_type', 'is_active']);

        return response()->json([
            'data' => $configs->map(fn (IntegrationConfig $c) => [
                'integration_type' => $c->integration_type,
                'is_active'        => (bool) $c->is_active,
            ])->all(),
        ]);
    }
}
