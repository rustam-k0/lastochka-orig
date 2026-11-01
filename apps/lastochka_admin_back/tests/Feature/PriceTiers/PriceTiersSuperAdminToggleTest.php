<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Менять price_tiers.enabled может только суперадмин, независимо от
 * role_access_matrix_enabled (MID-308). Остальные модули сохраняются как раньше.
 */
class PriceTiersSuperAdminToggleTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    private const MUTATION = /** @lang GraphQL */ '
        mutation ($config: String!) {
            saveProjectModules(config: $config) { key value }
        }
    ';

    private const DENIED = 'Модуль «Цены от количества» включает и выключает только суперадминистратор.';

    protected function setUp(): void
    {
        parent::setUp();

        // Матрица ролей выключена: manageSettings пускает любого глобального
        // админа, и отказ обязан прийти именно от проверки price_tiers.
        AdminSetting::setValue('role_access_matrix_enabled', '0', 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function admin(bool $superAdmin): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        if ($superAdmin) {
            $user->assignRole(Role::findOrCreate(Role::SUPERADMIN));
        }

        return $user->fresh();
    }

    /** @param array<string, array<string, mixed>> $modules */
    private function store(array $modules): void
    {
        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode($modules, JSON_UNESCAPED_UNICODE),
            'json'
        );
        ModuleConfigService::invalidateCache();
    }

    /** @return array<string, mixed> */
    private function storedModules(): array
    {
        return json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true) ?: [];
    }

    public function test_admin_without_superadmin_cannot_enable_price_tiers(): void
    {
        $this->store([
            'b2b' => ['label' => 'B2B', 'enabled' => false],
            'price_tiers' => ['label' => 'Цены от количества', 'enabled' => false],
        ]);

        $response = $this->actingAs($this->admin(false))->graphQL(self::MUTATION, [
            'config' => json_encode([
                'b2b' => ['label' => 'B2B', 'enabled' => true],
                'price_tiers' => ['label' => 'Цены от количества', 'enabled' => true],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response->assertGraphQLValidationError('price_tiers', self::DENIED);

        // Отказ атомарный: блоб не сохранён целиком.
        $stored = $this->storedModules();
        $this->assertFalse($stored['price_tiers']['enabled']);
        $this->assertFalse($stored['b2b']['enabled']);
        $this->assertFalse(ModuleConfigService::isPriceTiersEnabled());
    }

    public function test_admin_without_superadmin_cannot_disable_price_tiers(): void
    {
        $this->store(['price_tiers' => ['label' => 'Цены от количества', 'enabled' => true]]);

        // Ключ отсутствует в присланном блобе = false: это тоже смена значения.
        $response = $this->actingAs($this->admin(false))->graphQL(self::MUTATION, [
            'config' => json_encode([
                'b2b' => ['label' => 'B2B', 'enabled' => true],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response->assertGraphQLValidationError('price_tiers', self::DENIED);
        $this->assertTrue($this->storedModules()['price_tiers']['enabled']);
    }

    public function test_admin_without_superadmin_saves_other_modules_as_before(): void
    {
        $this->store([
            'b2b' => ['label' => 'B2B', 'enabled' => false],
            'price_tiers' => ['label' => 'Цены от количества', 'enabled' => false],
        ]);

        $this->actingAs($this->admin(false))->graphQL(self::MUTATION, [
            'config' => json_encode([
                'b2b' => ['label' => 'B2B', 'enabled' => true],
                'price_tiers' => ['label' => 'Цены от количества', 'enabled' => false],
            ], JSON_UNESCAPED_UNICODE),
        ])->assertJsonMissing(['errors']);

        $stored = $this->storedModules();
        $this->assertTrue($stored['b2b']['enabled']);
        $this->assertFalse($stored['price_tiers']['enabled']);
    }

    public function test_missing_key_on_both_sides_is_not_a_change(): void
    {
        $this->store(['b2b' => ['label' => 'B2B', 'enabled' => false]]);

        $this->actingAs($this->admin(false))->graphQL(self::MUTATION, [
            'config' => json_encode([
                'b2b' => ['label' => 'B2B', 'enabled' => true],
            ], JSON_UNESCAPED_UNICODE),
        ])->assertJsonMissing(['errors']);

        $this->assertTrue($this->storedModules()['b2b']['enabled']);
    }

    public function test_superadmin_toggles_price_tiers_and_cache_is_flushed(): void
    {
        $this->store(['price_tiers' => ['label' => 'Цены от количества', 'enabled' => false]]);
        $this->assertFalse(ModuleConfigService::isPriceTiersEnabled()); // прогрев кэша

        $this->actingAs($this->admin(true))->graphQL(self::MUTATION, [
            'config' => json_encode([
                'price_tiers' => ['label' => 'Цены от количества', 'enabled' => true],
            ], JSON_UNESCAPED_UNICODE),
        ])->assertJsonMissing(['errors']);

        $this->assertTrue($this->storedModules()['price_tiers']['enabled']);
        $this->assertTrue(ModuleConfigService::isPriceTiersEnabled(), 'кэш флага сброшен мутацией');
    }

    public function test_matrix_flag_does_not_relax_the_rule(): void
    {
        // Матрица включена: manageSettings сам требует суперадмина, и обычного
        // админа не пускает уже политика. Суперадмин проходит обе проверки.
        AdminSetting::setValue('role_access_matrix_enabled', '1', 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->store(['price_tiers' => ['label' => 'Цены от количества', 'enabled' => false]]);

        $config = json_encode([
            'price_tiers' => ['label' => 'Цены от количества', 'enabled' => true],
        ], JSON_UNESCAPED_UNICODE);

        $denied = $this->actingAs($this->admin(false))->graphQL(self::MUTATION, ['config' => $config]);
        $this->assertNotEmpty($denied->json('errors'));
        $this->assertFalse($this->storedModules()['price_tiers']['enabled']);

        $this->actingAs($this->admin(true))->graphQL(self::MUTATION, ['config' => $config])
            ->assertJsonMissing(['errors']);
        $this->assertTrue($this->storedModules()['price_tiers']['enabled']);
    }
}
