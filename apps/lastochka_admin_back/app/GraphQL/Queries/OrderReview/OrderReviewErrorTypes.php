<?php

namespace App\GraphQL\Queries\OrderReview;

use App\Enums\OrderReviewErrorType;

final class OrderReviewErrorTypes
{
    /**
     * Справочник типов ошибок сборки для селекта в карточке отзыва.
     *
     * @param null $_
     * @param array{} $args
     * @return array<int, array{value: string, label: string}>
     */
    public function __invoke($_, array $args): array
    {
        return array_map(
            fn (OrderReviewErrorType $type) => ['value' => $type->value, 'label' => $type->label()],
            OrderReviewErrorType::cases()
        );
    }
}
