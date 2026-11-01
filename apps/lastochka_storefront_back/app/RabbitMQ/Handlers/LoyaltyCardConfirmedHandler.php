<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

final class LoyaltyCardConfirmedHandler
{
    /**
     * loyalty_card.confirmed от 1c_parser: заказ «Лояльность» ушёл в 1С,
     * карта подтверждена. Идемпотентно: повторное событие — no-op.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handle(array $eventData): string
    {
        $payload = $eventData['payload'] ?? $eventData;
        $cardNumber = $payload['card_number'] ?? null;

        if (!$cardNumber) {
            return 'ack';
        }

        $user = User::withTrashed()->where('card_number', $cardNumber)->first();

        if (!$user) {
            Log::warning('loyalty_card.confirmed: пользователь с card_number не найден', [
                'card_number' => $cardNumber,
            ]);

            return 'ack';
        }

        if ($user->card_confirmed_at === null) {
            $user->card_confirmed_at = now();
            $user->save();
        }

        return 'ack';
    }
}
