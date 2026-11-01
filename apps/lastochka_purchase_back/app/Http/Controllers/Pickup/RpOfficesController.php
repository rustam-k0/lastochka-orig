<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pickup;

use App\Http\Controllers\Controller;
use Electrohub\DeliveryProviders\Models\RpOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RpOfficesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'bbox'    => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'q'       => ['nullable', 'string', 'max:255'],
            'limit'   => ['nullable', 'integer', 'min:1', 'max:1000'],
            'opsType' => ['nullable', 'string', 'max:100'],
        ]);

        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', explode(',', $request->input('bbox')));

        if ($minLng >= $maxLng || $minLat >= $maxLat) {
            return response()->json(['error' => 'invalid_bbox', 'message' => 'minLng must be < maxLng and minLat must be < maxLat'], 422);
        }

        $opsType = $request->input('opsType', 'ГОПС');
        $q       = $request->input('q');
        $limit   = (int) $request->input('limit', 500);

        $query = RpOffice::query()
            ->where('is_active', true)
            ->where('ops_type', $opsType)
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->limit($limit);

        if ($q !== null && $q !== '') {
            if (strlen($q) === 6 && ctype_digit($q)) {
                $query->where('postal_code', $q);
            } else {
                $query->where(function ($w) use ($q) {
                    $w->where('settlement', 'like', "%{$q}%")
                      ->orWhere('address', 'like', "%{$q}%")
                      ->orWhere('name', 'like', "%{$q}%");
                });
            }
        }

        $offices = $query->get();

        return response()->json([
            'data' => $offices->map(fn (RpOffice $o) => $this->formatOffice($o))->all(),
        ]);
    }

    public function show(string $index): JsonResponse
    {
        $office = RpOffice::query()
            ->where('postal_code', $index)
            ->first();

        if ($office === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->formatOffice($office)]);
    }

    private function formatOffice(RpOffice $o): array
    {
        return [
            'provider'    => 'russian_post',
            'external_id' => $o->postal_code,
            'title'       => $o->name,
            'address'     => $o->address,
            'city'        => $o->settlement,
            'postalCode'  => $o->postal_code,
            'lat'         => $o->latitude,
            'lng'         => $o->longitude,
            'workTime'    => $o->work_time,
            'phone'       => $o->phone,
            'ops_type'    => $o->ops_type,
        ];
    }
}
