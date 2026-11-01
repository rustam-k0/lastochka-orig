<?php

namespace App\GraphQL\Queries\Aggregator;

use App\Aggregator\Services\OfferAvailabilityResolver;
use App\Models\PlatformProduct;

/**
 * Офферы платформенного товара для витрины (aggregator-режим, FOODLINK-10874, §6.6).
 */
final class OffersForProductQuery
{
    public function __construct(private OfferAvailabilityResolver $resolver)
    {
    }

    public function __invoke($_, array $args)
    {
        $product = PlatformProduct::findOrFail($args['id']);
        $point = $args['deliveryPoint'] ?? null;

        return $this->resolver->resolveOffersForPoint($product, $point);
    }
}
