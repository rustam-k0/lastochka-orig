<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'description' => $this->description,
            'itemsCount' => (int) ($this->items_count ?? $this->items->count()),
            'totalAmount' => (int) $this->whenLoaded('items', function () {
                // Цены от количества (MID-308): цена позиции сметы зависит от её
                // количества. Без лесенки и при выключенном модуле котировка равна
                // getEffectivePrice(), сумма считается как раньше.
                $resolver = app(ProductPriceResolver::class);

                return $this->items->sum(function ($item) use ($resolver) {
                    if (! $item->product) {
                        return 0;
                    }

                    return $resolver->lineTotalForQuantity($item->product, (int) $item->quantity);
                });
            }, 0),
            'items' => EstimateItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
