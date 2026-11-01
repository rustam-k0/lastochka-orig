<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProductIssueReport */
final class ProductIssueReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'productId' => (int) $this->product_id,
            'email' => (string) $this->email,
            'type' => (string) $this->type,
            'message' => (string) $this->message,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
