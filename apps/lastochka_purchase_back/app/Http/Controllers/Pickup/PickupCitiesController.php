<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use Electrohub\DeliveryProviders\Models\DeliveryCity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PickupCitiesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q'     => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $q     = $request->input('q', '');
        $limit = (int) $request->input('limit', 50);

        $query = DeliveryCity::query()
            ->where('active', true)
            ->orderBy('name')
            ->limit($limit);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhere('slug', 'like', "%{$q}%")
                  ->orWhere('zip', 'like', "%{$q}%");
            });
        }

        $cities = $query->get();

        return response()->json([
            'data' => $cities->map(fn (DeliveryCity $c) => [
                'name'   => $c->name,
                'slug'   => $c->slug,
                'region' => $c->region,
                'zip'    => $c->zip,
                'lat'    => $c->lat,
                'lng'    => $c->lng,
            ])->all(),
        ]);
    }
}
