<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use Electrohub\DeliveryProviders\Models\CdekDeliveryPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CdekPvzController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'bbox'  => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'type'  => ['nullable', 'string', 'in:PVZ,POSTAMAT,ALL'],
            'q'     => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', explode(',', $request->input('bbox')));

        if ($minLng >= $maxLng || $minLat >= $maxLat) {
            return response()->json(['error' => 'invalid_bbox', 'message' => 'minLng must be < maxLng and minLat must be < maxLat'], 422);
        }

        $type  = $request->input('type', 'PVZ');
        $q     = $request->input('q');
        $limit = (int) $request->input('limit', 500);

        $query = CdekDeliveryPoint::query()
            ->where('is_active', true)
            ->where('is_handout', true)
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->limit($limit);

        if ($type !== 'ALL') {
            $query->where('type', $type);
        }

        if ($q !== null && $q !== '') {
            if (ctype_digit($q)) {
                $query->where('postal_code', 'like', $q . '%');
            } else {
                $query->where(function ($w) use ($q) {
                    $w->where('code', 'like', "%{$q}%")
                      ->orWhere('city', 'like', "%{$q}%")
                      ->orWhere('address', 'like', "%{$q}%")
                      ->orWhere('name', 'like', "%{$q}%");
                });
            }
        }

        $points = $query->get();

        return response()->json([
            'data' => $points->map(fn (CdekDeliveryPoint $p) => $this->formatPoint($p))->all(),
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $point = CdekDeliveryPoint::query()
            ->where('code', $code)
            ->first();

        if ($point === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->formatPoint($point)]);
    }

    private function formatPoint(CdekDeliveryPoint $p): array
    {
        return [
            'provider'    => 'cdek',
            'external_id' => $p->code,
            'code'        => $p->code,
            'title'       => $p->name,
            'address'     => $p->address,
            'city'        => $p->city,
            'postalCode'  => $p->postal_code,
            'lat'         => $p->latitude,
            'lng'         => $p->longitude,
            'workTime'    => $p->work_time,
            'phone'       => $p->phone,
            'type'        => $p->type,
        ];
    }
}
