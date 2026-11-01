<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Публиковать только клиентов с телефоном.
 */
final class CustomerHasPhoneCondition implements PublishCondition
{
    public function __invoke(Model $user): bool
    {
        return ! empty($user->phone);
    }
}
