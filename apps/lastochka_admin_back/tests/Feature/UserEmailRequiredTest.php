<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffFieldSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-293: обязательность почты сотрудника регулируется настройкой
 * staff_email_required. Включена — UserCreate/UserUpdate требуют непустой
 * уникальный email; выключена (значение по умолчанию, поведение прочих форков) —
 * сотрудник заводится и правится без почты. Уникальность действует в обоих
 * режимах.
 */
class UserEmailRequiredTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const CREATE = /** @lang GraphQL */ '
        mutation ($name: String, $surname: String, $phone: String, $email: String, $role_id: Int, $password: String, $password_confirmation: String) {
            UserCreate(name: $name, surname: $surname, phone: $phone, email: $email, role_id: $role_id, password: $password, password_confirmation: $password_confirmation) {
                code
                message
            }
        }
    ';

    private const UPDATE = /** @lang GraphQL */ '
        mutation ($id: Int, $name: String, $surname: String, $phone: String, $email: String) {
            UserUpdate(id: $id, name: $name, surname: $surname, phone: $phone, email: $email) {
                code
                message
            }
        }
    ';

    private User $adminUser;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::firstOrCreate(
            ['name' => Role::ADMIN, 'guard_name' => 'api'],
            ['name_ru' => 'Администратор'],
        );
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->adminUser = $this->makeUser();
        $this->adminUser->assignRole($this->adminRole);

        $this->setEmailRequired(true);
    }

    private function setEmailRequired(bool $enabled): void
    {
        AdminSetting::setValue(StaffFieldSettings::EMAIL_REQUIRED_KEY, $enabled ? '1' : '0', 'boolean');
    }

    private function makeUser(?string $email = null): User
    {
        return User::create([
            'name'    => 'Тест',
            'surname' => 'Сотрудник',
            'phone'   => '+7' . mt_rand(9000000000, 9999999999),
            'email'   => $email ?? ('staff_' . uniqid() . '@test.local'),
        ]);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Новый',
            'surname'               => 'Сотрудник',
            'phone'                 => '+7' . mt_rand(9000000000, 9999999999),
            'email'                 => 'new_' . uniqid() . '@test.local',
            'role_id'               => $this->adminRole->id,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    private function validationErrorsFor(array $response, string $field): array
    {
        $errors = $response['errors'][0]['extensions']['validation'] ?? [];

        return $errors[$field] ?? [];
    }

    public function test_create_without_email_is_rejected(): void
    {
        $payload = $this->createPayload();
        unset($payload['email']);

        $response = $this->actingAs($this->adminUser)->graphQL(self::CREATE, $payload)->json();

        $this->assertNotEmpty($this->validationErrorsFor($response, 'email'), 'UserCreate без email должен вернуть ошибку валидации email');
        $this->assertDatabaseMissing('users', ['phone' => $payload['phone']]);
    }

    public function test_create_with_null_email_is_rejected(): void
    {
        $payload = $this->createPayload(['email' => null]);

        $response = $this->actingAs($this->adminUser)->graphQL(self::CREATE, $payload)->json();

        $this->assertNotEmpty($this->validationErrorsFor($response, 'email'));
        $this->assertDatabaseMissing('users', ['phone' => $payload['phone']]);
    }

    public function test_create_with_email_succeeds(): void
    {
        $payload = $this->createPayload();

        $response = $this->actingAs($this->adminUser)->graphQL(self::CREATE, $payload)->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('users', ['phone' => $payload['phone'], 'email' => $payload['email']]);
    }

    public function test_update_with_null_email_is_rejected(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => $user->name,
            'surname' => $user->surname,
            'phone'   => $user->phone,
            'email'   => null,
        ])->json();

        $this->assertNotEmpty($this->validationErrorsFor($response, 'email'));
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_update_with_email_of_another_user_is_rejected(): void
    {
        $other = $this->makeUser();
        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => $user->name,
            'surname' => $user->surname,
            'phone'   => $user->phone,
            'email'   => $other->email,
        ])->json();

        $this->assertNotEmpty($this->validationErrorsFor($response, 'email'));
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_update_without_email_key_keeps_email(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => 'Частичный',
            'surname' => $user->surname,
            'phone'   => $user->phone,
        ])->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame('Частичный', $user->fresh()->name);
    }

    public function test_update_keeping_own_email_succeeds(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => 'Обновлённый',
            'surname' => $user->surname,
            'phone'   => $user->phone,
            'email'   => $user->email,
        ])->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Обновлённый', $user->fresh()->name);
    }

    public function test_setting_disabled_allows_create_without_email(): void
    {
        $this->setEmailRequired(false);

        $payload = $this->createPayload();
        unset($payload['email']);

        $response = $this->actingAs($this->adminUser)->graphQL(self::CREATE, $payload)->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('users', ['phone' => $payload['phone'], 'email' => null]);
    }

    public function test_setting_disabled_allows_clearing_email_on_update(): void
    {
        $this->setEmailRequired(false);

        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => $user->name,
            'surname' => $user->surname,
            'phone'   => $user->phone,
            'email'   => null,
        ])->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertNull($user->fresh()->email);
    }

    public function test_setting_disabled_still_rejects_duplicate_email(): void
    {
        $this->setEmailRequired(false);

        $other = $this->makeUser();
        $user = $this->makeUser();

        $response = $this->actingAs($this->adminUser)->graphQL(self::UPDATE, [
            'id'      => $user->id,
            'name'    => $user->name,
            'surname' => $user->surname,
            'phone'   => $user->phone,
            'email'   => $other->email,
        ])->json();

        $this->assertNotEmpty($this->validationErrorsFor($response, 'email'));
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_setting_absent_in_db_behaves_as_disabled(): void
    {
        AdminSetting::query()->where('key', StaffFieldSettings::EMAIL_REQUIRED_KEY)->delete();

        $payload = $this->createPayload();
        unset($payload['email']);

        $response = $this->actingAs($this->adminUser)->graphQL(self::CREATE, $payload)->json();

        $this->assertArrayNotHasKey('errors', $response, json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('users', ['phone' => $payload['phone'], 'email' => null]);
    }
}
