<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Исходящее событие заказа admin_back несёт снапшот лесенки «цены от
 * количества» позиции и цену доставленной ступени после возврата у двери
 * (MID-308, дефект с e2e).
 *
 * Таблица event_outbox одна на admin_back/delivery_back/purchase_back
 * (общая БД mendev_djami). У сущности orders в config/rabbitmq-events.php
 * admin_back стоит delay_seconds => 1, и при задержке воркер
 * rabbitmq:publish-outbox пересобирает payload ЭТИМ трансформером поверх
 * события, изначально записанного delivery_back/purchase_back. До фикса
 * ключи price_tiers/unit_price_old терялись (заказ 93 на dev: позиция
 * замены и добавленная сборщиком позиция ушли на витрину без снапшота, без
 * зачёркнутой цены и с eligible_for_bonus, хотя в djami is_eligible = 0), а
 * возврат части штучной позиции у двери слал цену за единицу по старой
 * формуле вместо цены сработавшей ступени доставленного количества.
 */
class PriceTiersOrderEventOutboundTest extends TestCase
{
    use DatabaseTransactions;

    private const SNAPSHOT = [
        'base_price' => 279,
        'base_price_old' => null,
        'base_is_eligible' => true,
        'tiers' => [
            ['min_quantity' => 1, 'price' => 279],
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ],
    ];

    /**
     * Снапшот-пустышка «снапшот снят» (общий контракт MID-308): валидный
     * снапшот без ступеней, не то же самое, что price_tiers = NULL.
     */
    private const EMPTY_SNAPSHOT = [
        'base_price' => 279,
        'base_price_old' => null,
        'base_is_eligible' => true,
        'tiers' => [],
    ];

