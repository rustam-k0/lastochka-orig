<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOneCShopsMaster;
use Tests\TestCase;

/**
 * MID-306: статус, контакты, площадь, описание и порог тяжёлого заказа магазина из 1С.
 */
class ShopOneCNewKeysTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCShopsMaster;

    private string $guid;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guid = (string) Str::uuid();
    }

    private function send(array $overrides, string $eventType = 'updated'): void
    {
        $this->minute--;
        $result = (new EventConsumer('shops', config('rabbitmq-events.entities.shops')))->processEvent(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => $this->guid,
            'shop_code' => $this->guid,
            'source' => '1c',
            'event_source' => '1c',
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function shop(): Shop
    {
        return Shop::query()->where('code', $this->guid)->firstOrFail();
    }

    public function test_active_sets_status(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Статус', 'active' => false], 'created');
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $this->shop()->status_id);

        $this->send(['active' => true]);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);

        $this->send(['active' => null]);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);

        $this->send(['active' => 'может быть']);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);

        $this->send(['active' => '']);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);

        $this->send(['active' => 'no']);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);

        $this->send(['active' => '0']);
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $this->shop()->status_id);

        $this->send(['active' => 'true']);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->shop()->status_id);
    }

    public function test_contacts_area_and_description(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send([
            'title' => 'Контакты',
            'phone' => ' +7 (391) 200-00-00 ',
            'email' => 'shop@razgulay.ru',
            'area_sqm' => '120,5',
            'description' => 'Алкомаркет у дома',
        ], 'created');

        $shop = $this->shop();
        $this->assertSame('+7 (391) 200-00-00', $shop->phone);
        $this->assertSame('shop@razgulay.ru', $shop->email);
        $this->assertSame('120.50', (string) $shop->area_sqm);
        $this->assertSame('Алкомаркет у дома', $shop->description);

        $this->send(['email' => 'не почта', 'area_sqm' => 'большой', 'phone' => '', 'description' => null]);

        $shop = $this->shop();
        $this->assertSame('shop@razgulay.ru', $shop->email);
        $this->assertSame('120.50', (string) $shop->area_sqm);
        $this->assertNull($shop->phone);
        $this->assertNull($shop->description);

        $this->send(['area_sqm' => -5]);
        $this->assertSame('120.50', (string) $this->shop()->area_sqm);

        $this->send(['email' => null, 'area_sqm' => null]);
        $this->assertNull($this->shop()->email);
        $this->assertNull($this->shop()->area_sqm);
    }

    public function test_heavy_order_threshold_kg_is_stored_in_grams(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Тяжёлый', 'heavy_order_threshold_kg' => '25,5'], 'created');
        $this->assertSame(25500, (int) $this->shop()->heavy_order_threshold);

        $this->send(['heavy_order_threshold_kg' => -1]);
        $this->assertSame(25500, (int) $this->shop()->heavy_order_threshold);

        $this->send(['heavy_order_threshold_kg' => 'много']);
        $this->assertSame(25500, (int) $this->shop()->heavy_order_threshold);

        $this->send(['heavy_order_threshold_kg' => null]);
        $this->assertNull($this->shop()->heavy_order_threshold);
    }

    public function test_type_is_not_read(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Тип', 'type' => 'restaurant'], 'created');

        $this->assertSame(Type::SHOP_TYPE_STORE, (int) $this->shop()->type_id);
    }

    public function test_is_default_without_city_is_ignored(): void
    {
        $this->setOneCShopsMaster(true);
        $this->send(['title' => 'Без города', 'is_default' => true, 'address' => 'ул. Ленина, д. 5'], 'created');

        $this->assertFalse((bool) $this->shop()->is_default);
    }

    public function test_flag_off_new_keys_are_not_read(): void
    {
        $this->setOneCShopsMaster(false);
        $this->send([
            'title' => 'Старый путь',
            'active' => false,
            'phone' => '+73912000000',
            'heavy_order_threshold_kg' => 10,
        ], 'created');

        $shop = $this->shop();
        $this->assertSame(Status::CODE_ACTIVE, (int) $shop->status_id);
        $this->assertNull($shop->phone);
        $this->assertNull($shop->heavy_order_threshold);
    }
}
