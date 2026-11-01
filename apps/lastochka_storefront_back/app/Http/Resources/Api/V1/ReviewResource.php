<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StoreReview|\App\Models\ProductReview
 *
 * @property-read string|null $description
 * @property-read string|null $author_name
 */
final class ReviewResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     rating: int,
     *     description: string|null,
     *     authorName: string|null,
     *     isPublished: bool,
     *     createdAt: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'rating' => (int) $this->rating,
            'description' => $this->description === null ? null : (string) $this->description,
            'authorName' => $this->author_name === null ? null : (string) $this->author_name,
            'isPublished' => (bool) $this->is_published,
            /** @format date-time */
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
