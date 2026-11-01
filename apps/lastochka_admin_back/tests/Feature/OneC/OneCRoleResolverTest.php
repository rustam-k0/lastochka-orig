<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Models\Role;
use App\Services\OneC\OneCRoleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MID-306: роль сотрудника из 1С резолвится только из белого списка config('onec.employee_roles').
 */
class OneCRoleResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::updateOrCreate(['name' => Role::COLLECTOR, 'guard_name' => 'api'], ['name_ru' => 'Сборщик']);
        Role::updateOrCreate(['name' => Role::COURIER, 'guard_name' => 'api'], ['name_ru' => 'Курьер']);
        Role::updateOrCreate(['name' => Role::ADMIN, 'guard_name' => 'api'], ['name_ru' => 'Администратор']);
        Role::updateOrCreate(['name' => Role::SUPERADMIN, 'guard_name' => 'api'], ['name_ru' => 'Супер-администратор']);
        Role::firstOrCreate(['name' => Role::API, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER]);
    }

    private function resolver(): OneCRoleResolver
    {
        return app(OneCRoleResolver::class);
    }

    private function customRole(string $nameRu): Role
    {
        return Role::create(['name' => 'role_onec_' . Str::lower(Str::random(8)), 'guard_name' => 'api', 'name_ru' => $nameRu]);
    }

    public function test_default_whitelist_is_collector_courier_and_admin(): void
    {
        if (env('ONEC_EMPLOYEE_ROLES') !== null) {
            $this->markTestSkipped('ONEC_EMPLOYEE_ROLES задан в окружении, дефолт конфига не проверить');
        }

        $config = require base_path('config/onec.php');

        $this->assertSame([Role::COLLECTOR, Role::COURIER, Role::ADMIN], $config['employee_roles']);
    }

    public function test_resolves_by_code_case_insensitive(): void
    {
        $this->assertSame(Role::COLLECTOR, $this->resolver()->resolve(' Collector ')?->name);
    }

    public function test_resolves_by_russian_name(): void
    {
        $this->assertSame(Role::COURIER, $this->resolver()->resolve('КУРЬЕР')?->name);
    }

    public function test_roles_outside_whitelist_are_not_resolved(): void
    {
        $custom = $this->customRole('Кассир тест ' . Str::random(5));

        $this->assertNull($this->resolver()->resolve('admin'));
        $this->assertNull($this->resolver()->resolve('Администратор'));
        $this->assertNull($this->resolver()->resolve($custom->name));
        $this->assertNull($this->resolver()->resolve($custom->name_ru));
    }

    public function test_extended_whitelist_resolves_custom_role(): void
    {
        $title = 'Кассир тест ' . Str::random(5);
        $custom = $this->customRole($title);
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, $custom->name]);

        $this->assertSame($custom->id, $this->resolver()->resolve($custom->name)?->id);
        $this->assertSame($custom->id, $this->resolver()->resolve(mb_strtoupper($title))?->id);
    }

    public function test_code_has_priority_over_russian_name(): void
    {
        $custom = $this->customRole('courier');
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, $custom->name]);

        $this->assertSame(Role::COURIER, $this->resolver()->resolve('courier')?->name);
    }

    public function test_superadmin_and_api_are_not_resolved_even_if_whitelisted(): void
    {
        config()->set('onec.employee_roles', [Role::COLLECTOR, Role::COURIER, Role::SUPERADMIN, Role::API]);

        $this->assertNull($this->resolver()->resolve('superadmin'));
        $this->assertNull($this->resolver()->resolve('Супер-администратор'));
        $this->assertNull($this->resolver()->resolve('api'));
        $this->assertFalse($this->resolver()->isAllowedRoleName(Role::SUPERADMIN));
        $this->assertFalse($this->resolver()->isAllowedRoleName(Role::API));
    }

    public function test_is_allowed_role_name(): void
    {
        $this->assertTrue($this->resolver()->isAllowedRoleName(Role::COLLECTOR));
        $this->assertTrue($this->resolver()->isAllowedRoleName('Courier'));
        $this->assertFalse($this->resolver()->isAllowedRoleName(Role::ADMIN));
        $this->assertFalse($this->resolver()->isAllowedRoleName(''));
    }

    public function test_unknown_or_not_string_is_null(): void
    {
        $this->assertNull($this->resolver()->resolve('кладовщик'));
        $this->assertNull($this->resolver()->resolve(''));
        $this->assertNull($this->resolver()->resolve(null));
        $this->assertNull($this->resolver()->resolve(4));
        $this->assertNull($this->resolver()->resolve(['collector']));
    }
}
