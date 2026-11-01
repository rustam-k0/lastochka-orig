<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Публиковать только отзывы от авторизованных пользователей.
 * Внешние отзывы (source != storefront) не публикуем обратно.
 */
final class ReviewHasUserCondition implements PublishCondition
{
    public function __invoke(Model $review): bool
    {
        // Не публиковать внешние отзывы обратно
        if ($review->source && $review->source !== config('rabbitmq-events.source')) {
            return false;
        }

        return $review->user_id !== null;
    }
}
