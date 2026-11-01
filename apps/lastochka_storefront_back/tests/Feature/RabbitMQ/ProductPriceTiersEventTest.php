<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Store;
use App\Models\StoreProduct;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

final class ProductPriceTiersEventTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    private const LADDER = [
        ['min_quantity' => 5, 'price' => 199],
        ['min_quantity' => 2, 'price' => 249],
    ];

    private function transformer(): ProductEventTransformer
    {
        return app(ProductEventTransformer::class);
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function ladderOf(Product $product, int $storeId): array
    {
        return ProductPriceTier::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->orderBy('min_quantity')
            ->get()
            ->map(fn (ProductPriceTier $tier) => [(float) $tier->min_quantity, (float) $tier->price])
            ->all();
    }

    // ==================== price_updated, обычный режим ====================

    public function test_price_updated_writes_sorted_ladder_for_product_store(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-1',
            'price' => 279,
            'price_tiers' => self::LADDER,
        ]);

        $this->assertSame(279.0, (float) $product->fresh()->price);
        $this->assertSame([[2.0, 249.0], [5.0, 199.0]], $this->ladderOf($product, (int) $product->store_id));
    }

    public function test_missing_key_keeps_ladder_and_full_array_replaces_it(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);
        $this->seedPriceTiers($product, (int) $product->store_id, self::LADDER);

        $this->transformer()->processPriceUpdatedEvent($product, ['event_id' => 'evt-tiers-2', 'price' => 280]);
        $this->assertCount(2, $this->ladderOf($product, (int) $product->store_id), 'ключа нет: ступени не трогаем');

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-3',
            'price' => 280,
            'price_tiers' => [['min_quantity' => 10, 'price' => 150]],
        ]);
        $this->assertSame([[10.0, 150.0]], $this->ladderOf($product, (int) $product->store_id), 'массив: полная замена');
    }

    public function test_empty_array_and_null_delete_ladder(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);
        $storeId = (int) $product->store_id;

        foreach ([[], null] as $i => $emptyValue) {
            $this->seedPriceTiers($product, $storeId, self::LADDER);

            $this->transformer()->processPriceUpdatedEvent($product, [
                'event_id' => 'evt-tiers-empty-'.$i,
                'price' => 280,
                'price_tiers' => $emptyValue,
            ]);

            $this->assertSame([], $this->ladderOf($product, $storeId));
        }
    }

    public function test_broken_ladder_is_dropped_with_warning_and_price_is_applied(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);
        $storeId = (int) $product->store_id;
        $this->seedPriceTiers($product, $storeId, self::LADDER);

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-broken',
            'price' => 281,
            'price_tiers' => [
                ['min_quantity' => 2, 'price' => 249],
                ['min_quantity' => 2, 'price' => 240],
            ],
        ]);

        $this->assertSame(281.0, (float) $product->fresh()->price, 'цена события применяется');
        $this->assertCount(2, $this->ladderOf($product, $storeId), 'битая лесенка отброшена целиком, прежняя осталась');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'price_tiers'))
            ->once();
    }

    public function test_flag_off_ignores_key(): void
    {
        $this->setPriceTiersModule(false);
        $product = Product::factory()->create(['price' => 100]);

        $queries = $this->priceTierTableQueries(fn () => $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-off',
            'price' => 279,
            'price_tiers' => self::LADDER,
        ]));

        $this->assertSame(0, $queries, 'при выключенном флаге ни одного запроса к таблице ступеней');
        $this->assertSame(279.0, (float) $product->fresh()->price);
        $this->assertSame(0, ProductPriceTier::count(), 'при выключенном флаге в таблицу ступеней ничего не пишется');
    }

    // ==================== price_updated, сетевой каталог ====================

    public function test_network_mode_writes_ladder_for_shop_code_store(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $anchor = Store::factory()->create(['code' => 'tiers-anchor']);
        $shop = Store::factory()->create(['code' => 'tiers-shop']);
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0]);

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-net',
            'source' => '1c',
            'shop_code' => 'tiers-shop',
            'price' => 300,
            'price_tiers' => self::LADDER,
        ]);

        $this->assertSame(300.0, (float) StoreProduct::where('store_id', $shop->id)->where('product_id', $product->id)->value('price'));
        $this->assertSame([[2.0, 249.0], [5.0, 199.0]], $this->ladderOf($product, $shop->id));
        $this->assertSame([], $this->ladderOf($product, $anchor->id), 'якорному магазину лесенка не пишется');
    }

    public function test_network_mode_without_shop_code_ignores_ladder(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $anchor = Store::factory()->create(['code' => 'tiers-anchor']);
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0]);

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-net-no-shop',
            'source' => '1c',
            'price' => 300,
            'price_tiers' => self::LADDER,
        ]);

        $this->assertSame(0, ProductPriceTier::count());
    }

    // ==================== карточное событие ====================

    /** @param array<string, mixed> $overrides */
    private function cardEvent(string $eventType, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext-tiers-card',
            'source' => '1c',
            'shop_code' => 'TIERS_001',
            'sku' => 'SKU-TIERS-CARD',
            'title' => 'Товар с лесенкой',
            'price' => 279,
            'active' => true,
        ], $overrides);
    }

    public function test_card_event_writes_and_clears_ladder_in_regular_mode(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'TIERS_001']);
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        $consumer->processEvent($this->cardEvent('created', [
            'event_timestamp' => now()->subHour()->toIso8601String(),
            'price_tiers' => self::LADDER,
        ]));

        $product = Product::where('sku', 'SKU-TIERS-CARD')->firstOrFail();
        $this->assertSame([[2.0, 249.0], [5.0, 199.0]], $this->ladderOf($product, $store->id));

        $consumer->processEvent($this->cardEvent('updated', [
            'event_timestamp' => now()->subMinutes(30)->toIso8601String(),
            'title' => 'Товар с лесенкой, переименован',
        ]));
        $this->assertCount(2, $this->ladderOf($product, $store->id), 'карточка без ключа лесенку не трогает');

        $consumer->processEvent($this->cardEvent('updated', ['price_tiers' => []]));
        $this->assertSame([], $this->ladderOf($product, $store->id));
    }

    /**
     * Карточное событие admin_back самодостаточно: price уже цена кванта
     * (quantity_step + measurement_unit=gram), а price_tiers рядом остаются за
     * базовую единицу (кг). Лесенка пишется как есть, перевод только при чтении.
     */
    public function test_self_contained_quantum_event_stores_ladder_in_base_units(): void
    {
        $this->setPriceTiersModule(true);
        $store = Store::factory()->create(['code' => 'TIERS_001']);
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        $consumer->processEvent($this->cardEvent('created', [
            'source' => 'app',
            'unit_type' => 'kg',
            'measurement_unit' => 'gram',
            'quantity_step' => 200,
            'price' => 100,
            'quantity' => 50,
            'price_tiers' => [['min_quantity' => 2, 'price' => 450]],
        ]));

        $product = Product::where('sku', 'SKU-TIERS-CARD')->firstOrFail();

        $this->assertSame(100.0, (float) $product->price, 'цена кванта применена как есть');
        $this->assertSame([[2.0, 450.0]], $this->ladderOf($product, $store->id), 'лесенка в таблице: базовые единицы, без конвертации');
        $this->assertSame(
            [['min_quantity' => 10.0, 'price' => 90.0]],
            app(\App\Services\Pricing\ProductTierLadderProvider::class)->portionLadder($product, $store->id),
            'в порции лесенка переводится только при чтении: от 2 кг = от 10 порций, 450 ₽/кг = 90 ₽ за 200 г'
        );
    }

    public function test_card_event_ignores_ladder_in_network_mode(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        Store::factory()->create(['code' => 'TIERS_001']);
        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        $event = $this->cardEvent('created', ['price_tiers' => self::LADDER]);
        unset($event['shop_code'], $event['price']);

        $consumer->processEvent($event);

        $this->assertNotNull(Product::where('sku', 'SKU-TIERS-CARD')->first(), 'предусловие: карточка создана');
        $this->assertSame(0, ProductPriceTier::count(), 'карточка сети глобальна: пер-магазинную лесенку не пишет');
    }

    // ==================== F3: усечённый лог битой лесенки ====================

    /**
     * Финальное ревью (F3): в контекст warning кладётся весь $eventData['price_tiers'],
     * а лесенка признаётся битой в том числе по count($raw) > MAX_TIERS — ошибка
     * источника даёт лог на тысячи элементов. Логировать число элементов, тип
     * и не больше 3 элементов вместо всего значения.
     */
    public function test_broken_ladder_longer_than_limit_logs_count_not_full_array(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);

        $tiers = [];
        for ($i = 1; $i <= 60; $i++) {
            $tiers[] = ['min_quantity' => $i, 'price' => 1000 - $i];
        }

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-huge',
            'price' => 281,
            'price_tiers' => $tiers,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) {
                if (! str_contains($message, 'price_tiers')) {
                    return false;
                }

                $this->assertArrayNotHasKey('price_tiers', $context, 'полный массив не должен попадать в лог');
                $this->assertSame(60, $context['price_tiers_count'] ?? null);
                $this->assertNotNull($context['price_tiers_type'] ?? null);
                $this->assertIsArray($context['price_tiers_sample'] ?? null);
                $this->assertLessThanOrEqual(3, count($context['price_tiers_sample']));

                return true;
            })
            ->once();
    }

    // ==================== F11: одиночный резолв магазина ====================

    /**
     * Финальное ревью (F11): resolveStoreForPerShopEvent() вызывался дважды —
     * внутри upsertStoreProductPrice() и отдельно для processPriceTiers() —
     * лишний запрос и возможный дубль warning при ненайденном магазине.
     */
    public function test_network_mode_resolves_store_once_when_store_not_found(): void
    {
        $this->setPriceTiersModule(true, networkCatalog: true);
        $anchor = Store::factory()->create(['code' => 'tiers-anchor']);
        $product = Product::factory()->create(['store_id' => $anchor->id, 'price' => 0]);

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-store-missing',
            'source' => '1c',
            'shop_code' => 'unknown-shop-code',
            'price' => 300,
            'price_tiers' => self::LADDER,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'store not found'))
            ->once();
        $this->assertSame(0, ProductPriceTier::count());
    }

    // ==================== F14: ступень дороже обычной цены ====================

    public function test_ladder_tier_above_regular_price_logs_warning(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 279, 'price_old' => null, 'quantity_step' => 1]);

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-above',
            'price' => 279,
            'price_tiers' => [['min_quantity' => 1, 'price' => 350]],
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'price_tiers ladder has a tier above regular price')
            ->once();
    }

    public function test_ladder_not_above_regular_price_has_no_warning(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 279, 'price_old' => null, 'quantity_step' => 1]);

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-below',
            'price' => 279,
            'price_tiers' => self::LADDER,
        ]);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Страж от ложного срабатывания на единицах: у квантового товара price
     * события уже цена кванта, а лесенка — за базовую единицу (кг). Лобовое
     * сравнение 450 (₽/кг) с 100 (₽/квант) ошибочно решило бы, что ступень
     * дороже. Числа те же, что в test_self_contained_quantum_event_stores_ladder_in_base_units
     * (2 кг = 10 порций по 90 ₽ — дешевле цены кванта 100 ₽).
     */
    public function test_quantum_product_correct_ladder_per_kilogram_has_no_warning(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create([
            'price' => 279, 'quantity' => 50, 'unit_type' => 'kg',
            'quantity_step' => 200, 'measurement_unit' => 'gram',
        ]);

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-quantum-ok',
            'price' => 500,
            'measurement_unit' => 'kilogram',
            'price_tiers' => [['min_quantity' => 2, 'price' => 450]],
        ]);

        $this->assertSame(100.0, (float) $product->fresh()->price, 'предусловие: цена кванта применена (500 ₽/кг → 100 ₽ за 200 г)');
        Log::shouldNotHaveReceived('warning');
    }

    // ==================== Остатки ревью R2: лесенка пишется только при изменении ====================

    /** @return list<string> SQL всех INSERT/DELETE/UPDATE по product_price_tiers за время $callback */
    private function tierWriteSql(callable $callback): array
    {
        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            $sql = mb_strtolower($query->sql);
            if (str_contains($query->sql, 'product_price_tiers')
                && (str_starts_with($sql, 'insert') || str_starts_with($sql, 'delete') || str_starts_with($sql, 'update'))) {
                $writes[] = $query->sql;
            }
        });

        $callback();

        return $writes;
    }

    /**
     * R2(а): повторное событие с той же лесенкой — периодическая
     * пересинхронизация цен от 1С шлёт ту же лесенку на каждое price_updated,
     * без сравнения это лишний DELETE+INSERT на каждое такое событие.
     */
    public function test_repeated_event_with_same_ladder_does_not_rewrite_rows(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);
        $storeId = (int) $product->store_id;

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-same-1',
            'price' => 279,
            'price_tiers' => self::LADDER,
        ]);

        $before = ProductPriceTier::where('product_id', $product->id)->where('store_id', $storeId)
            ->orderBy('min_quantity')->pluck('created_at', 'id');

        $writes = $this->tierWriteSql(function () use ($product): void {
            $this->transformer()->processPriceUpdatedEvent($product, [
                'event_id' => 'evt-tiers-same-2',
                'price' => 279,
                'price_tiers' => self::LADDER,
            ]);
        });

        $this->assertSame([], $writes, 'повторная та же лесенка не пишется в product_price_tiers');

        $after = ProductPriceTier::where('product_id', $product->id)->where('store_id', $storeId)
            ->orderBy('min_quantity')->pluck('created_at', 'id');
        $this->assertEquals($before, $after, 'строки не пересозданы (те же id и created_at)');
    }

    /**
     * R2(б): warning о ступени дороже обычной цены пишется на первое событие
     * и НЕ повторяется на второе с той же (неизменённой) лесенкой.
     */
    public function test_warning_about_expensive_tier_is_not_repeated_for_unchanged_ladder(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 279, 'price_old' => null, 'quantity_step' => 1]);
        $expensiveLadder = [['min_quantity' => 1, 'price' => 350]];

        Log::spy();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-expensive-1',
            'price' => 279,
            'price_tiers' => $expensiveLadder,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'price_tiers ladder has a tier above regular price')
            ->once();

        $this->transformer()->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-tiers-expensive-2',
            'price' => 279,
            'price_tiers' => $expensiveLadder,
        ]);

        // Всё ещё ровно один раз за оба события: повторная (неизменённая)
        // лесенка не пишется и предупреждение не дублируется.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'price_tiers ladder has a tier above regular price')
            ->once();
    }

    /**
     * R2(в): страж от регресса сравнения — изменённая лесенка по-прежнему
     * заменяется целиком, а не пропускается как «та же».
     */
    public function test_changed_ladder_is_still_fully_replaced(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);
        $storeId = (int) $product->store_id;
        $this->seedPriceTiers($product, $storeId, self::LADDER);

        $writes = $this->tierWriteSql(function () use ($product): void {
            $this->transformer()->processPriceUpdatedEvent($product, [
                'event_id' => 'evt-tiers-changed',
                'price' => 280,
                'price_tiers' => [['min_quantity' => 10, 'price' => 150]],
            ]);
        });

        $this->assertNotEmpty($writes, 'изменённая лесенка обязана пойти в DELETE+INSERT');
        $this->assertSame([[10.0, 150.0]], $this->ladderOf($product, $storeId));
    }

    /**
     * R2(г): повторное [] при пустой таблице — ничего не пишет (не DELETE
     * по пустому набору).
     */
    public function test_repeated_empty_ladder_on_empty_table_does_not_write(): void
    {
        $this->setPriceTiersModule(true);
        $product = Product::factory()->create(['price' => 100]);

        $writes = $this->tierWriteSql(function () use ($product): void {
            $this->transformer()->processPriceUpdatedEvent($product, [
                'event_id' => 'evt-tiers-empty-noop',
                'price' => 280,
                'price_tiers' => [],
            ]);
        });

        $this->assertSame([], $writes, 'пустая лесенка при пустой таблице — no-op');
        $this->assertSame(0, ProductPriceTier::count());
    }
}
