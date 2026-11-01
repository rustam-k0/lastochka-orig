<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Магазин по умолчанию в городе (MENDEV-10): не более одного на город.
 */
class DefaultShopTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::whereHas('roles', function ($q) {
            $q->where('name', Role::ADMIN);
        })->firstOrFail();
    }

    private function makeCity(string $name): City
    {
        return City::create([
            'name' => $name . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeShop(?City $city, bool $isDefault = false): Shop
    {
        // fresh() обязателен: у shops часть колонок NOT NULL с дефолтом на уровне БД,
        // а свежесозданная модель их не знает — fillInfo() записал бы обратно null.
        $shop = Shop::create([
            'title' => 'Магазин ' . uniqid(),
            'city_id' => $city?->id,
            'is_default' => $isDefault,
        ]);

        return $shop->fresh();
    }

    public function test_setting_default_resets_previous_default_in_same_city(): void
    {
        $city = $this->makeCity('Красноярск');
        $oldDefault = $this->makeShop($city, true);
        $newDefault = $this->makeShop($city);

        (new ShopService())->syncDefaultShop($newDefault, true);

        $this->assertTrue((bool) $newDefault->fresh()->is_default);
        $this->assertFalse((bool) $oldDefault->fresh()->is_default);
    }

    public function test_default_shop_of_another_city_is_untouched(): void
    {
        $krasnoyarsk = $this->makeCity('Красноярск');
        $moscow = $this->makeCity('Москва');

        $moscowDefault = $this->makeShop($moscow, true);
        $krasnoyarskShop = $this->makeShop($krasnoyarsk);

        (new ShopService())->syncDefaultShop($krasnoyarskShop, true);

        $this->assertTrue((bool) $moscowDefault->fresh()->is_default);
        $this->assertTrue((bool) $krasnoyarskShop->fresh()->is_default);
    }

    public function test_flag_can_be_reset(): void
    {
        $city = $this->makeCity('Красноярск');
        $shop = $this->makeShop($city, true);

        (new ShopService())->syncDefaultShop($shop, false);

        $this->assertFalse((bool) $shop->fresh()->is_default);
    }

    public function test_shop_without_city_cannot_be_default(): void
    {
        $shop = $this->makeShop(null);

        $this->expectException(ValidationException::class);

        (new ShopService())->syncDefaultShop($shop, true);
    }

    public function test_moving_default_shop_to_another_city_resets_that_city_default(): void
    {
        $from = $this->makeCity('Красноярск');
        $to = $this->makeCity('Москва');

        $moving = $this->makeShop($from, true);
        $existingDefault = $this->makeShop($to, true);

        $this->actingAs($this->adminUser);

        (new ShopService())->fillInfo($moving, [
            'title' => $moving->title,
            'city_id' => $to->id,
        ]);

        $this->assertTrue((bool) $moving->fresh()->is_default);
        $this->assertFalse((bool) $existingDefault->fresh()->is_default);
    }

    public function test_update_without_key_keeps_flag(): void
    {
        $city = $this->makeCity('Красноярск');
        $shop = $this->makeShop($city, true);

        $this->actingAs($this->adminUser);

        (new ShopService())->fillInfo($shop, [
            'title' => 'Переименованный',
        ]);

        $this->assertTrue((bool) $shop->fresh()->is_default);
    }

    public function test_event_payload_contains_is_default(): void
    {
        $city = $this->makeCity('Красноярск');
        $shop = $this->makeShop($city, true);

        $payload = (new ShopEventTransformer())->toEvent($shop);

        $this->assertTrue($payload['is_default']);
    }

    public function test_from_event_without_key_does_not_touch_flag(): void
    {
        $attributes = (new ShopEventTransformer())->fromEvent([
            'event_id' => 'e1',
            'id' => '1',
            'shop_code' => 'mendev',
            'source' => 'storefront',
            'title' => 'Магазин',
        ]);

        $this->assertArrayNotHasKey('is_default', $attributes);
    }
}
