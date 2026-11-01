<?php

namespace App\GraphQL\Mutations\ProductReview;

use App\Models\ProductReview;
use App\Services\PhoneFormatter;
use Illuminate\Support\Facades\DB;

final class CreateProductReview
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): ProductReview
    {
        return DB::transaction(function () use ($args) {
            $authorPhone = null;
            if (!empty($args['author_phone'])) {
                $authorPhone = PhoneFormatter::format($args['author_phone']);
            }

            $review = ProductReview::create([
                'shop_id' => $args['shop_id'],
                'product_id' => $args['product_id'],
                'rating' => $args['rating'],
                'description' => $args['description'] ?? null,
                'is_published' => $args['is_published'] ?? false,
                'author_name' => $args['author_name'] ?? null,
                'author_phone' => $authorPhone,
            ]);

            return $review->fresh(['shop', 'product']);
        });
    }
}
