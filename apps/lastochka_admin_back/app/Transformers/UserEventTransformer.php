<?php

namespace App\Transformers;

use App\Models\User;
use App\Models\Type;
use App\Models\Role;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesShopCode;

    protected array $requiredFields = [
        'id',
        'role',
    ];

    /**
     * Convert User to event payload
     */
    public function toEvent(Model $user, ?object $relatedModel = null, ?string $eventId = null): array
    {
        /** @var User $user */

        // Get role
        $role = $user->roles->first()?->name;

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($user->deleted_at ?? $user->updated_at ?? $user->created_at ?? now()),
            'id' => (string) $user->id,
            'shop_code' => $this->resolveShopCode($relatedModel, $user),
            'source' => config('rabbitmq-events.source'),
            'active' => true,
            'role' => $role,
            'name' => $user->name,
            'surname' => $user->surname,
            'patronymic' => $user->patronymic,
            'phone' => $user->phone,
            'email' => $user->email,
            'birthday' => $user->birthday?->format('Y-m-d'),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'deleted_at' => $user->deleted_at,
        ];
    }

    /**
     * Convert event payload to User attributes
     */
    public function fromEvent(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'surname' => $data['surname'] ?? null,
            'patronymic' => $data['patronymic'] ?? null,
            'phone' => $data['phone'] ?? '-',
            'email' => $data['email'] ?? null,
        ];
    }

    /**
     * Find existing user by unique business keys (deduplication)
     *
     * Prevents duplicate user creation when external events reference
     * users that already exist locally but weren't created through RabbitMQ.
     *
     * Search priority:
     * 1. Phone (if not empty) - most reliable identifier
     * 2. Email (if not empty) - fallback identifier
     * 3. Name + Surname combination - last resort for matching
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $phone = $eventData['phone'] ?? null;
        $email = $eventData['email'] ?? null;

        // 1. Search by phone (наиболее надёжный идентификатор)
        if ($phone && $phone !== '-' && $phone !== '') {
            $existing = User::where('phone', $phone)->first();
            if ($existing) {
                \Log::info('User found by phone for deduplication', [
                    'phone' => $phone,
                    'existing_id' => $existing->id,
                ]);
                return $existing;
            }
        }

        // 2. Search by email (fallback)
        if ($email && $email !== '') {
            $existing = User::where('email', $email)->first();
            if ($existing) {
                \Log::info('User found by email for deduplication', [
                    'email' => $email,
                    'existing_id' => $existing->id,
                ]);
                return $existing;
            }
        }

        // Fallback по ФИО (name + surname) намеренно удалён: ФИО — не идентификатор,
        // два разных сотрудника с одинаковыми «Иван Петров» склеивались в одну
        // запись, а delete одного из них (по внешнему источнику) трогал другого.
        // Уникальность обеспечивают phone / email; совпадения ФИО считаем
        // разными сущностями и создаём новую запись.
        return null;
    }

    /**
     * Hook: Before creating a new user
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        // Generate random password since model events are disabled
        if (!isset($transformedData['password'])) {
            $transformedData['password'] = bcrypt(Str::random());
        }

        if (!isset($transformedData['phone'])) {
          $transformedData['phone'] = '-';
        }

        return $transformedData;
    }

    /**
     * Hook: After creating a new user
     */
    public function afterCreate(Model $user, array $eventData): void
    {
        $this->syncRole($user, $eventData);
    }

    /**
     * Hook: After updating a user
     */
    public function afterUpdate(Model $user, array $eventData): void
    {
        $this->syncRole($user, $eventData);
    }

    /**
     * Sync user role from event data
     */
    protected function syncRole(User $user, array $eventData): void
    {
        if (!isset($eventData['role'])) {
            return;
        }

        $roleName = $eventData['role'];

        // Find or create role
        // Using Spatie's Role model logic via our wrapper
        $role = Role::firstOrCreate(['name' => Str::lower(Str::slug($roleName)), 'guard_name' => 'api'],['name_ru'=>$roleName]);

        // Sync roles (replaces existing roles)
        $user->syncRoles([$role]);
    }
}
