<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'rating' => $this->rating,
            'description' => $this->description,
            'author_name' => $this->author_name,
            'author_phone' => $this->author_phone,
            /** @var string|null @format date-time */
            'created_at' => $this->created_at?->toISOString(),
            /** @var string|null @format date-time */
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
