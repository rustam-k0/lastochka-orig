<?php

namespace App\GraphQL\Mutations\ProductReview;

use App\Models\ProductReview;

final class PublishProductReview
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): ProductReview
    {
        $review = ProductReview::findOrFail($args['id']);

        $review->update([
            'is_published' => $args['is_published'],
        ]);

        return $review->fresh(['shop', 'product']);
    }
}