    private function makeOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'tier-outbound-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_FINISHED,
            'payment_type_id' => 2,
            'type_id' => 2,
            'amount' => 995,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
    }

    private function makeProduct(?int $infoWeight, int $infoWeightTypeId): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Tier outbound product ' . uniqid(),
            'articul' => 'TO-' . uniqid(),
            'unit_type_id' => 1,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addItem(int $orderId, int $productId, array $attrs): int
    {
        if (array_key_exists('price_tiers', $attrs) && $attrs['price_tiers'] !== null) {
            $attrs['price_tiers'] = json_encode($attrs['price_tiers'], JSON_UNESCAPED_UNICODE);
        }

        return \DB::table('order_items')->insertGetId(array_merge([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'price' => 0,
            'price_during_order' => 0,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function events(Order $order): array
    {
        return app(OrderEventTransformer::class)->toEvent(
            $order->fresh()->load(['orderItems.product', 'orderItems.originProduct'])
        )['items'];
    }

    private function eventItem(Order $order, int $itemId): array
    {
        $item = collect($this->events($order))->firstWhere('id', (string) $itemId);
        $this->assertNotNull($item, 'позиция не попала в событие');

        return $item;
    }

    /** @test */
    public function regular_item_with_snapshot_carries_price_tiers_and_unit_price_old()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 5,
            'price' => 995,
            'price_during_order' => 199,
            'price_old' => 279,
            'price_tiers' => self::SNAPSHOT,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertEquals(self::SNAPSHOT, $item['price_tiers']);
        $this->assertSame(279.0, $item['unit_price_old']);
    }

    /** @test */
    public function item_without_snapshot_has_no_price_tiers_or_unit_price_old_key_and_fixed_key_set()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 3,
            'price' => 300,
            'price_during_order' => 100,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertArrayNotHasKey('price_tiers', $item);
        $this->assertArrayNotHasKey('unit_price_old', $item);

        $this->assertSame([
            'id',
            'source',
            'product_id',
            'product_source',
            'product_name',
            'quantity',
            'unit_price',
            'unit_price_effective',
            'line_subtotal',
            'line_discount_total',
            'line_total',
            'item_status',
            'returned_quantity',
            // Размер порции в г/мл/шт — для выгрузки невзвешенной весовой позиции в 1С
            'portion_size',
        ], array_keys($item));
    }

    /** @test */
    public function empty_snapshot_placeholder_carries_price_tiers_without_unit_price_old()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 1,
            'price' => 279,
            'price_during_order' => 279,
            'price_tiers' => self::EMPTY_SNAPSHOT,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertEquals(self::EMPTY_SNAPSHOT, $item['price_tiers']);
        $this->assertArrayNotHasKey('unit_price_old', $item);
    }

    /** @test */
    public function replacement_item_carries_price_tiers_of_replacement_product_origin_item_has_no_key()
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'origin_product_id' => $originProductId,
            'origin_price' => 100,
            'origin_quantity' => 2,
            'quantity' => 2,
            'price' => 498,
            'price_during_order' => 249,
            'price_old' => 279,
            'price_tiers' => self::SNAPSHOT,
        ]);

        $events = collect($this->events($order));
        $origin = $events->firstWhere('id', $itemId . '_origin');
        $replacement = $events->firstWhere('id', (string) $itemId);

        $this->assertNotNull($origin, 'оригинальная позиция не попала в событие');
        $this->assertNotNull($replacement, 'позиция замены не попала в событие');

        $this->assertArrayNotHasKey('price_tiers', $origin);
        $this->assertEquals(self::SNAPSHOT, $replacement['price_tiers']);
    }

    /** @test */
    public function partial_return_two_of_five_piece_item_uses_tier_price_of_delivered_quantity()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        // Заказано 5 по 199 (сработала ступень 5), у двери вернули 2 — доставлено 3.
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 5,
            'price' => 995,
            'price_during_order' => 199,
            'price_old' => 279,
            'price_tiers' => self::SNAPSHOT,
            'returned_quantity' => 2,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(3, $item['quantity']);
        $this->assertSame(249.0, $item['unit_price']);
        $this->assertSame(747.0, $item['line_total']);
        $this->assertSame(279.0, $item['unit_price_old']);
    }

    /** @test */
    public function partial_return_four_of_five_piece_item_falls_back_to_base_tier_without_old_price()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        // Доставлена всего 1 порция — тариф базовой ступени, скидки нет.
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 5,
            'price' => 995,
            'price_during_order' => 199,
            'price_old' => 279,
            'price_tiers' => self::SNAPSHOT,
            'returned_quantity' => 4,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(1, $item['quantity']);
        $this->assertSame(279.0, $item['unit_price']);
        $this->assertSame(279.0, $item['line_total']);
        $this->assertArrayHasKey('unit_price_old', $item);
        $this->assertNull($item['unit_price_old']);
    }

    /** @test */
    public function full_return_five_of_five_piece_item_has_zero_line_total_without_exceptions()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 5,
            'price' => 995,
            'price_during_order' => 199,
            'price_old' => 279,
            'price_tiers' => self::SNAPSHOT,
            'returned_quantity' => 5,
        ]);

        $item = $this->eventItem($order, $itemId);

        $this->assertSame(0, $item['quantity']);
        $this->assertSame(0.0, $item['line_total']);
    }

    /** @test */
    public function weighted_item_with_snapshot_and_return_keeps_zero_behaviour_but_carries_price_tiers()
    {
        $order = $this->makeOrder();
        $productId = $this->makeProduct(500, Type::INFO_WEIGHT_GRAM);
        $itemId = $this->addItem($order->id, $productId, [
            'quantity' => 1,
            'price' => 42,
            'price_during_order' => 42,
            'price_old' => 50,
            'price_tiers' => self::SNAPSHOT,
            'actual_weight' => 500,
            'returned_quantity' => 1,
        ]);

        $item = $this->eventItem($order, $itemId);

        // Весовая позиция возвращается только целиком: поведение метода
        // пользователя (deliveredAfterCourierReturn) не меняется, котировку
        // по ступени для неё не считаем.
        $this->assertSame(0, $item['quantity']);
        $this->assertSame(0.0, $item['line_total']);
        $this->assertSame(0, $item['actual_weight']);
        $this->assertEquals(self::SNAPSHOT, $item['price_tiers']);
        // Котировка не считалась: зачёркнутая цена остаётся из позиции, как в delivery_back
        $this->assertSame(50.0, $item['unit_price_old']);
    }
}
