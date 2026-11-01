<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Role;
use App\Models\User;
use App\Transformers\EmployeeEventTransformer;
use Comrades\RabbitMQEvents\Exceptions\InvalidEventException;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: сотрудники из 1С (employee.created / employee.updated).
 */
class EmployeeEventTransformerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    private int $minute = 30;

    private Role $outsideRole;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['name' => Role::COLLECTOR, 'guard_name' => 'api'], ['name_ru' => 'Сборщик']);
        Role::updateOrCreate(['name' => Role::COURIER, 'guard_name' => 'api'], ['name_ru' => 'Курьер']);
        Role::updateOrCreate(['name' => Role::ADMIN, 'guard_name' => 'api'], ['name_ru' => 'Администратор']);
        Role::updateOrCreate(['name' => Role::SUPERADMIN, 'guard_name' => 'api'], ['name_ru' => 'Супер-администратор']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->outsideRole = Role::updateOrCreate(
            ['name' => 'role_onec_outside_test', 'guard_name' => 'api'],
            ['name_ru' => 'Проджект менеджер тест'],
        );
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, Role::ADMIN]);
    }

    private function payload(string $guid, array $overrides, string $source = '1c'): array
    {
        $this->minute--;

        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $guid,
            'source' => $source,
            'event_source' => $source,
        ], $overrides);
    }

    private function send(string $guid, array $overrides, string $source = '1c'): void
    {
        $result = (new EventConsumer('employees', config('rabbitmq-events.entities.employees')))
            ->processEvent($this->payload($guid, $overrides, $source));

        $this->assertSame('ack', $result);
    }

    private function phone(): string
    {
        return '+7999' . random_int(1000000, 9999999);
    }

    private function employee(string $guid): ?User
    {
        $entityId = EventSyncMetadata::query()
            ->where('entity_type', 'employees')
            ->where('source', '1c')
            ->where('external_id', $guid)
            ->value('entity_id');

        return $entityId ? User::withTrashed()->find($entityId) : null;
    }

    private function create(array $overrides = []): array
    {
        $guid = (string) Str::uuid();
        $phone = $this->phone();
        $this->send($guid, array_merge([
            'surname' => 'Иванов',
            'name' => 'Пётр',
            'phone' => $phone,
            'role' => 'collector',
        ], $overrides));

        return [$guid, $phone];
    }

    public function test_employees_entity_is_consume_only(): void
    {
        $config = config('rabbitmq-events.entities.employees');

        $this->assertFalse($config['enabled']);
        $this->assertTrue($config['global_scope']);
        $this->assertSame(['employee.created', 'employee.updated'], $config['routing_key_patterns']);
        $this->assertSame('admin_employees_in', config('rabbitmq-events.inbound_queues.employees'));
    }

    public function test_creates_employee_with_role_and_random_password(): void
    {
        $this->setOneCShopsMaster(true);
        // Боевая стоимость bcrypt: пароль сотрудника из 1С всё равно хешируется с rounds=4.
        config()->set('hashing.bcrypt.rounds', 10);
        app()->forgetInstance('hash');
        Hash::clearResolvedInstance('hash');
        $guid = (string) Str::uuid();
        $digits = (string) random_int(1000000, 9999999);

        $this->send($guid, [
            'surname' => 'Иванов',
            'name' => 'Пётр',
            'patronymic' => 'Сергеевич',
            'phone' => '8 (999) ' . $digits,
            'email' => 'ivanov.' . $digits . '@test.local',
            'role' => 'collector',
        ]);

        $user = $this->employee($guid);
        $this->assertNotNull($user);
        $this->assertSame('Иванов', $user->surname);
        $this->assertSame('Пётр', $user->name);
        $this->assertSame('Сергеевич', $user->patronymic);
        $this->assertSame('+7999' . $digits, $user->phone);
        $this->assertSame('ivanov.' . $digits . '@test.local', $user->email);
        $this->assertTrue($user->hasRole(Role::COLLECTOR));
        $this->assertNotEmpty($user->getAuthPassword());
        // Одноразовый случайный пароль: дешёвый bcrypt, чтобы пачка не упиралась в хеширование.
        $this->assertSame(4, password_get_info($user->getAuthPassword())['options']['cost'] ?? null);
        $this->assertNotEmpty($user->external_user_id);
    }

    public function test_full_name_is_split_and_russian_role_name_is_accepted(): void
    {
        $this->setOneCShopsMaster(true);
        $guid = (string) Str::uuid();

        $this->send($guid, ['full_name' => ' Петрова  Анна Мария Ивановна ', 'phone' => $this->phone(), 'role' => 'КУРЬЕР']);

        $user = $this->employee($guid);
        $this->assertSame('Петрова', $user->surname);
        $this->assertSame('Анна', $user->name);
        $this->assertSame('Мария Ивановна', $user->patronymic);
        $this->assertTrue($user->hasRole(Role::COURIER));
    }

    public function test_links_manually_created_employee_by_phone(): void
    {
        $this->setOneCShopsMaster(true);
        $phone = $this->phone();
        $manual = User::create(['name' => 'Ручной', 'surname' => 'Сотрудник', 'phone' => $phone]);
        $guid = (string) Str::uuid();

        $this->send($guid, ['surname' => 'Сидоров', 'name' => 'Олег', 'phone' => $phone, 'role' => 'courier']);

        $this->assertSame($manual->id, $this->employee($guid)?->id);
        $this->assertSame(1, User::query()->where('phone', $phone)->count());
        $this->assertSame('Сидоров', $manual->fresh()->surname);
        $this->assertTrue($manual->fresh()->hasRole(Role::COURIER));
    }

    public function test_phone_of_other_1c_employee_is_not_taken(): void
    {
        $this->setOneCShopsMaster(true);
        [$first, $phone] = $this->create(['surname' => 'Первый']);
        $second = (string) Str::uuid();

        $this->send($second, ['surname' => 'Второй', 'name' => 'Сотрудник', 'phone' => $phone, 'role' => 'collector']);

        $this->assertNull($this->employee($second));
        $this->assertSame('Первый', $this->employee($first)->surname);
        $this->assertSame(1, User::query()->where('phone', $phone)->count());
    }

    public function test_unknown_or_superadmin_role_skips_creation(): void
    {
        $this->setOneCShopsMaster(true);

        foreach (['кладовщик', 'superadmin'] as $role) {
            $guid = (string) Str::uuid();
            $phone = $this->phone();

            $this->send($guid, ['surname' => 'Без', 'name' => 'Роли', 'phone' => $phone, 'role' => $role]);

            $this->assertNull($this->employee($guid), $role);
            $this->assertSame(0, User::query()->where('phone', $phone)->count(), $role);
        }
    }

    public function test_missing_required_fields_skip_creation(): void
    {
        $this->setOneCShopsMaster(true);
        $guid = (string) Str::uuid();

        $this->send($guid, ['surname' => 'Безымянный', 'phone' => '12345', 'role' => 'collector']);

        $this->assertNull($this->employee($guid));
    }

    public function test_update_keeps_missing_fields_and_ignores_empty_names(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid, $phone] = $this->create(['email' => 'keep.' . Str::random(6) . '@test.local']);
        $email = $this->employee($guid)->email;

        $this->send($guid, ['surname' => null, 'name' => 'Павел']);

        $user = $this->employee($guid);
        $this->assertSame('Иванов', $user->surname);
        $this->assertSame('Павел', $user->name);
        $this->assertSame($email, $user->email);
        $this->assertSame($phone, $user->phone);
        $this->assertTrue($user->hasRole(Role::COLLECTOR));
    }

    public function test_update_does_not_take_phone_of_other_employee(): void
    {
        $this->setOneCShopsMaster(true);
        $otherPhone = $this->phone();
        User::create(['name' => 'Чужой', 'surname' => 'Телефон', 'phone' => $otherPhone]);
        [$guid, $phone] = $this->create();

        $this->send($guid, ['phone' => $otherPhone]);
        $this->send($guid, ['phone' => 'нет телефона']);

        $this->assertSame($phone, $this->employee($guid)->phone);
    }

    public function test_update_with_unknown_role_keeps_role(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid] = $this->create();

        $this->send($guid, ['role' => 'кладовщик']);

        $this->assertTrue($this->employee($guid)->hasRole(Role::COLLECTOR));

        $this->send($guid, ['role' => 'Курьер']);

        $user = $this->employee($guid);
        $this->assertTrue($user->hasRole(Role::COURIER));
        $this->assertFalse($user->hasRole(Role::COLLECTOR));
    }

    public function test_email_taken_or_invalid_is_not_set(): void
    {
        $this->setOneCShopsMaster(true);
        $taken = 'taken.' . Str::random(6) . '@test.local';
        User::create(['name' => 'Занятая', 'surname' => 'Почта', 'phone' => $this->phone(), 'email' => $taken]);

        [$guid] = $this->create(['email' => $taken]);
        $this->assertNull($this->employee($guid)->email);

        $this->send($guid, ['email' => 'не почта']);
        $this->assertNull($this->employee($guid)->email);
    }

    public function test_trashed_employee_is_not_updated(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid] = $this->create();
        $this->employee($guid)->delete();

        $this->send($guid, ['surname' => 'Изменён']);

        $user = $this->employee($guid);
        $this->assertTrue($user->trashed());
        $this->assertSame('Иванов', $user->surname);
    }

    public function test_flag_off_skips_employee(): void
    {
        $this->setOneCShopsMaster(false);
        $guid = (string) Str::uuid();
        $phone = $this->phone();

        $this->send($guid, ['surname' => 'Выкл', 'name' => 'Модуль', 'phone' => $phone, 'role' => 'collector']);

        $this->assertNull($this->employee($guid));
        $this->assertSame(0, User::query()->where('phone', $phone)->count());
    }

    public function test_event_not_from_1c_is_skipped(): void
    {
        $this->setOneCShopsMaster(true);
        $phone = $this->phone();

        $this->send((string) Str::uuid(), ['surname' => 'Чужой', 'name' => 'Источник', 'phone' => $phone, 'role' => 'collector'], 'retailbridge');

        $this->assertSame(0, User::query()->where('phone', $phone)->count());
    }

    public function test_user_with_role_outside_whitelist_is_not_linked_by_phone(): void
    {
        $this->setOneCShopsMaster(true);

        foreach ([$this->outsideRole->name, Role::SUPERADMIN] as $roleName) {
            $phone = $this->phone();
            $email = 'manual.' . Str::lower(Str::random(8)) . '@test.local';
            $manual = User::create(['name' => 'Админ', 'surname' => 'Ручной', 'phone' => $phone, 'email' => $email]);
            $manual->assignRole($roleName);
            $guid = (string) Str::uuid();

            $this->send($guid, [
                'surname' => 'Иванов',
                'name' => 'Пётр',
                'phone' => $phone,
                'email' => 'from1c.' . Str::lower(Str::random(8)) . '@test.local',
                'role' => 'collector',
            ]);

            $this->assertNull($this->employee($guid), $roleName);
            $this->assertSame(0, EventSyncMetadata::query()
                ->where('entity_type', 'employees')
                ->where('external_id', $guid)
                ->count(), $roleName);
            $this->assertSame(1, User::query()->where('phone', $phone)->count(), $roleName);

            $fresh = User::query()->findOrFail($manual->id);
            $this->assertSame($email, $fresh->email, $roleName);
            $this->assertSame($phone, $fresh->phone, $roleName);
            $this->assertSame('Ручной', $fresh->surname, $roleName);
            $this->assertSame([$roleName], $fresh->getRoleNames()->all(), $roleName);
        }
    }

    public function test_role_outside_whitelist_is_not_assigned(): void
    {
        $this->setOneCShopsMaster(true);

        foreach ([$this->outsideRole->name, 'Проджект менеджер тест'] as $role) {
            $guid = (string) Str::uuid();
            $phone = $this->phone();

            $this->send($guid, ['surname' => 'Хочу', 'name' => 'Админа', 'phone' => $phone, 'role' => $role]);

            $this->assertNull($this->employee($guid), $role);
            $this->assertSame(0, User::query()->where('phone', $phone)->count(), $role);
        }

        [$guid] = $this->create();
        $this->send($guid, ['role' => 'Проджект менеджер тест']);

        $user = $this->employee($guid);
        $this->assertSame([Role::COLLECTOR], $user->getRoleNames()->all());
    }

    public function test_admin_role_is_assigned_when_whitelisted(): void
    {
        $this->setOneCShopsMaster(true);

        foreach (['admin', 'Администратор'] as $role) {
            $guid = (string) Str::uuid();
            $phone = $this->phone();

            $this->send($guid, ['surname' => 'Админов', 'name' => 'Пётр', 'phone' => $phone, 'role' => $role]);

            $user = $this->employee($guid);
            $this->assertNotNull($user, $role);
            $this->assertSame([Role::ADMIN], $user->getRoleNames()->all(), $role);
        }
    }

    public function test_existing_admin_is_linked_by_phone_when_admin_whitelisted(): void
    {
        $this->setOneCShopsMaster(true);
        $phone = $this->phone();
        $manual = User::create(['name' => 'Ручной', 'surname' => 'Админ', 'phone' => $phone]);
        $manual->assignRole(Role::ADMIN);
        $guid = (string) Str::uuid();

        $this->send($guid, ['surname' => 'Иванов', 'name' => 'Пётр', 'phone' => $phone, 'role' => 'admin']);

        $this->assertSame($manual->id, $this->employee($guid)?->id);
        $this->assertSame('Иванов', $manual->fresh()->surname);
        $this->assertSame([Role::ADMIN], $manual->fresh()->getRoleNames()->all());
    }

    public function test_default_whitelist_includes_admin(): void
    {
        if (env('ONEC_EMPLOYEE_ROLES') !== null) {
            $this->markTestSkipped('ONEC_EMPLOYEE_ROLES задан в окружении, дефолт конфига не проверить');
        }

        $config = require base_path('config/onec.php');

        $this->assertSame([Role::COLLECTOR, Role::COURIER, Role::ADMIN], $config['employee_roles']);
    }

    public function test_linked_employee_granted_role_outside_whitelist_is_not_updated(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid, $phone] = $this->create();
        $this->employee($guid)->assignRole($this->outsideRole->name);

        $this->send($guid, ['surname' => 'Новая', 'phone' => $this->phone(), 'role' => 'courier']);

        $user = $this->employee($guid);
        $this->assertSame('Иванов', $user->surname);
        $this->assertSame($phone, $user->phone);
        $this->assertTrue($user->hasRole($this->outsideRole->name));
        $this->assertTrue($user->hasRole(Role::COLLECTOR));
        $this->assertFalse($user->hasRole(Role::COURIER));
    }

    public function test_extended_whitelist_assigns_custom_role(): void
    {
        $this->setOneCShopsMaster(true);
        $title = 'Кассир 1С ' . Str::random(5);
        $custom = Role::create(['name' => 'role_onec_' . Str::lower(Str::random(8)), 'guard_name' => 'api', 'name_ru' => $title]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, Role::ADMIN, $custom->name]);

        [$guid] = $this->create(['role' => mb_strtoupper($title)]);

        $user = $this->employee($guid);
        $this->assertNotNull($user);
        $this->assertSame([$custom->name], $user->getRoleNames()->all());

        $this->send($guid, ['surname' => 'Кассиров']);
        $this->assertSame('Кассиров', $this->employee($guid)->surname);
    }

    public function test_superadmin_in_whitelist_is_still_not_assigned(): void
    {
        $this->setOneCShopsMaster(true);
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, Role::ADMIN, Role::SUPERADMIN, Role::API]);

        foreach (['superadmin', 'api'] as $role) {
            $guid = (string) Str::uuid();
            $phone = $this->phone();

            $this->send($guid, ['surname' => 'Супер', 'name' => 'Админ', 'phone' => $phone, 'role' => $role]);

            $this->assertNull($this->employee($guid), $role);
            $this->assertSame(0, User::query()->where('phone', $phone)->count(), $role);
        }
    }

    public function test_same_role_is_not_resynced(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid] = $this->create();

        $roleWrites = [];
        DB::listen(function ($query) use (&$roleWrites) {
            if (preg_match('/^\s*(delete|insert)\b.*\bmodel_has_roles\b/i', $query->sql) === 1) {
                $roleWrites[] = $query->sql;
            }
        });

        $this->send($guid, ['surname' => 'Повторов', 'role' => 'Сборщик']);

        $this->assertSame([], $roleWrites);
        $this->assertSame('Повторов', $this->employee($guid)->surname);
        $this->assertSame([Role::COLLECTOR], $this->employee($guid)->getRoleNames()->all());

        $this->send($guid, ['role' => 'courier']);

        $this->assertNotSame([], $roleWrites);
        $this->assertSame([Role::COURIER], $this->employee($guid)->getRoleNames()->all());
    }

    public function test_deleted_event_type_is_rejected_and_employee_not_deleted(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid] = $this->create();

        $this->expectException(InvalidEventException::class);

        try {
            (new EventConsumer('employees', config('rabbitmq-events.entities.employees')))
                ->processEvent($this->payload($guid, ['event_type' => 'deleted']));
        } finally {
            $user = $this->employee($guid);
            $this->assertNotNull($user);
            $this->assertFalse($user->trashed());
        }
    }

    public function test_before_delete_hook_returns_false(): void
    {
        $this->setOneCShopsMaster(true);
        [$guid] = $this->create();
        $user = $this->employee($guid);

        $result = app(EmployeeEventTransformer::class)->beforeDelete($user, [
            'event_id' => (string) Str::uuid(),
            'id' => $guid,
            'source' => '1c',
            'event_source' => '1c',
        ]);

        $this->assertFalse($result);
    }

    public function test_unsupported_event_type_is_rejected_without_orphan_metadata(): void
    {
        $this->setOneCShopsMaster(true);
        $guid = (string) Str::uuid();

        $this->expectException(InvalidEventException::class);

        try {
            (new EventConsumer('employees', config('rabbitmq-events.entities.employees')))
                ->processEvent($this->payload($guid, [
                    'event_type' => 'fired',
                    'surname' => 'Уволен',
                    'name' => 'Иван',
                    'phone' => $this->phone(),
                    'role' => 'collector',
                ]));
        } finally {
            $this->assertSame(0, EventSyncMetadata::query()
                ->where('entity_type', 'employees')
                ->where('source', '1c')
                ->where('external_id', $guid)
                ->count());
        }
    }

    public function test_patronymic_overrides_full_name_split(): void
    {
        $this->setOneCShopsMaster(true);
        $guid = (string) Str::uuid();

        $this->send($guid, [
            'full_name' => 'Сидорова Мария',
            'patronymic' => 'Петровна',
            'phone' => $this->phone(),
            'role' => 'collector',
        ]);

        $user = $this->employee($guid);
        $this->assertSame('Сидорова', $user->surname);
        $this->assertSame('Мария', $user->name);
        $this->assertSame('Петровна', $user->patronymic);
    }
}
