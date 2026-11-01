<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Preorder */
final class PreorderResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     productId: int,
     *     storeId: int,
     *     productTitle?: string|null,
     *     productImage?: string|null,
     *     fullName: string,
     *     phone: string,
     *     email: string|null,
     *     quantity: int,
     *     comment: string|null,
     *     status: string,
     *     createdAt: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'productId' => (int) $this->product_id,
            'storeId' => (int) $this->store_id,
            'productTitle' => $this->whenLoaded('product', fn (): ?string => $this->product->title),
            'productImage' => $this->whenLoaded('product', function (): ?string {
                $image = $this->product->images->first();

                return $image ? (string) $image->path : null;
            }),
            'fullName' => (string) $this->full_name,
            'phone' => (string) $this->phone,
            'email' => $this->email,
            'quantity' => (int) $this->quantity,
            'comment' => $this->comment,
            'status' => (string) $this->status->value,
            /** @format date-time */
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
