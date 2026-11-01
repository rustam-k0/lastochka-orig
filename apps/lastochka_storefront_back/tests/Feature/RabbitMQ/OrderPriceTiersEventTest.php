<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderPriceTiersEventTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const FROM_ONE = [
        ['min_quantity' => 1, 'price' => 279],
        ['min_quantity' => 2, 'price' => 249],
        ['min_quantity' => 5, 'price' => 199],
    ];

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return PriceTierSnapshot::build(279.0, null, true, self::FROM_ONE)->toArray();
    }

    /** @return array{0: Order, 1: OrderItem, 2: Product} */
    private function orderWithTieredItem(int $quantity, float $price, ?float $priceOld, bool $eligible): array
    {
        $store = Store::factory()->create(['code' => 'TIERS_ORD']);
        $product = Product::factory()->for($store)->create(['price' => 279, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        $order = Order::factory()->for(User::factory()->create())->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => $price * $quantity,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => $price * $quantity,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Вино красное',
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => $quantity,
            'total' => $price * $quantity,
            'measurement_unit' => 'piece',
            'eligible_for_bonus' => $eligible,
            'price_tiers' => $this->snapshot(),
        ]);

        return [$order, $item, $product];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function assemblyEvent(array $items): array
    {
        return [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'TIERS_ORD',
            'status' => 'assembled',
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function eventItem(OrderItem $item, int $quantity, float $unitPrice): array
    {
        return [
            'id' => (string) $item->id,
            'source' => config('rabbitmq-events.source'),
            'product_name' => $item->title,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_price_effective' => $unitPrice,
            'line_total' => $unitPrice * $quantity,
            'item_status' => 'confirmed',
        ];
    }

    /** Позиции исходящего события order_created (тот же buildItems(), что у остальных событий заказа). */
    private function publishedItems(Order $order): array
    {
        return (new OrderEventTransformer)->toCustomEvent($order, 'order_created', [])['items'];
    }

    // ==================== Исходящее событие ====================

    public function test_outgoing_item_carries_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        [$order] = $this->orderWithTieredItem(5, 199, 279, false);

        $items = $this->publishedItems($order);

        $this->assertEquals($this->snapshot(), $items[0]['price_tiers']);
        $this->assertEquals(199.0, $items[0]['unit_price']);
        $this->assertEquals(279.0, $items[0]['unit_price_old']);
        $this->assertFalse($items[0]['is_eligible']);
    }

    public function test_outgoing_item_without_snapshot_has_no_key(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);
        $item->update(['price_tiers' => null]);

        $this->assertArrayNotHasKey('price_tiers', $this->publishedItems($order->fresh())[0]);
    }

    /**
     * Правило «флаг гейтит только создание данных»: заказ со снапшотом при уже
     * ВЫКЛЮЧЕННОМ флаге. Событие сборки с изменённым количеством пересчитывает
     * price_old / eligible_for_bonus по снапшоту, а исходящее событие такого
     * заказа по-прежнему несёт price_tiers. К таблице ступеней обращений нет.
     */
    public function test_order_with_snapshot_is_processed_by_snapshot_when_flag_is_off(): void
    {
        $this->setPriceTiersModule(false);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        $queries = $this->priceTierTableQueries(function () use ($order, $item): void {
            (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([$this->eventItem($item, 1, 279.0)]));
        });

        $item->refresh();
        $this->assertSame(1, (int) $item->quantity);
        $this->assertNull($item->price_old, 'первая ступень снапшота: зачёркнутой цены нет');
        $this->assertTrue($item->eligible_for_bonus);
        $this->assertSame(0, $queries);

        $published = $this->publishedItems($order->fresh())[0];
        $this->assertEquals($this->snapshot(), $published['price_tiers'], 'исходящее событие несёт снапшот и при выключенном флаге');
        $this->assertTrue($published['is_eligible']);
    }

    // ==================== Входящее событие после сборки ====================

    /** Сборщик положил 3 вместо 5: ступень 249, позиция остаётся скидочной. */
    public function test_assembly_5_to_3_recalculates_price_old_and_flag_by_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([$this->eventItem($item, 3, 249.0)]));

        $item->refresh();
        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame(249.0, (float) $item->price, 'цена за единицу берётся из события');
        $this->assertSame(747.0, (float) $item->total);
        $this->assertSame(279.0, (float) $item->price_old);
        $this->assertFalse($item->eligible_for_bonus);
        $this->assertEquals($this->snapshot(), $item->price_tiers, 'ключа price_tiers в событии нет: снапшот позиции не тронут');
    }

    /** Осталась 1 шт: первая ступень, позиция становится обычной, промокод начинает действовать. */
    public function test_assembly_to_first_tier_makes_item_regular_and_promocode_applies(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item, $product] = $this->orderWithTieredItem(5, 199, 279, false);

        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $order->store_id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'TIER10',
        ]);
        $order->promocode()->create([
            'original_id' => $promocode->id,
            'code' => 'TIER10',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'discount_amount' => 0,
            'created_at' => now(),
        ]);

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([$this->eventItem($item, 1, 279.0)]));

        $item->refresh();
        $this->assertSame(279.0, (float) $item->price);
        $this->assertNull($item->price_old);
        $this->assertTrue($item->eligible_for_bonus, 'признак пересчитан вместе со ступенью');

        $order->refresh();
        $this->assertSame(279.0, (float) $order->subtotal);
        $this->assertSame(27.9, (float) $order->discount, 'промокод действует на позицию первой ступени');
    }

    /**
     * Курьерка переписала снапшот существующей позиции (purchase_back OrderShopUpdate:
     * смена магазина заказа, лесенка нового магазина): снапшот перезаписывается
     * значением из события, price_old считается уже по новой лесенке.
     */
    public function test_snapshot_from_event_overwrites_existing_item_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        $newSnapshot = [
            'base_price' => 300,
            'base_price_old' => null,
            'base_is_eligible' => true,
            'tiers' => [['min_quantity' => 3, 'price' => 230]],
        ];

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 5, 230.0) + ['price_tiers' => $newSnapshot],
        ]));

        $item->refresh();
        $this->assertEquals(
            PriceTierSnapshot::fromArray($newSnapshot)->toArray(),
            $item->price_tiers,
            'снапшот перезаписан значением из события'
        );
        $this->assertSame(230.0, (float) $item->price);
        $this->assertSame(1150.0, (float) $item->total);
        $this->assertSame(300.0, (float) $item->price_old, 'опорная цена новой лесенки: обычная цена нового магазина');
        $this->assertFalse($item->eligible_for_bonus);
    }

    /**
     * Курьерка сняла снапшот (в новом магазине лесенки нет): price_tiers: null.
     * Позиция дальше ведёт себя как обычная: поля ступени сброшены, следующее
     * событие по лесенке уже ничего не пересчитывает.
     */
    public function test_null_snapshot_in_event_removes_snapshot_and_item_becomes_regular(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 5, 310.0) + ['price_tiers' => null],
        ]));

        $item->refresh();
        $this->assertNull($item->price_tiers, 'снапшот снят');
        $this->assertSame(310.0, (float) $item->price, 'цена и сумма строки берутся из события');
        $this->assertSame(1550.0, (float) $item->total);
        $this->assertNull($item->price_old, 'зачёркнутая цена ступени сброшена: у товара скидки нет');
        $this->assertTrue($item->eligible_for_bonus);
        $this->assertFalse(app(\App\Services\Discount\ItemDiscountResolver::class)->hasDiscount($item->load('product')));

        // Следующее событие (2 шт): лесенки у позиции больше нет, ступень «от 2» не применяется.
        (new OrderEventTransformer)->afterUpdate($order->fresh(), $this->assemblyEvent([$this->eventItem($item, 2, 310.0)]));

        $item->refresh();
        $this->assertNull($item->price_tiers);
        $this->assertNull($item->price_old);
        $this->assertTrue($item->eligible_for_bonus);
        $this->assertSame(620.0, (float) $item->total);
    }

    public function test_price_mismatch_with_own_quote_is_logged(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([$this->eventItem($item, 3, 199.0)]));

        $this->assertSame(199.0, (float) $item->fresh()->price, 'источник цены после сборки: событие');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'price_tiers'))
            ->once();
    }

    public function test_item_without_snapshot_is_synced_as_before(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);
        $item->update(['price_tiers' => null]);

        (new OrderEventTransformer)->afterUpdate($order->fresh(), $this->assemblyEvent([$this->eventItem($item, 1, 279.0)]));

        $item->refresh();
        $this->assertSame(279.0, (float) $item->price_old, 'без снапшота price_old не пересчитывается');
        $this->assertFalse($item->eligible_for_bonus);
    }

    // ==================== Позиция, созданная курьеркой ====================

    /**
     * Сохранение снапшота зависит только от наличия ключа price_tiers в событии,
     * не от локального флага: тест намеренно идёт при ВЫКЛЮЧЕННОМ модуле витрины.
     */
    public function test_item_added_by_collector_keeps_snapshot_from_event(): void
    {
        $this->setPriceTiersModule(false);
        [$order, $item, $product] = $this->orderWithTieredItem(1, 279, null, true);
        $added = Product::factory()->for($order->store)->create(['title' => 'Сыр твёрдый', 'price' => 500, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        $courierSnapshot = [
            'base_price' => 500,
            'base_price_old' => null,
            'base_is_eligible' => true,
            'tiers' => [['min_quantity' => 2, 'price' => 450]],
        ];

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 1, 279.0),
            [
                'id' => '9999',
                'source' => 'delivery',
                'product_name' => 'Сыр твёрдый',
                'quantity' => 2,
                'unit_price' => 450.0,
                'unit_price_effective' => 450.0,
                'item_status' => 'added',
                'price_tiers' => $courierSnapshot,
            ],
            [
                'id' => '9998',
                'source' => 'delivery',
                'product_name' => 'Хлеб без лесенки',
                'quantity' => 1,
                'unit_price' => 45.0,
                'unit_price_effective' => 45.0,
                'item_status' => 'added',
            ],
        ]));

        $cheese = $order->items()->where('title', 'Сыр твёрдый')->firstOrFail();
        $this->assertSame($added->id, (int) $cheese->product_id);
        $this->assertSame(500.0, (float) $cheese->price_tiers['base_price']);
        $this->assertEquals([['min_quantity' => 2.0, 'price' => 450.0]], $cheese->price_tiers['tiers']);
        $this->assertSame(500.0, (float) $cheese->price_old, 'ступень дешевле обычной цены: зачёркнутая цена из снапшота');
        $this->assertFalse($cheese->eligible_for_bonus);

        $bread = $order->items()->where('title', 'Хлеб без лесенки')->firstOrFail();
        $this->assertNull($bread->price_tiers, 'ключа в событии нет: снапшота нет');
        $this->assertTrue($bread->eligible_for_bonus);
    }

    // ==================== Дополнение диспетчера: квант порций в исходящем событии ====================

    /** @return array{0: Order, 1: OrderItem} */
    private function orderWithQuantumItem(int $quantityGrams, ?array $priceTiers): array
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'price' => 279,
            'quantity_step' => 200,
            'measurement_unit' => 'gram',
        ]);

        $order = Order::factory()->for(User::factory()->create())->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 279,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 279,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Сыр весовой квант',
            'price' => 279,
            'price_old' => null,
            'quantity' => $quantityGrams,
            'total' => 279,
            'measurement_unit' => 'gram',
            'eligible_for_bonus' => true,
            'price_tiers' => $priceTiers,
        ]);

        return [$order, $item];
    }

    /**
     * Дополнение диспетчера (ревью задачи 8): у позиции СО снапшотом порции в
     * исходящем событии считаются округлением (OrderItemPortions), как при
     * сборке, а не отбрасыванием дробной части: 1930 г при шаге 200 г → 10,
     * не 9 (intdiv).
     */
    public function test_outgoing_quantum_item_with_snapshot_uses_rounded_portions(): void
    {
        $this->setPriceTiersModule(true);
        [$order] = $this->orderWithQuantumItem(1930, $this->snapshot());

        $items = $this->publishedItems($order);

        $this->assertSame(10, $items[0]['quantity']);
    }

    /**
     * Страж от регресса на форках (там снапшотов нет): у позиции БЕЗ снапшота
     * формула buildItems() не меняется — прежнее intdiv() (1930 / 200 = 9).
     */
    public function test_outgoing_quantum_item_without_snapshot_keeps_previous_formula(): void
    {
        $this->setPriceTiersModule(true);
        [$order] = $this->orderWithQuantumItem(1930, null);

        $items = $this->publishedItems($order);

        $this->assertSame(9, $items[0]['quantity']);
    }

    // ==================== Fix round 1: рассинхрон цены у позиции курьерки ====================

    /** @return array<string, mixed> */
    private function collectorAddedCheeseEventItem(float $unitPrice): array
    {
        return [
            'id' => '9999',
            'source' => 'delivery',
            'product_name' => 'Сыр твёрдый',
            'quantity' => 2,
            'unit_price' => $unitPrice,
            'unit_price_effective' => $unitPrice,
            'item_status' => 'added',
            'price_tiers' => [
                'base_price' => 500,
                'base_price_old' => null,
                'base_is_eligible' => true,
                'tiers' => [['min_quantity' => 2, 'price' => 450]],
            ],
        ];
    }

    /**
     * Позиция, созданная курьеркой (замена/дозаказ), — первое место, где цену
     * новой позиции считает уже курьерка сама: именно тут рассинхрон
     * витрина ↔ курьерка ↔ чек мог бы возникнуть незамеченным. Тот же лог
     * расхождения, что и у существующей позиции (tierFieldsForSyncedItem):
     * quote(2 порции) по снапшоту даёт 450, событие прислало 470 — расхождение
     * ≥ 0.01 → предупреждение. Цена и сумма позиции при этом берутся из
     * события (котировкой не подменяются), снапшот сохраняется как есть.
     */
    public function test_price_mismatch_for_item_added_by_collector_is_logged(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(1, 279, null, true);
        $added = Product::factory()->for($order->store)->create(['title' => 'Сыр твёрдый', 'price' => 500, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 1, 279.0),
            $this->collectorAddedCheeseEventItem(470.0),
        ]));

        $cheese = $order->items()->where('title', 'Сыр твёрдый')->firstOrFail();
        $this->assertSame($added->id, (int) $cheese->product_id);
        $this->assertSame(470.0, (float) $cheese->price, 'цена позиции берётся из события, не из котировки');
        $this->assertSame(940.0, (float) $cheese->total, 'сумма строки берётся из события, не из котировки');
        $this->assertEquals(
            PriceTierSnapshot::fromArray($this->collectorAddedCheeseEventItem(470.0)['price_tiers'])->toArray(),
            $cheese->price_tiers,
            'снапшот сохранён как есть'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'price_tiers'))
            ->once();
    }

    // ==================== F13(б): отменённая/заменённая позиция со снапшотом ====================

    /**
     * F13(б), финальное ревью: отменённая позиция с ключом price_tiers во
     * входящем событии — снапшот принимается (перезаписывается значением из
     * события), но price/price_old/eligible_for_bonus НЕ пересчитываются по
     * нему (позиция в составе заказа не участвует), warning расхождения не
     * пишется. quantity/price/total/status по-прежнему берутся из события —
     * это отдельный от тарифных полей механизм.
     */
    public function test_cancelled_item_accepts_new_snapshot_without_recalculating_tier_fields(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        $newSnapshot = [
            'base_price' => 300,
            'base_price_old' => null,
            'base_is_eligible' => true,
            'tiers' => [['min_quantity' => 3, 'price' => 230]],
        ];

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            array_merge($this->eventItem($item, 5, 500.0), ['price_tiers' => $newSnapshot, 'item_status' => 'cancelled']),
        ]));

        $item->refresh();
        $this->assertSame('cancelled', $item->status?->value ?? $item->status, 'предусловие: позиция отменена');
        $this->assertSame(500.0, (float) $item->price, 'цена берётся из события — отдельный от тарифных полей механизм');
        $this->assertEquals(
            PriceTierSnapshot::fromArray($newSnapshot)->toArray(),
            $item->price_tiers,
            'снапшот принят из события'
        );
        $this->assertSame(279.0, (float) $item->price_old, 'price_old НЕ пересчитан по новому снапшоту: позиция не участвует');
        $this->assertFalse($item->eligible_for_bonus, 'признак НЕ пересчитан по новому снапшоту');

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * F13(б): заменённая позиция, событие снимает снапшот (price_tiers: null) —
     * снапшот снимается, но price_old/eligible_for_bonus НЕ сбрасываются на
     * «обычные» (это происходит только для АКТИВНЫХ позиций), warning не пишется.
     */
    public function test_replaced_item_removes_snapshot_without_recalculating_tier_fields(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            array_merge($this->eventItem($item, 5, 310.0), ['price_tiers' => null, 'item_status' => 'replaced']),
        ]));

        $item->refresh();
        $this->assertSame('replaced', $item->status?->value ?? $item->status, 'предусловие: позиция заменена');
        $this->assertNull($item->price_tiers, 'снапшот снят событием');
        $this->assertSame(310.0, (float) $item->price, 'цена берётся из события');
        $this->assertSame(279.0, (float) $item->price_old, 'price_old НЕ сброшен на «обычный»: позиция не участвует');
        $this->assertFalse($item->eligible_for_bonus, 'признак НЕ сброшен на «обычный»: позиция не участвует');

        Log::shouldNotHaveReceived('warning');
    }

    /** Цена события совпадает с собственной котировкой снапшота — предупреждения нет. */
    public function test_price_match_for_item_added_by_collector_is_not_logged(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(1, 279, null, true);
        Product::factory()->for($order->store)->create(['title' => 'Сыр твёрдый', 'price' => 500, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 1, 279.0),
            $this->collectorAddedCheeseEventItem(450.0),
        ]));

        Log::shouldNotHaveReceived('warning');
    }

    // ==================== Fix round 2: полный возврат позиции у двери (заказ 98 на dev) ====================

    /**
     * MID-308, заказ 98 на dev: курьер вернул позицию у двери целиком —
     * событие несёт quantity 0. Ни одна ступень лесенки не покрывает 0
     * порций, quote(0) откатывается на базовую цену/признак снапшота вместо
     * значений позиции со сборки — расхождение с djami (там price_old/
     * eligible_for_bonus не пересчитываются) плюс лишнее предупреждение в
     * логе. Зачёркнутая цена и признак остаются прежними, снапшот по ключу
     * события всё равно принимается.
     */
    public function test_full_return_at_door_keeps_price_old_and_eligible_flag(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            array_merge($this->eventItem($item, 0, 220.0), [
                'returned_quantity' => 5,
                'line_total' => 0,
                'unit_price_old' => 279,
                'price_tiers' => $this->snapshot(),
            ]),
        ]));

        $item->refresh();
        $this->assertSame(0, (int) $item->quantity);
        $this->assertSame(5, (int) $item->returned_quantity);
        $this->assertSame(279.0, (float) $item->price_old, 'зачёркнутая цена не пересчитана на 0 порциях доставки');
        $this->assertFalse($item->eligible_for_bonus, 'признак не пересчитан на 0 порциях доставки');
        $this->assertEquals($this->snapshot(), $item->price_tiers, 'снапшот по ключу события принят как обычно');

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Частичный возврат (доставлено 2 из 5, возвращено 3) — ветка с порциями
     * больше 0 не затронута фиксом: price_old и eligible_for_bonus по-прежнему
     * пересчитываются по ступени снапшота.
     */
    public function test_partial_return_at_door_still_recalculates_by_snapshot(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(5, 199, 279, false);

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            array_merge($this->eventItem($item, 2, 249.0), [
                'returned_quantity' => 3,
            ]),
        ]));

        $item->refresh();
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(3, (int) $item->returned_quantity);
        $this->assertSame(279.0, (float) $item->price_old, 'частичный возврат: ступень 249 по-прежнему пересчитывается по снапшоту');
        $this->assertFalse($item->eligible_for_bonus);
    }

    /**
     * То же правило для позиции, которую создаёт курьерка (замена/дозаказ):
     * 0 порций в событии не пересчитывает зачёркнутую цену/признак по
     * снапшоту котировкой quote(0) — остаются значения, посчитанные при
     * создании позиции (offerPriceOld/hasDiscountForProduct), как у позиции
     * без снапшота.
     */
    public function test_item_added_by_collector_with_zero_portions_keeps_default_tier_fields(): void
    {
        $this->setPriceTiersModule(true);
        [$order, $item] = $this->orderWithTieredItem(1, 279, null, true);
        Product::factory()->for($order->store)->create(['title' => 'Сыр твёрдый', 'price' => 500, 'quantity_step' => 1, 'measurement_unit' => 'piece']);

        Log::spy();

        (new OrderEventTransformer)->afterUpdate($order, $this->assemblyEvent([
            $this->eventItem($item, 1, 279.0),
            [
                'id' => '9999',
                'source' => 'delivery',
                'product_name' => 'Сыр твёрдый',
                'quantity' => 0,
                'unit_price' => 450.0,
                'unit_price_effective' => 450.0,
                'item_status' => 'added',
                // base_price_old/base_is_eligible намеренно отличаются от
                // дефолта позиции (null/true), чтобы падение теста на RED
                // было видно по значению, а не совпадало случайно
                'price_tiers' => [
                    'base_price' => 500,
                    'base_price_old' => 600,
                    'base_is_eligible' => false,
                    'tiers' => [['min_quantity' => 2, 'price' => 450]],
                ],
            ],
        ]));

        $cheese = $order->items()->where('title', 'Сыр твёрдый')->firstOrFail();
        $this->assertSame(0, (int) $cheese->quantity);
        $this->assertNull($cheese->price_old, 'котировка на 0 порциях не применяется: значение по умолчанию позиции');
        $this->assertTrue($cheese->eligible_for_bonus, 'котировка на 0 порциях не применяется: значение по умолчанию позиции');
        $this->assertEquals(
            PriceTierSnapshot::fromArray([
                'base_price' => 500,
                'base_price_old' => 600,
                'base_is_eligible' => false,
                'tiers' => [['min_quantity' => 2, 'price' => 450]],
            ])->toArray(),
            $cheese->price_tiers,
            'снапшот сохранён как есть'
        );

        Log::shouldNotHaveReceived('warning');
    }
}
