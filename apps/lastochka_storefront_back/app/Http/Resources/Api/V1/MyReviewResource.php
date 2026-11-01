<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OrderReview;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductReview|\App\Models\OrderReview
 */
final class MyReviewResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     targetId: int,
     *     targetTitle: string|null,
     *     targetImageUrl: string|null,
     *     rating: int,
     *     description: string|null,
     *     createdAt: string,
     *     updatedAt: string,
     *     canEdit: bool,
     *     canDelete: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $review = $this->resource;

        if ($review instanceof ProductReview) {
            $type = 'product';
            $targetId = (int) $review->product_id;
            $targetTitle = $review->product?->title;
            $imagePath = $review->product?->images?->first()?->path;
            $targetImageUrl = $imagePath !== null ? ImageResource::toFullUrl($imagePath) : null;
        } elseif ($review instanceof OrderReview) {
            $type = 'order';
            $targetId = (int) $review->order_id;
            $targetTitle = 'Заказ №' . $targetId;
            $targetImageUrl = null;
        } else {
            $type = 'unknown';
            $targetId = 0;
            $targetTitle = null;
            $targetImageUrl = null;
        }

        return [
            'id' => (int) $review->id,
            /** @var 'product'|'order' */
            'type' => $type,
            /** @var int */
            'targetId' => $targetId,
            'targetTitle' => $targetTitle === null ? null : (string) $targetTitle,
            /** @var string|null */
            'targetImageUrl' => $targetImageUrl,
            'rating' => (int) $review->rating,
            'description' => $review->description === null ? null : (string) $review->description,
            /** @format date-time */
            'createdAt' => $review->created_at->toIso8601String(),
            /** @format date-time */
            'updatedAt' => $review->updated_at->toIso8601String(),
            'canEdit' => true,
            'canDelete' => false,
        ];
    }
}
