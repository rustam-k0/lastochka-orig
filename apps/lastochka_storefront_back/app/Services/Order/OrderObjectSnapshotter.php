<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Address;
use Illuminate\Support\Facades\Storage;

final class OrderObjectSnapshotter
{
    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(Address $address): ?array
    {
        if (! $address->is_object || $address->object_data === null) {
            return null;
        }

        $payload = $address->object_data->toArray();

        $payload['photos'] = $address->photos()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'url' => Storage::disk($p->disk)->url($p->path),
                'mime' => $p->mime,
            ])
            ->values()
            ->all();

        return $payload;
    }
}
