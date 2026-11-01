<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Режим «фиксированный дневной остаток» (stock_mode=fixed) должен включаться
 * мгновенно: до этого фикса quantity подтягивался из fixed_stock только ночным
 * кроном stock:reset-fixed, и товар с нулевым/отрицательным остатком из 1С
 * оставался невидимым в поиске и каталоге до 00:05.
 *
 * Второй аспект — сам крон: он менял quantity массовым UPDATE (Eloquent-события
 * не поднимались), поэтому документ в Meilisearch оставался с in_stock=false
 * и после полуночи товар всё равно не находился поиском.
 */
final class FixedStockApplyTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        Product::unsetEventDispatcher();

        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();
    }

    public function test_switching_to_fixed_mode_applies_limit_to_quantity_immediately(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-1',
            'stock_mode' => 'normal',
            'fixed_stock' => null,
            'quantity' => -2,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_fix1', 'SHOP_FIX');

        $this->consumer->processEvent($this->event([
            'event_type' => 'updated',
            'id' => 'ext_fix1',
            'shop_code' => 'SHOP_FIX',
            'sku' => 'FIX-1',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
        ]));

        $this->assertSame(10, (int) $product->fresh()->quantity);
    }

    public function test_repeated_event_with_same_limit_keeps_spent_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX2']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-2',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
            'quantity' => 3, // 7 из 10 уже продано за сегодня
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_fix2', 'SHOP_FIX2');

        $this->consumer->processEvent($this->event([
            'event_type' => 'updated',
            'id' => 'ext_fix2',
            'shop_code' => 'SHOP_FIX2',
            'sku' => 'FIX-2',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
        ]));

        $this->assertSame(3, (int) $product->fresh()->quantity, 'Рядовое событие из 1С не должно восстанавливать проданный дневной остаток');
    }

    public function test_changing_limit_applies_new_limit_to_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX3']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-3',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
            'quantity' => 3,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_fix3', 'SHOP_FIX3');

        $this->consumer->processEvent($this->event([
            'event_type' => 'updated',
            'id' => 'ext_fix3',
            'shop_code' => 'SHOP_FIX3',
            'sku' => 'FIX-3',
            'stock_mode' => 'fixed',
            'fixed_stock' => 20,
        ]));

        $this->assertSame(20, (int) $product->fresh()->quantity);
    }

    public function test_leaving_fixed_mode_does_not_apply_limit(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX4']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-4',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
            'quantity' => 3,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_fix4', 'SHOP_FIX4');

        $this->consumer->processEvent($this->event([
            'event_type' => 'updated',
            'id' => 'ext_fix4',
            'shop_code' => 'SHOP_FIX4',
            'sku' => 'FIX-4',
            'stock_mode' => 'normal',
            'fixed_stock' => null,
            'quantity' => 55,
        ]));

        $this->assertSame(55, (int) $product->fresh()->quantity, 'Возврат в normal отдаёт остаток обратно 1С');
    }

    /**
     * 1c_parser публикует карточку `products.updated` напрямую в витрину без
     * ключа stock_mode (его знает только admin_back). Такое событие не должно
     * перетирать дневной лимит fixed-товара складским остатком 1С — иначе
     * товар с нулём в 1С скрывается сразу после ночного stock:reset-fixed.
     */
    public function test_1c_card_event_without_stock_mode_keeps_fixed_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX7']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-7',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
            'quantity' => 8,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_fix7', 'SHOP_FIX7', '1c');

        $event = $this->event([
            'event_type' => 'updated',
            'id' => 'ext_fix7',
            'shop_code' => 'SHOP_FIX7',
            'sku' => 'FIX-7',
            'source' => '1c',
            'quantity' => 0,
        ]);
        unset($event['stock_mode'], $event['fixed_stock']);

        $this->consumer->processEvent($event);

        $fresh = $product->fresh();
        $this->assertSame(8, (int) $fresh->quantity, 'Остаток 1С не должен перетирать дневной лимит fixed-товара');
        $this->assertSame('fixed', $fresh->stock_mode);
        $this->assertSame(10, (int) $fresh->fixed_stock);
    }

    public function test_1c_card_event_without_stock_mode_keeps_ignore_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_IGN1']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'IGN-1',
            'stock_mode' => 'ignore',
            'fixed_stock' => null,
            'quantity' => 100000,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_ign1', 'SHOP_IGN1', '1c');

        $event = $this->event([
            'event_type' => 'updated',
            'id' => 'ext_ign1',
            'shop_code' => 'SHOP_IGN1',
            'sku' => 'IGN-1',
            'source' => '1c',
            'quantity' => 0,
        ]);
        unset($event['stock_mode']);

        $this->consumer->processEvent($event);

        $this->assertSame(100000, (int) $product->fresh()->quantity);
        $this->assertSame('ignore', $product->fresh()->stock_mode);
    }

    public function test_1c_card_event_without_stock_mode_still_updates_normal_quantity(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_NRM1']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'NRM-1',
            'stock_mode' => 'normal',
            'quantity' => 8,
            'quantity_step' => 1,
        ]);
        $this->bind($product, 'ext_nrm1', 'SHOP_NRM1', '1c');

        $event = $this->event([
            'event_type' => 'updated',
            'id' => 'ext_nrm1',
            'shop_code' => 'SHOP_NRM1',
            'sku' => 'NRM-1',
            'source' => '1c',
            'quantity' => 3,
        ]);
        unset($event['stock_mode']);

        $this->consumer->processEvent($event);

        $this->assertSame(3, (int) $product->fresh()->quantity, 'Для normal-товара остаток 1С применяется как раньше');
    }

    public function test_product_created_in_fixed_mode_gets_limit_as_quantity(): void
    {
        Store::factory()->create(['code' => 'SHOP_FIX5']);

        $this->consumer->processEvent($this->event([
            'event_type' => 'created',
            'shop_code' => 'SHOP_FIX5',
            'sku' => 'FIX-5',
            'stock_mode' => 'fixed',
            'fixed_stock' => 7,
            'quantity' => 0,
        ]));

        $product = Product::where('sku', 'FIX-5')->first();
        $this->assertNotNull($product);
        $this->assertSame(7, (int) $product->quantity);
    }

    public function test_reset_fixed_command_reindexes_products_in_search(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_FIX6']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'FIX-6',
            'stock_mode' => 'fixed',
            'fixed_stock' => 10,
            'quantity' => 0,
            'quantity_step' => 1,
            'is_active' => true,
            'price' => 100,
        ]);

        // Исходное состояние индекса — товар распродан, in_stock=false.
        $this->searchService->indexProduct($product);
        $this->assertFalse($this->searchClient->indexedDocument($product->id)['in_stock']);

        $this->artisan('stock:reset-fixed')->assertExitCode(0);

        $document = $this->searchClient->indexedDocument($product->id);
        $this->assertNotNull($document);
        $this->assertSame(10, $document['quantity'], 'Документ в поиске должен получить восстановленный остаток');
        $this->assertTrue($document['in_stock'], 'После сброса дневного лимита товар обязан находиться поиском');
    }

    /**
     * Привязать локальный товар к внешнему id — иначе EventConsumer не найдёт
     * его (findExistingEntity по sku намеренно возвращает null) и создаст дубль.
     */
    private function bind(Product $product, string $externalId, string $shopCode, string $source = 'bagisto'): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => $externalId,
            'source' => $source,
            'shop_code' => $shopCode,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'SHOP',
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
