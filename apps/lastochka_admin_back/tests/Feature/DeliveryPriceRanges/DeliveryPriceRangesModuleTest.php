<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\GraphQL\Queries\AdminSetting\AdminSettingQueries;
use App\Models\AdminSetting;
use App\Models\Shop;
use App\Models\ShopZone;
use App\Models\Status;
use App\Models\User;
use App\Services\ModuleConfigService;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * MID-280: тумблер модуля delivery_price_ranges в project_modules. Дефолт
 * выключен, гейтит применение ступеней в мутации зоны. Событие магазина флаг
 * НЕ читает (фикс-раунд 1): оно несёт ступени основной зоны всегда, гейт
 * только на чтении — на витрине (иначе выключение/включение тумблера обнуляло
 * бы stores.delivery_price_ranges без возможности вернуть ступени назад).
 * Снимок заказа и остальные три сквозные правки курьерки флаг тоже не читают
 * (см. спека раздел 5).
 */
class DeliveryPriceRangesModuleTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const MUTATION = '
        mutation (
            $id: Int
            $shop_id: Int
            $delivery_price: Int
            $min_order_price: Int
            $free_delivery_from: Int
            $delivery_price_ranges: [ShopZoneDeliveryPriceRangeInput!]
        ) {
            ShopZoneCreteOrUpdate(
                id: $id
                shop_id: $shop_id
                delivery_price: $delivery_price
                min_order_price: $min_order_price
                free_delivery_from: $free_delivery_from
                delivery_price_ranges: $delivery_price_ranges
            ) {
                code
                message
            }
        }
    ';

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function setModule(bool $enabled): void
    {
        $raw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $modules = $raw ? (json_decode($raw, true) ?: []) : [];
        $modules[ModuleConfigService::DELIVERY_PRICE_RANGES_KEY] = [
            'label' => 'Диапазоны стоимости доставки',
            'enabled' => $enabled,
        ];

        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode($modules, JSON_UNESCAPED_UNICODE),
            'json'
        );
        ModuleConfigService::invalidateCache();
    }

    private function admin(): User
    {
        return User::whereHas('roles', fn ($query) => $query->where('name', \App\Models\Role::ADMIN))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function shop(): Shop
    {
        return Shop::create([
            'title' => 'MID-280 module shop ' . uniqid(),
            'status_id' => Status::CODE_ACTIVE,
        ]);
    }

    private function zone(Shop $shop, array $extra = []): ShopZone
    {
        return ShopZone::create(array_merge([
            'shop_id' => $shop->id,
            'name' => 'MID-280 module ' . uniqid(),
            'delivery_price' => 200,
            'min_order_price' => 500,
            'delivery_type' => 'own',
            'is_active' => true,
        ], $extra));
    }

    // --- Дефолты ---

    public function test_module_present_in_defaults_and_disabled(): void
    {
        AdminSetting::where('key', ModuleConfigService::PROJECT_MODULES_KEY)->delete();

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertArrayHasKey('delivery_price_ranges', $decoded);
        $this->assertFalse($decoded['delivery_price_ranges']['enabled']);
        $this->assertSame('Диапазоны стоимости доставки', $decoded['delivery_price_ranges']['label']);
        $this->assertSame(
            'Стоимость доставки зависит от суммы заказа: у зоны задаются диапазоны «от суммы: цена».',
            $decoded['delivery_price_ranges']['description']
        );
    }

    public function test_module_merged_into_stored_blob_without_it(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([
            'bonuses' => ['label' => 'Бонусная система', 'enabled' => true],
        ]), 'json');

        $decoded = json_decode((new AdminSettingQueries())->projectModules(), true);

        $this->assertTrue($decoded['bonuses']['enabled']);
        $this->assertArrayHasKey('delivery_price_ranges', $decoded);
        $this->assertFalse($decoded['delivery_price_ranges']['enabled']);
    }

    // --- Миграция ---

    public function test_migration_added_module_disabled_by_default(): void
    {
        $modules = json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true);

        $this->assertArrayHasKey('delivery_price_ranges', $modules);
        $this->assertFalse($modules['delivery_price_ranges']['enabled']);
        $this->assertSame('Диапазоны стоимости доставки', $modules['delivery_price_ranges']['label']);
        $this->assertSame(
            'Стоимость доставки зависит от суммы заказа: у зоны задаются диапазоны «от суммы: цена».',
            $modules['delivery_price_ranges']['description']
        );
    }

    public function test_migration_is_idempotent_and_does_not_override_saved_value(): void
    {
        // Модуль уже включён (пользователь успел его переключить) — повторный
        // прогон миграции (например, на другом форке) не должен его выключать.
        $this->setModule(true);

        $migration = require database_path('migrations/2026_09_23_000001_add_delivery_price_ranges_project_module.php');
        $migration->up();

        $modules = json_decode((string) AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY), true);
        $this->assertTrue($modules['delivery_price_ranges']['enabled']);
    }

    // --- ModuleConfigService::isDeliveryPriceRangesEnabled() ---

    public function test_is_delivery_price_ranges_enabled_reads_and_caches_blob(): void
    {
        $this->setModule(true);
        $this->assertTrue(ModuleConfigService::isDeliveryPriceRangesEnabled());

        // Меняем блоб в обход invalidateCache() — читатель обязан отдать закэшированное значение.
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([
            'delivery_price_ranges' => ['label' => 'Диапазоны стоимости доставки', 'enabled' => false],
        ]), 'json');
        $this->assertTrue(ModuleConfigService::isDeliveryPriceRangesEnabled());

        ModuleConfigService::invalidateCache();
        $this->assertFalse(ModuleConfigService::isDeliveryPriceRangesEnabled());
    }

    public function test_is_delivery_price_ranges_disabled_when_module_missing(): void
    {
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode([]), 'json');
        ModuleConfigService::invalidateCache();

        $this->assertFalse(ModuleConfigService::isDeliveryPriceRangesEnabled());
    }

    // --- Гейт в мутации зоны ---

    public function test_mutation_with_module_disabled_does_not_save_ranges_and_applies_free_delivery_from_argument_directly(): void
    {
        $this->setModule(false);

        $shop = $this->shop();
        $zone = $this->zone($shop);

        $response = $this->actingAs($this->admin())->graphQL(self::MUTATION, [
            'id' => $zone->id,
            'shop_id' => $shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 1500,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $response->assertJsonPath('data.ShopZoneCreteOrUpdate.code', 200);

        $fresh = $zone->fresh();
        $this->assertSame([], $fresh->deliveryPriceRangesList());
        // free_delivery_from — аргумент мутации применяется напрямую, как до фичи.
        $this->assertSame(1500, $fresh->free_delivery_from);
    }

    public function test_mutation_with_module_disabled_does_not_delete_ranges_saved_before_disabling(): void
    {
        $this->setModule(true);

        $shop = $this->shop();
        $zone = $this->zone($shop, [
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]);

        $this->setModule(false);

        $response = $this->actingAs($this->admin())->graphQL(self::MUTATION, [
            'id' => $zone->id,
            'shop_id' => $shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 500,
            'delivery_price_ranges' => [],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));

        $fresh = $zone->fresh();
        // Ступени, сохранённые до выключения, из shop_zones не удаляются.
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $fresh->deliveryPriceRangesList());
        $this->assertSame(500, $fresh->free_delivery_from);
    }

    public function test_mutation_with_module_enabled_behaves_as_in_task_4(): void
    {
        $this->setModule(true);

        $shop = $this->shop();
        $zone = $this->zone($shop);

        $response = $this->actingAs($this->admin())->graphQL(self::MUTATION, [
            'id' => $zone->id,
            'shop_id' => $shop->id,
            'delivery_price' => 200,
            'min_order_price' => 500,
            'free_delivery_from' => 5000, // игнорируется: порог вычисляется из ступеней
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $this->assertNull($response->json('errors'), json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));

        $fresh = $zone->fresh();
        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $fresh->deliveryPriceRangesList());
        $this->assertSame(3000, $fresh->free_delivery_from);
    }

    // --- Событие магазина: флаг НЕ читает (фикс-раунд 1) ---
    //
    // Решение координатора: гейт в ShopEventTransformer убран. Если событие при
    // выключенном модуле несёт [], витрина обнуляет stores.delivery_price_ranges,
    // и при обратном включении модуля ступени не вернутся, пока каждую зону не
    // пересохранят вручную. Событие несёт ступени основной зоны ВСЕГДА (как в
    // задаче 5), гейт остаётся только на чтении — на витрине.

    public function test_shop_event_carries_ranges_when_module_disabled(): void
    {
        $this->setModule(false);

        $shop = $this->shop();
        $this->zone($shop, [
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $payload['delivery_price_ranges']);
    }

    public function test_shop_event_carries_ranges_when_module_enabled(): void
    {
        $this->setModule(true);

        $shop = $this->shop();
        $this->zone($shop, [
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $payload['delivery_price_ranges']);
    }
}
