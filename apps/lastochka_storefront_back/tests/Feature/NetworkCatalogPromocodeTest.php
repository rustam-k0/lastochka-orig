<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cart\ApplyPromocodeAction;
use App\Actions\Order\CreateClientOrderAction;
use App\Actions\Order\CreateOrderAction;
use App\Actions\Order\SupplementOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Exceptions\InvalidPromocodeException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Pickup\PickupSlotService;
use App\Services\Settings\ModuleConfigService;
use Carbon\Carbon;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Промокоды и бонусы в сетевом каталоге (network_catalog).
 *
 * Бизнес-правило: промокод не суммируется со скидками, eligible-сумма
 * считается только по позициям БЕЗ скидки; бонусы начисляются по тем же
 * позициям. В легаси скидку определяет карточка (products.price_old +
 * активные акции), в сетевом режиме карточка глобальная и её price_old
 * всегда NULL, а скидка магазина лежит в store_products.price_old
 * (1С кладёт туда «ЦенаЗаЕдиницуСоСкидкой»).
 *
 * Контракт:
 * (а) позиция со скидкой оффера магазина НЕ eligible ни для промокода,
 *     ни для начисления бонусов;
 * (б) позиция без скидки оффера eligible, как и раньше;
 * (в) активная акция карточки исключает позицию и в сетевом режиме —
 *     даже когда снапшот скидки позиции пуст;
 * (г) заказ считает ровно так же, как корзина (нет расхождения витрина/чек),
 *     в том числе без предварительной выборки корзины;
 * (д) все места расчёта eligible (корзина, витрина marketplace, создание
 *     заказа, дозаказ, сборка) ходят в один источник;
 * (е) при выключенном флаге источник скидки прежний — карточка (легаси
 *     байт-в-байт, снапшот позиции роли не играет).
 */
final class NetworkCatalogPromocodeTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_ALL_ON_SALE = 'Промокод нельзя применить: все товары в корзине уже участвуют в акции.';

    private Store $anchor;

    private Store $storeA;

    private User $user;

    /** Карточка со скидкой магазина: оффер 800 при базовой 1000. */
    private Product $discounted;

    /** Карточка без скидки магазина: оффер 500 без price_old. */
    private Product $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Самовывоз читает слоты от now() — фиксируем безопасное утро.
        Carbon::setTestNow(Carbon::parse('2026-08-07 08:00:00', config('app.timezone')));

        $this->anchor = Store::factory()->create(['code' => 'promo-anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create([
            'code' => 'promo-a',
            'is_active' => true,
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
            'is_bonus_enabled' => false,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $this->setModules(network: true);

        $this->discounted = $this->networkProduct();
        $this->offer($this->discounted, price: 800.0, priceOld: 1000.0);

        $this->plain = $this->networkProduct();
        $this->offer($this->plain, price: 500.0, priceOld: null);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ==================== 1. Промокод и скидка оффера ====================

    /**
     * Единственная позиция со скидкой магазина: eligible-подмножество пустое,
     * промокод применить нельзя. До фикса hasDiscount() читал NULL-колонку
     * глобальной карточки и промокод «сваливался» поверх скидки 1С.
     */
    public function test_item_with_offer_discount_is_not_eligible(): void
    {
        $this->addToCart($this->discounted);
        $this->promocodePercent(10, 'NET10');

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');
    }

    /**
     * Позиция без скидки оффера — eligible, промокод считается как раньше.
     */
    public function test_item_without_offer_discount_is_eligible(): void
    {
        $this->addToCart($this->plain);
        $this->promocodePercent(10, 'NET10');

        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');

        // 10% от 500 = 50
        $this->assertSame(50.0, $this->cartGroupDiscount());
    }

    /**
     * Смешанная корзина: скидка только по позиции без скидки магазина.
     */
    public function test_mixed_cart_counts_only_items_without_offer_discount(): void
    {
        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);
        $this->promocodePercent(10, 'NET10');

        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');

        // eligible = 500 (только plain), 10% = 50; со скидочной позиции было бы 130
        $this->assertSame(50.0, $this->cartGroupDiscount());
    }

    /**
     * Вторая половина правила «скидка = price_old позиции ИЛИ акция»:
     * акция назначена ПОСЛЕ добавления в корзину, поэтому снапшот позиции
     * пуст и отсечь её может только ветка активной акции карточки.
     */
    public function test_active_promotion_excludes_item_with_empty_snapshot(): void
    {
        $this->addToCart($this->plain);

        // Дискриминатор: снапшот скидки позиции пуст — сработать обязана
        // именно акционная ветка резолвера.
        $this->assertNull(CartItem::query()->where('product_id', $this->plain->id)->value('price_old'));

        Promotion::factory()->for($this->anchor)->create(['discount_percent' => 20])
            ->products()->attach($this->plain->id);

        $this->promocodePercent(10, 'NET10');

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');
    }

    // ==================== 2. Заказ считает как корзина ====================

    public function test_order_discount_matches_cart_discount(): void
    {
        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);
        $this->promocodePercent(10, 'NET10');

        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');
        $cartDiscount = $this->cartGroupDiscount();

        $order = $this->createPickupOrder();

        $this->assertSame(50.0, $cartDiscount);
        $this->assertSame($cartDiscount, (float) $order->discount, 'заказ обязан считать промокод как корзина');
        $this->assertSame(1300.0, (float) $order->subtotal);
        // total = subtotal - discount + delivery(0) + assembly(0)
        $this->assertSame(1250.0, (float) $order->total);
    }

    /**
     * Оформление СРАЗУ после добавления, без выборки корзины: скидка товарной
     * добавки должна быть уже в снапшоте дочерней позиции (её пишет
     * HandlesSupplements::createCatalogChildren), иначе заказ посчитает
     * скидочную добавку eligible — 62 вместо 50.
     */
    public function test_order_without_cart_fetch_excludes_supplement_with_offer_discount(): void
    {
        [$supplement, $value] = $this->catalogSupplement(
            parent: $this->plain,
            price: 120.0,
            priceOld: 150.0,
        );

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->plain->id,
            'quantity' => 1,
            'storeId' => $this->storeA->id,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ])->assertSuccessful();

        // Снапшот скидки добавки зафиксирован на создании, без GET /cart.
        $this->assertSame(
            150.0,
            (float) CartItem::query()->where('product_id', $value->product_id)->value('price_old')
        );

        $this->promocodePercent(10, 'NET10');
        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');

        $order = $this->createPickupOrder();

        // eligible = 500 (товар), добавка со скидкой оффера не в счёт → 50, не 62
        $this->assertSame(620.0, (float) $order->subtotal);
        $this->assertSame(50.0, (float) $order->discount);
    }

    // ==================== 3. Бонусы ====================

    /**
     * Начисление бонусов идёт только по позициям без скидки магазина:
     * снапшот order_items.eligible_for_bonus и зеркало orders.eligible_amount.
     */
    public function test_bonus_eligibility_excludes_offer_discounted_items(): void
    {
        $this->storeA->update([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 50,
        ]);

        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);

        // Витрина корзины: eligible только по позиции без скидки магазина.
        $this->assertSame(500, $this->cartGroupBonus()['eligibleAmount']);

        $order = $this->createPickupOrder(bonusAction: 'earn');

        $discountedItem = $order->items->firstWhere('product_id', $this->discounted->id);
        $plainItem = $order->items->firstWhere('product_id', $this->plain->id);

        $this->assertFalse((bool) $discountedItem->eligible_for_bonus, 'позиция со скидкой оффера не начисляет бонусы');
        $this->assertTrue((bool) $plainItem->eligible_for_bonus);
        $this->assertSame(500, (int) $order->eligible_amount);
        $this->assertSame(50, (int) $order->bonus_earned);
    }

    /**
     * Preview бонусов при оформлении: корзина целиком из позиций со скидкой
     * магазина даёт eligible = 0, значит снапшот order_bonuses не создаётся
     * вовсе. По старому источнику eligible был бы 800 и заказ уехал бы со
     * снапшотом начисления.
     */
    public function test_bonus_preview_skips_snapshot_when_all_items_discounted(): void
    {
        $this->storeA->update([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 50,
        ]);

        $this->addToCart($this->discounted);

        $order = $this->createPickupOrder(bonusAction: 'earn');

        $this->assertNull($order->bonus()->first(), 'нет eligible-позиций — снапшот бонусов не нужен');
        $this->assertSame(0, (int) $order->eligible_amount);
        $this->assertSame(0, (int) $order->bonus_earned);
    }

    // ==================== 4. Marketplace: витрина и заказ ====================

    /**
     * Порог min_order_amount у platform-wide промокода считается по
     * eligible-сумме: 500 (позиция без скидки) < 600, промокод отклоняется.
     * По всей корзине (1300) он бы применился.
     */
    public function test_platform_wide_min_order_amount_counts_only_eligible_items(): void
    {
        $this->setModules(network: true, marketplace: true);

        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);

        Promocode::factory()->percent(10)->withMinAmount(600)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLAT10',
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage('Минимальная сумма заказа для промокода: 600.00 руб.');

        app(ApplyPromocodeAction::class)->execute($this->user, null, 'PLAT10');
    }

    /**
     * Блок промокода в marketplace-корзине: applied только к позициям без
     * скидки магазина (1 из 2), поэтому применение частичное.
     */
    public function test_client_cart_promocode_block_counts_only_eligible_items(): void
    {
        $this->setModules(network: true, marketplace: true);

        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);

        Promocode::factory()->percent(10)->create([
            'store_id' => null,
            'scope' => Promocode::SCOPE_PLATFORM,
            'code' => 'PLAT10',
        ]);

        app(ApplyPromocodeAction::class)->execute($this->user, null, 'PLAT10');

        $promocodeBlock = $this->actingAs($this->user)->getJson('/api/v1/cart')
            ->assertOk()
            ->json('promocode');

        $this->assertSame(1, $promocodeBlock['appliedToItemsCount']);
        $this->assertSame(2, $promocodeBlock['totalItemsCount']);
        $this->assertTrue($promocodeBlock['isPartialApplication']);
        $this->assertSame(50.0, (float) $promocodeBlock['discount']);
    }

    /**
     * Агрегированный бонус-блок marketplace-корзины: eligible только по
     * позициям без скидки магазина.
     */
    public function test_client_cart_bonus_block_excludes_offer_discounted_items(): void
    {
        $this->setModules(network: true, marketplace: true);
        $this->storeA->update([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 50,
        ]);

        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);

        $bonus = $this->actingAs($this->user)->getJson('/api/v1/cart')
            ->assertOk()
            ->json('bonus');

        $this->assertSame(500, $bonus['eligibleAmount']);
        $this->assertSame(50, $bonus['maxEarn']);
    }

    /**
     * План списания бонусов по магазинам (marketplace-заказ): квота считается
     * от eligible-суммы магазина. eligible=500, spend_percent=50 → запрошенные
     * 400 режутся до 250; по всей корзине (1300) квота была бы 650 и в снапшот
     * order_bonuses.requested_amount уехали бы все 400.
     */
    public function test_marketplace_bonus_spend_plan_counts_only_eligible_items(): void
    {
        $this->setModules(network: true, marketplace: true);
        $this->storeA->update([
            'is_bonus_enabled' => true,
            'bonus_earn_percent' => 10,
            'bonus_spend_percent' => 50,
        ]);
        $this->user->update(['bonus_balance' => 1000]);

        $address = Address::factory()->active()->for($this->user)->create();

        $this->addToCart($this->discounted);
        $this->addToCart($this->plain);

        $tomorrow = now()->addDay()->format('Y-m-d');
        $slots = app(PickupSlotService::class)->getSlots($this->storeA, $tomorrow);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должны быть слоты самовывоза');

        $result = app(CreateClientOrderAction::class)->execute(new CreateClientOrderData(
            user: $this->user->fresh(),
            addressId: $address->id,
            paymentType: 'cash',
            deliverySlots: [['storeId' => $this->storeA->id, 'slotId' => (int) $slots[0]['id']]],
            shippingMethod: ShippingMethod::Pickup,
            bonusAction: 'spend',
            bonusSpendAmount: 400,
        ));

        $order = Order::with('bonus')->findOrFail($result['subOrderIds'][0]);

        $this->assertSame(250, (int) $order->bonus->requested_amount, 'план списания режется по eligible-сумме');
        $this->assertSame(250, (int) $order->bonus_spent);
    }

    // ==================== 5. Дозаказ и сборка ====================

    /**
     * Дозаказ пересчитывает процентный промокод по позициям без скидки:
     * добавленная позиция со скидкой оффера в eligible не попадает.
     */
    public function test_supplement_order_recalculates_discount_without_discounted_items(): void
    {
        $this->addToCart($this->plain);
        $this->promocodePercent(10, 'NET10');
        app(ApplyPromocodeAction::class)->execute($this->user, $this->storeA->id, 'NET10');

        $order = $this->createPickupOrder();
        $this->assertSame(50.0, (float) $order->discount);

        // Дозаказываем позицию со скидкой магазина.
        $this->addToCart($this->discounted);

        app(SupplementOrderAction::class)->execute($order->fresh(), $this->user->fresh());

        $order->refresh();

        $this->assertSame(1300.0, (float) $order->subtotal);
        $this->assertSame(50.0, (float) $order->discount, 'дозаказанная скидочная позиция не расширяет промокод');
    }

    /**
     * Позиция, созданная событием сборки (замена/дозаказ от сборщика):
     * корзины за ней нет, поэтому скидка резолвится из оффера магазина заказа
     * и снапшотится в order_items.price_old, а eligible_for_bonus = false.
     */
    public function test_assembly_added_item_snapshots_offer_discount(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
            'subtotal' => 500,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 500,
            'items_count' => 1,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->plain->id,
            'title' => 'Товар без скидки',
            'price' => 500,
            'price_old' => null,
            'quantity' => 1,
            'total' => 500,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $this->discounted->id,
            'external_id' => 'EXT-DISCOUNTED',
            'source' => '1c',
            'shop_code' => null,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        (new OrderEventTransformer)->afterUpdate($order->fresh(), [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery-order-uuid',
            'source' => 'delivery',
            'shop_code' => $this->storeA->code,
            'items' => [
                [
                    'id' => 'delivery-item-new',
                    'source' => 'delivery',
                    'product_id' => 'EXT-DISCOUNTED',
                    'product_source' => '1c',
                    'product_name' => 'Товар со скидкой магазина',
                    'item_status' => 'added',
                    'quantity' => 1,
                    'unit_price' => 800.0,
                    'unit_price_effective' => 800.0,
                    'line_subtotal' => 800.0,
                    'line_total' => 800.0,
                ],
            ],
        ]);

        $added = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('product_id', $this->discounted->id)
            ->firstOrFail();

        $this->assertSame(1000.0, (float) $added->price_old, 'снапшот цены до скидки взят из оффера магазина заказа');
        $this->assertFalse((bool) $added->eligible_for_bonus);
    }

    /**
     * Пересчёт процентного промокода после сборки: eligible считается по
     * снапшотам позиций, скидочная позиция в него не входит (50, не 130).
     */
    public function test_assembly_recalculates_percent_promocode_without_discounted_items(): void
    {
        $promocode = $this->promocodePercent(10, 'NET10');

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
            'subtotal' => 1300,
            'discount' => 50,
            'delivery_cost' => 0,
            'total' => 1250,
            'items_count' => 2,
        ]);
        $plainItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->plain->id,
            'title' => 'Товар без скидки',
            'price' => 500,
            'price_old' => null,
            'quantity' => 1,
            'total' => 500,
        ]);
        $discountedItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->discounted->id,
            'title' => 'Товар со скидкой магазина',
            'price' => 800,
            'price_old' => 1000,
            'quantity' => 1,
            'total' => 800,
        ]);
        $order->promocode()->create([
            'original_id' => $promocode->id,
            'code' => $promocode->code,
            'type' => $promocode->type,
            'value' => $promocode->value,
            'discount_amount' => 50,
            'created_at' => now(),
        ]);

        (new OrderEventTransformer)->afterUpdate($order->fresh(), [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery-order-uuid',
            'source' => 'delivery',
            'shop_code' => $this->storeA->code,
            'items' => [
                [
                    'id' => (string) $plainItem->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Товар без скидки',
                    'quantity' => 1,
                    'unit_price' => 500.0,
                    'unit_price_effective' => 500.0,
                    'line_total' => 500.0,
                ],
                [
                    'id' => (string) $discountedItem->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Товар со скидкой магазина',
                    'quantity' => 1,
                    'unit_price' => 800.0,
                    'unit_price_effective' => 800.0,
                    'line_total' => 800.0,
                ],
            ],
        ]);

        $order->refresh();

        $this->assertSame(1300.0, (float) $order->subtotal);
        $this->assertSame(50.0, (float) $order->discount, 'после сборки промокод считает только позиции без скидки');
        $this->assertSame(50.0, (float) $order->promocode->fresh()->discount_amount);
    }

    /**
     * Payload заказа в RabbitMQ (is_eligible) считает по тому же источнику:
     * снапшот order_items.price_old, а не NULL-колонка глобальной карточки.
     */
    public function test_order_event_payload_marks_discounted_item_not_eligible(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->discounted->id,
            'price' => 800,
            'price_old' => 1000,
            'quantity' => 1,
            'total' => 800,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->plain->id,
            'price' => 500,
            'price_old' => null,
            'quantity' => 1,
            'total' => 500,
        ]);

        $payload = (new OrderEventTransformer)->toEvent($order->fresh());

        $items = collect($payload['items'])->keyBy('product_id');

        $this->assertFalse($items[(string) $this->discounted->id]['is_eligible']);
        $this->assertTrue($items[(string) $this->plain->id]['is_eligible']);
    }

    // ==================== 6. Легаси ====================

    /**
     * Флаг выключен — источник скидки прежний: КАРТОЧКА, даже когда снапшот
     * позиции пуст. Единственная позиция с дисконтной карточкой и пустым
     * price_old обязана дать «все товары уже участвуют в акции»: если легаси
     * начнёт читать снапшот, промокод применится и теста не станет.
     * GET /cart здесь сознательно не дёргается — syncPrices переписал бы
     * снапшот по карточке и обезоружил проверку.
     */
    public function test_legacy_mode_reads_card_discount_with_empty_item_snapshot(): void
    {
        $this->setModules(network: false);

        $store = Store::factory()->create(['code' => 'legacy-store', 'is_active' => true]);

        $cardDiscounted = Product::factory()->for($store)->create([
            'price' => 800,
            'price_old' => 1000,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $this->user->id]);
        CartItem::factory()->for($cart)->for($cardDiscounted)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 800,
            'price_old' => null,
        ]);

        Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'LEG10',
        ]);

        $this->expectException(InvalidPromocodeException::class);
        $this->expectExceptionMessage(self::MSG_ALL_ON_SALE);

        app(ApplyPromocodeAction::class)->execute($this->user, $store->id, 'LEG10');
    }

    /**
     * Обратная сторона легаси: карточка без скидки и НЕПУСТОЙ снапшот
     * позиции — промокод обязан примениться (снапшот в легаси не читается).
     */
    public function test_legacy_mode_ignores_item_snapshot_discount(): void
    {
        $this->setModules(network: false);

        $store = Store::factory()->create(['code' => 'legacy-store-2', 'is_active' => true]);

        $cardPlain = Product::factory()->for($store)->create([
            'price' => 500,
            'price_old' => null,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $this->user->id]);
        CartItem::factory()->for($cart)->for($cardPlain)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
            'price_old' => 600,
        ]);

        Promocode::factory()->percent(10)->create([
            'store_id' => $store->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'LEG10',
        ]);

        app(ApplyPromocodeAction::class)->execute($this->user, $store->id, 'LEG10');

        $this->assertSame(50.0, $this->cartGroupDiscount($store->id));
    }

    // ==================== helpers ====================

    /**
     * Флаги модулей хранятся в storefront_settings.project_modules (type=json).
     * ModuleConfigService — singleton с мемоизацией, поэтому после записи
     * сбрасываем и кеш, и мемо.
     */
    private function setModules(bool $network, bool $marketplace = false): void
    {
        $modules = [
            'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $network],
        ];

        if ($marketplace) {
            $modules[ModuleConfigService::MARKETPLACE_MODE_KEY] = [
                'type' => 'select',
                'value' => ModuleConfigService::MODE_MARKETPLACE,
            ];
        }

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode($modules, JSON_UNESCAPED_UNICODE), 'type' => 'json']
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * Глобальная карточка сети: владелец — якорный магазин, цена/остаток
     * карточки нулевые (живут в store_products).
     */
    private function networkProduct(): Product
    {
        return Product::factory()->create([
            'store_id' => $this->anchor->id,
            'is_active' => true,
            'price' => 0,
            'price_old' => null,
            'quantity' => 0,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
    }

    private function offer(Product $product, float $price, ?float $priceOld): StoreProduct
    {
        return StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => 10,
        ]);
    }

    /**
     * Товарная добавка (каталожный SupplementValue) к карточке $parent
     * с собственным оффером в магазине покупки.
     *
     * @return array{0: Supplement, 1: SupplementValue}
     */
    private function catalogSupplement(Product $parent, float $price, ?float $priceOld): array
    {
        $supplementProduct = $this->networkProduct();
        $this->offer($supplementProduct, price: $price, priceOld: $priceOld);

        $supplement = Supplement::create([
            'product_id' => $parent->id,
            'title' => 'Соус',
            'type' => 'many',
        ]);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title' => 'Сырный соус',
            'price' => null,
            'product_id' => $supplementProduct->id,
            'position' => 1,
        ]);

        return [$supplement, $value];
    }

    private function addToCart(Product $product, int $quantity = 1): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => $quantity,
            'storeId' => $this->storeA->id,
        ])->assertSuccessful();
    }

    private function promocodePercent(int $value, string $code): Promocode
    {
        return Promocode::factory()->percent($value)->create([
            'store_id' => $this->storeA->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => $code,
        ]);
    }

    /**
     * Скидка группы магазина в выдаче корзины (GET /api/v1/cart).
     */
    private function cartGroupDiscount(?int $storeId = null): float
    {
        return (float) $this->cartGroup($storeId)['discount'];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartGroupBonus(?int $storeId = null): array
    {
        return $this->cartGroup($storeId)['bonus'];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartGroup(?int $storeId = null): array
    {
        $storeId ??= $this->storeA->id;

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart');
        $response->assertOk();

        $group = collect($response->json('storeGroups'))->firstWhere('id', $storeId);

        $this->assertNotNull($group, "в выдаче корзины нет группы магазина {$storeId}");

        return $group;
    }

    private function createPickupOrder(?string $bonusAction = null): Order
    {
        $slots = app(PickupSlotService::class)->getSlots($this->storeA, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть слот самовывоза');

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $this->user->fresh(),
            storeId: $this->storeA->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            bonusAction: $bonusAction,
            paymentType: 'cash',
        ));

        return Order::with('items')->findOrFail($result['orderId']);
    }
}
