<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CustomerEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'phone'];
    }

    /**
     * Преобразовать модель User в событие для RabbitMQ.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $user, ?object $shop = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $user->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $user->id,
            'shop_code' => config('rabbitmq-events.shop_code', 'STOREFRONT'),
            'source' => config('rabbitmq-events.source'),
            'phone' => $user->phone,
            'name' => $user->first_name,
            'surname' => $user->last_name,
            'patronymic' => $user->patronymic,
            'email' => $user->email,
            'birthday' => $user->birth_date?->format('Y-m-d'),
            'gender' => $user->gender?->value,
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
            'loyalty_id' => $user->loyalty_id ?: $user->ensureLoyaltyId(),
            'bonus_balance' => (int) ($user->bonus_balance ?? 0),
            'bonus_reserved' => (int) ($user->bonus_reserved ?? 0),
            // Номер карты лояльности — только подтверждённая 1С (has_card-семантика):
            // pending-карта в 1С/админку не уходит, чтобы не выглядеть существующей.
            'card_number' => $user->hasActiveCard() ? $user->card_number : null,
        ];
    }

    /**
     * Преобразовать входящее событие в данные модели User.
     * Используется при получении customer.* от CRM.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [
            'phone' => $data['phone'],
            'first_name' => $data['name'] ?? null,
            'last_name' => $data['surname'] ?? null,
            'patronymic' => $data['patronymic'] ?? null,
            'email' => $data['email'] ?? null,
            'birth_date' => $data['birthday'] ?? null,
            'gender' => $data['gender'] ?? null,
            'loyalty_id' => $data['loyalty_id'] ?? null,
        ];
    }

    /**
     * Поиск существующего пользователя по телефону.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        return User::withTrashed()
            ->where('phone', $eventData['phone'])
            ->first();
    }

    /**
     * Перед обновлением — не перезаписывать локальные данные, если источник не админка.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeUpdate(Model $user, array $eventData, array $transformedData): ?array
    {
        // Storefront — генератор loyalty_id: никогда не перезаписываем непустой локальный
        // loyalty_id входящим событием, независимо от источника.
        if ($user->loyalty_id && array_key_exists('loyalty_id', $transformedData)) {
            unset($transformedData['loyalty_id']);
        }

        // payload.source перезаписывается ModelEventObserver на external_source из
        // event_sync_metadata (admin → storefront уходит с source='storefront'),
        // поэтому для определения авторства события используем event_source —
        // он содержит подлинный source публикатора (config('rabbitmq-events.source')).
        $eventSource = $eventData['event_source'] ?? $eventData['source'] ?? null;

        // Если обновление пришло НЕ от самого storefront (admin, 1С, retailbridge) —
        // применяем все поля без ограничений
        if ($eventSource && $eventSource !== config('rabbitmq-events.source')) {
            return empty($transformedData) ? null : $transformedData;
        }

        // Если обновление от самого storefront — не перезаписываем заполненные локально
        if ($user->first_name && isset($transformedData['first_name'])) {
            unset($transformedData['first_name']);
        }
        if ($user->last_name && isset($transformedData['last_name'])) {
            unset($transformedData['last_name']);
        }
        if ($user->email && isset($transformedData['email'])) {
            unset($transformedData['email']);
        }

        return empty($transformedData) ? null : $transformedData;
    }
}
