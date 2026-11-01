<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\SupplementValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Supplement
 */
final class SupplementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'type' => (string) $this->type,
            'isRequired' => (bool) $this->is_required,
            'values' => $this->when(
                $this->resource->relationLoaded('values'),
                fn () => SupplementValueResource::collection(
                    $this->values->filter(function (SupplementValue $v): bool {
                        if (! $v->isFromCatalog() || ! $v->tracksStock()) {
                            return true;
                        }
                        $product = $v->product;

                        // Остаток берётся из оффера магазина контекста
                        // (сетевой каталог), иначе из колонки карточки.
                        return $product && $product->stockQuantity() >= $product->minSaleStock();
                    })->values()
                ),
                []
            ),
        ];
    }
}
