<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\User;
use App\Services\Store\AvailableStoreService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class CustomerB2bStatusTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['phone', 'is_b2b'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели User.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [
            'phone' => $data['phone'],
            'is_b2b' => (bool) $data['is_b2b'],
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
        $phone = $eventData['phone'] ?? null;

        if ($phone === null) {
            return null;
        }

        $user = User::where('phone', $phone)->first();

        // Если пользователь не существует — создать минимальную запись.
        // withoutEvents чтобы не публиковать обратно customer.created с пустыми полями
        if (!$user) {
            $user = User::withoutEvents(function () use ($phone, $eventData) {
                return User::create([
                    'phone' => $phone,
                    'is_b2b' => (bool) ($eventData['is_b2b'] ?? false),
                ]);
            });
        }

        return $user;
    }

    /**
     * Обработчик кастомного события b2b_status_changed.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processB2bStatusChangedEvent(Model $user, array $eventData): void
    {
        $user->update(['is_b2b' => (bool) $eventData['is_b2b']]);

        // Инвалидировать кеш доступных магазинов для всех адресов пользователя,
        // т.к. B2B/B2C клиенты видят разные магазины
        $this->invalidateUserStoresCache($user);

        Log::info('CustomerB2bStatusTransformer: B2B status changed', [
            'user_id' => $user->id,
            'phone' => $eventData['phone'],
            'is_b2b' => $eventData['is_b2b'],
        ]);
    }

    private function invalidateUserStoresCache(Model $user): void
    {
        $service = app(AvailableStoreService::class);

        $user->addresses()->each(function ($address) use ($service) {
            $service->invalidateCache($address->id);
        });
    }

    /**
     * После создания пользователя — логирование.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $user, array $eventData): void
    {
        Log::info('CustomerB2bStatusTransformer: created B2B user', [
            'user_id' => $user->id,
            'phone' => $eventData['phone'],
            'is_b2b' => $eventData['is_b2b'],
        ]);
    }

    /**
     * После обновления пользователя — логирование.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $user, array $eventData): void
    {
        Log::info('CustomerB2bStatusTransformer: updated B2B status', [
            'user_id' => $user->id,
            'phone' => $eventData['phone'],
            'is_b2b' => $eventData['is_b2b'],
        ]);
    }
}
