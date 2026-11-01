<?php

namespace App\GraphQL\Mutations\MerchantReview;

use App\Models\MerchantReview;
use App\Services\PhoneFormatter;
use Illuminate\Support\Facades\DB;

final class UpdateMerchantReview
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantReview
    {
        return DB::transaction(function () use ($args) {
            $review = MerchantReview::findOrFail($args['id']);

            $updateData = [];

            if (isset($args['rating'])) {
                $updateData['rating'] = $args['rating'];
            }

            if (array_key_exists('description', $args)) {
                $updateData['description'] = $args['description'];
            }

            if (isset($args['is_published'])) {
                $updateData['is_published'] = $args['is_published'];
            }

            if (array_key_exists('author_name', $args)) {
                $updateData['author_name'] = $args['author_name'];
            }

            if (array_key_exists('author_phone', $args)) {
                $updateData['author_phone'] = $args['author_phone']
                    ? PhoneFormatter::format($args['author_phone'])
                    : null;
            }

            if (!empty($updateData)) {
                $review->update($updateData);
            }

            return $review->fresh(['shop']);
        });
    }
}
