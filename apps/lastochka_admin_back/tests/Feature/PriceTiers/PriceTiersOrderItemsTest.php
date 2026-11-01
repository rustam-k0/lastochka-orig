<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Aggregator\Events\OrderPlaced;
use App\Aggregator\Services\OrderAggregatorSplitter;
use App\Models\AdminSetting;
use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Services\ModuleConfigService;
use App\Transformers\OrderEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * Снапшот лесенки «цены от количества» в позиции заказа (MID-308): приём из
 * события заказа через array_key_exists, наследование подзаказами обоих
 * сплитов, запись обычной цены (base_price снапшота) в prices.value вместо
 * цены сработавшей ступени.
 *
 * Ничто здесь не зависит от флага модуля: ниже по потоку решает наличие
 * снапшота у позиции (спека, раздел 5 «Что гейтит флаг»). Флаг читают четыре
 * сервиса с разным кэшем, и заказ, оформленный со ступенью, обязан досчитаться
 * одинаково, даже если в admin_back флаг ещё выключен. Поэтому setUp флаг
 * выключает, а отдельный тест проверяет то же самое при включённом.
 *
 * Снапшоты сравниваются через assertEquals: MySQL JSON нормализует порядок
 * ключей объекта, assertSame на ассоциативном массиве упал бы на порядке.
 */
class PriceTiersOrderItemsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithPriceTiers;

    private const SNAPSHOT = [
        'base_price' => 279,
        'base_price_old' => null,
        'base_is_eligible' => true,
        'tiers' => [
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ],
    ];

    /**
     * Межсервисный сигнал «снапшот снят» (общий контракт, раздел 3): не то же
     * самое, что ключ price_tiers = null (полное удаление, уже покрыто выше) —
     * это по-прежнему валидный снапшот, только без ступеней.
     */
    private const EMPTY_SNAPSHOT = [
        'base_price' => 279,
        'base_price_old' => null,
        'base_is_eligible' => true,
        'tiers' => [],
    ];

    private OrderEventTransformer $transformer;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();
        $this->shop = $this->makeShop();
        $this->setPriceTiersModule(false);
    }

    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function makeShop(): Shop
    {
        return ShopFactory::new()->create([
            'code' => 'tier-order-' . uniqid(),
            'title' => 'Tier Order Shop',
            'commission' => 0,
            'deposit' => 0,
            'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30,
            'slot_max_orders' => 10,
            'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6,
            'latitude' => 55.7,
        ]);
    }

    private function makeProduct(Shop $shop, string $externalId): Product
    {
        $product = Product::withoutEvents(fn () => Product::create([
            'title' => 'Товар ' . $externalId . ' ' . uniqid(),
            'articul' => 'art-' . uniqid(),
            'shop_id' => $shop->id,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
        ]));

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'source' => 'storefront',
            'external_id' => $externalId,
            'shop_code' => $shop->code,
            'entity_id' => $product->id,
            'last_event_timestamp' => now(),
        ]);

        return $product;
    }

    private function makeOrder(?Shop $shop = null): Order
    {
        $client = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'tier-order-' . uniqid(),
            'client_id' => $client->id,
            'shop_id' => ($shop ?? $this->shop)->id,
            'type_id' => Type::DELIVERY_COURIER,
            'status_id' => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount' => 995,
            'origin_amount' => 995,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
    }

    /** @return array<int, int> */
    private function processItems(Order $order, array $items): array
    {
        $method = new \ReflectionMethod($this->transformer, 'processOrderItems');
        $method->setAccessible(true);

        return $method->invoke($this->transformer, $order, $items, $this->shop->code);
    }

    /** @return array<string, mixed> */
    private function eventItem(string $lineId, string $productExternalId, array $overrides = []): array
    {
        return array_merge([
            'id' => $lineId,
            'source' => 'storefront',
            'product_id' => $productExternalId,
            'product_source' => 'storefront',
            'product_name' => 'Товар ' . $productExternalId,
            'quantity' => 5,
            'unit_price' => 199,
            'unit_price_effective' => 199,
            'unit_price_old' => 279,
            'is_eligible' => false,
            'line_total' => 995,
        ], $overrides);
    }

    private function priceValue(Product $product): float
    {
        return (float) Price::where('product_id', $product->id)
            ->where('shop_id', $this->shop->id)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->value('value');
    }

    public function test_new_item_stores_snapshot_as_is(): void
    {
        $product = $this->makeProduct($this->shop, 'EXT-' . uniqid());
        $external = EventSyncMetadata::where('entity_id', $product->id)->where('entity_type', 'products')->value('external_id');
        $order = $this->makeOrder();

        [$itemId] = $this->processItems($order, [
            $this->eventItem('L-' . uniqid(), $external, ['price_tiers' => self::SNAPSHOT]),
        ]);

        $item = OrderItem::findOrFail($itemId);

        $this->assertEquals(self::SNAPSHOT, $item->price_tiers);
        $this->assertEquals(199.0, (float) $item->price);
    }

    public function test_update_keeps_snapshot_without_key_replaces_with_key_and_clears_on_null(): void
    {
        $external = 'EXT-' . uniqid();
        $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();
        $lineId = 'L-' . uniqid();

        [$itemId] = $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::SNAPSHOT])]);

        // Эхо сервиса общей БД: ключа нет — снапшот остаётся.
        $this->processItems($order, [$this->eventItem($lineId, $external, ['quantity' => 3, 'unit_price' => 249])]);
        $this->assertEquals(self::SNAPSHOT, OrderItem::findOrFail($itemId)->price_tiers);

        // Ключ пришёл — полная замена.
        $changed = array_merge(self::SNAPSHOT, ['base_price' => 289]);
        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => $changed])]);
        $this->assertEquals($changed, OrderItem::findOrFail($itemId)->price_tiers);

        // Ключ пришёл с null — снапшот снят.
        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => null])]);
        $this->assertNull(OrderItem::findOrFail($itemId)->price_tiers);
    }

    public function test_product_price_gets_base_price_of_snapshot_not_tier_price(): void
    {
        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();
        $lineId = 'L-' . uniqid();

        // Строки цены ещё нет: ветка create.
        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::SNAPSHOT])]);
        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);

        // Строка цены есть: ветка update.
        Price::where('product_id', $product->id)->update(['value' => 300]);
        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::SNAPSHOT])]);
        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    public function test_echo_without_key_uses_snapshot_of_stored_item(): void
    {
        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();
        $lineId = 'L-' . uniqid();

        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::SNAPSHOT])]);

        // После сборки: 3 шт по 249, ключа price_tiers в событии нет.
        $this->processItems($order, [$this->eventItem($lineId, $external, ['quantity' => 3, 'unit_price' => 249])]);

        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    public function test_item_without_snapshot_keeps_legacy_unit_price(): void
    {
        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();

        [$itemId] = $this->processItems($order, [$this->eventItem('L-' . uniqid(), $external)]);

        $this->assertNull(OrderItem::findOrFail($itemId)->price_tiers);
        $this->assertEqualsWithDelta(199.0, $this->priceValue($product), 0.001);
    }

    public function test_enabled_flag_changes_nothing_for_snapshot_handling(): void
    {
        $this->setPriceTiersModule(true);

        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();

        [$itemId] = $this->processItems($order, [
            $this->eventItem('L-' . uniqid(), $external, ['price_tiers' => self::SNAPSHOT]),
        ]);

        $this->assertEquals(self::SNAPSHOT, OrderItem::findOrFail($itemId)->price_tiers);
        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    public function test_flag_mismatch_never_writes_tier_price_into_product_price(): void
    {
        // Рассинхрон флагов: витрина уже оформила заказ со ступенью, а в
        // admin_back модуль выключен (setUp). В prices.value обязана лечь
        // обычная цена снапшота, не цена ступени.
        $this->assertFalse(ModuleConfigService::isPriceTiersEnabled());

        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        Price::create([
            'product_id' => $product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 279,
            'step' => 1,
        ]);
        $order = $this->makeOrder();

        [$itemId] = $this->processItems($order, [
            $this->eventItem('L-' . uniqid(), $external, ['price_tiers' => self::SNAPSHOT]),
        ]);

        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
        $this->assertEquals(self::SNAPSHOT, OrderItem::findOrFail($itemId)->price_tiers);
    }

    /**
     * F3 финального ревью: снапшот-пустышка {"tiers": []} — валидный снапшот,
     * не NULL. Ей передан отдельный тест: array_key_exists с price_tiers = []
     * должен сохранить объект как есть, а не превратиться в отсутствие снапшота.
     */
    public function test_empty_snapshot_placeholder_is_stored_as_is_not_null(): void
    {
        $product = $this->makeProduct($this->shop, 'EXT-' . uniqid());
        $external = EventSyncMetadata::where('entity_id', $product->id)->where('entity_type', 'products')->value('external_id');
        $order = $this->makeOrder();

        [$itemId] = $this->processItems($order, [
            $this->eventItem('L-' . uniqid(), $external, [
                'price_tiers' => self::EMPTY_SNAPSHOT,
                'unit_price' => 279,
                'unit_price_old' => null,
                'is_eligible' => true,
            ]),
        ]);

        $item = OrderItem::findOrFail($itemId);

        $this->assertNotNull($item->price_tiers, 'снапшот-пустышка — валидный снапшот, а не NULL');
        $this->assertEquals(self::EMPTY_SNAPSHOT, $item->price_tiers);
    }

    /**
     * F3: prices.value читает base_price снапшота-пустышки, а не unit_price
     * позиции (в unit_price могла остаться цена ступени, которая только что
     * снята — например, товар переехал в магазин без лесенки).
     */
    public function test_empty_snapshot_placeholder_price_uses_base_price_not_unit_price(): void
    {
        $external = 'EXT-' . uniqid();
        $product = $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();
        $lineId = 'L-' . uniqid();

        $this->processItems($order, [
            $this->eventItem($lineId, $external, [
                'price_tiers' => self::EMPTY_SNAPSHOT, // base_price = 279
                'unit_price' => 150, // цена сработавшей ступени до снятия — не должна попасть в prices.value
            ]),
        ]);

        $this->assertEqualsWithDelta(279.0, $this->priceValue($product), 0.001);
    }

    /**
     * F3: пустышка перезаписывает ранее сохранённый полноценный снапшот той же
     * позиции — ключ пришёл, значит полная замена (правило одно для всех
     * снапшотов, общий контракт раздел 3).
     */
    public function test_empty_snapshot_placeholder_overwrites_full_snapshot(): void
    {
        $external = 'EXT-' . uniqid();
        $this->makeProduct($this->shop, $external);
        $order = $this->makeOrder();
        $lineId = 'L-' . uniqid();

        [$itemId] = $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::SNAPSHOT])]);
        $this->assertEquals(self::SNAPSHOT, OrderItem::findOrFail($itemId)->price_tiers);

        $this->processItems($order, [$this->eventItem($lineId, $external, ['price_tiers' => self::EMPTY_SNAPSHOT])]);

        $this->assertEquals(self::EMPTY_SNAPSHOT, OrderItem::findOrFail($itemId)->price_tiers);
    }

    /**
     * MID-308 (переиздание заказа 93 на dev, `rabbitmq:sync-entity orders
     * --ids=93` без event_source): служебная строка `<id>_origin` (товар
     * P1, item_status=replaced, без ключа price_tiers) не должна писать
     * цену товара — ни цену сработавшей ступени, ни какую-либо другую.
     *
     * Раньше проверка isReplacedOriginRow() стояла ПОСЛЕ блока «создать/
     * обновить цену товара»: у строки _origin unit_price — цена ступени
     * позиции-замены на момент заказа (220), а запасной поиск снапшота
     * firstWhere('product_id', P1) среди позиций заказа ничего не находит
     * (позиция хранит товар-замену P2), поэтому catalogPriceForItem()
     * возвращал 220 и обычная цена P1 в магазине опускалась с 300 до 220.
     *
     * Позиция-замена (товар P2) при этом обрабатывается как обычно: цена
     * товара в магазине = base_price её снапшота (1900). Новых товаров-
     * заглушек не создаётся ни для P1, ни для строки _origin (она не
     * позиция заказа — пропускается целиком, до резолва/создания товара).
     */
    public function test_replaced_origin_row_does_not_write_product_price(): void
    {
        $externalP1 = 'EXT-P1-' . uniqid();
        $externalP2 = 'EXT-P2-' . uniqid();
        $productP1 = $this->makeProduct($this->shop, $externalP1);
        $productP2 = $this->makeProduct($this->shop, $externalP2);

        Price::create([
            'product_id' => $productP1->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 300,
            'step' => 1,
        ]);

        $order = $this->makeOrder();
        $productsBefore = Product::count();
        $lineId = 'L-' . uniqid();

        $this->processItems($order, [
            [
                'id' => $lineId . '_origin',
                'source' => 'storefront',
                'product_id' => $externalP1,
                'product_source' => 'storefront',
                'product_name' => 'Товар ' . $externalP1,
                'quantity' => 5,
                'unit_price' => 220,
                'item_status' => 'replaced',
            ],
            $this->eventItem($lineId, $externalP2, [
                'unit_price' => 190,
                'unit_price_effective' => 190,
                'price_tiers' => [
                    'base_price' => 1900,
                    'base_price_old' => null,
                    'base_is_eligible' => true,
                    'tiers' => [['min_quantity' => 5, 'price' => 190]],
                ],
            ]),
        ]);

        $this->assertEqualsWithDelta(300.0, $this->priceValue($productP1), 0.001, 'служебная строка _origin не пишет цену товара-оригинала');
        $this->assertEqualsWithDelta(1900.0, $this->priceValue($productP2), 0.001, 'позиция-замена пишет обычную цену снапшота (base_price)');
        $this->assertSame($productsBefore, Product::count(), 'из служебной строки новый товар-заглушка не создаётся');
    }

    public function test_zone_split_children_inherit_snapshot(): void
    {
        $raw = AdminSetting::getValue(ModuleConfigService::PROJECT_MODULES_KEY);
        $modules = json_decode((string) $raw, true) ?: [];
        $modules['assembly_zones'] = ['label' => 'Зоны сборки', 'enabled' => true];
        AdminSetting::setValue(ModuleConfigService::PROJECT_MODULES_KEY, json_encode($modules, JSON_UNESCAPED_UNICODE), 'json');

        $zone = AssemblyZone::create(['title' => 'Зона ' . uniqid(), 'color' => '#aabbcc', 'is_active' => true, 'position' => 1]);
        $category = Category::create(['title' => 'Cat-' . uniqid(), 'assembly_zone_id' => $zone->id, 'is_active' => true]);

        $withTiers = $this->makeProduct($this->shop, 'EXT-' . uniqid());
        $withTiers->categories()->attach($category->id);
        $plain = $this->makeProduct($this->shop, 'EXT-' . uniqid());

        $order = $this->makeOrder();
        OrderItem::create(['order_id' => $order->id, 'product_id' => $withTiers->id, 'quantity' => 5, 'price' => 199, 'price_tiers' => self::SNAPSHOT]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $plain->id, 'quantity' => 1, 'price' => 100]);

        $split = new \ReflectionMethod($this->transformer, 'splitOrderByZones');
        $split->setAccessible(true);
        $split->invoke($this->transformer, $order);

        $childIds = Order::where('parent_order_id', $order->id)->pluck('id');
        $this->assertCount(2, $childIds);

        $copyWithTiers = OrderItem::whereIn('order_id', $childIds)->where('product_id', $withTiers->id)->firstOrFail();
        $copyPlain = OrderItem::whereIn('order_id', $childIds)->where('product_id', $plain->id)->firstOrFail();

        $this->assertEquals(self::SNAPSHOT, $copyWithTiers->price_tiers);
        $this->assertNull($copyPlain->price_tiers);
    }

    public function test_aggregator_split_children_keep_snapshot(): void
    {
        $shopB = $this->makeShop();
        $productA = $this->makeProduct($this->shop, 'EXT-' . uniqid());
        $productB = $this->makeProduct($shopB, 'EXT-' . uniqid());

        $order = Order::create([
            'uuid' => (string) random_int(100000, 999999),
            'client_id' => Client::create(['phone' => '7' . random_int(1000000000, 9999999999), 'is_authorized' => true])->id,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
        $itemA = OrderItem::create(['order_id' => $order->id, 'product_id' => $productA->id, 'quantity' => 5, 'price' => 199, 'price_tiers' => self::SNAPSHOT]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $productB->id, 'quantity' => 1, 'price' => 100]);

        (new OrderAggregatorSplitter())->handle(new OrderPlaced($order->refresh()));

        $moved = OrderItem::findOrFail($itemA->id);
        $child = Order::findOrFail($moved->order_id);

        $this->assertSame($order->id, $child->parent_order_id, 'позиция переехала в подзаказ магазина');
        $this->assertEquals(self::SNAPSHOT, $moved->price_tiers);
    }
}
