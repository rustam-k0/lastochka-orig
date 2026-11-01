<?php

namespace App\GraphQL\Mutations\MerchantReview;

use App\Models\MerchantReview;

final class PublishMerchantReview
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantReview
    {
        $review = MerchantReview::findOrFail($args['id']);

        $review->update([
            'is_published' => $args['is_published'],
        ]);

        return $review->fresh(['shop']);
    }
}
