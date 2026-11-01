<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\Models\User;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * Исходящие события с лесенкой «цены от количества» (MID-308):
 * price_updated из ProductShopPriceService::publishOfferEvents() (сетевой
 * каталог) и карточное событие ProductEventTransformer::toEvent() (обычный
 * режим). При выключенном флаге ключа price_tiers в событиях нет. Снятие
 * оффера (delete) удаляет лесенку пары и шлёт price_tiers: [].
 */
class PriceTiersOutboundEventTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;
    use InteractsWithPriceTiers;

    private const SAVE = /** @lang GraphQL */ '
        mutation ($product_id: Int!, $shop_id: Int!, $price: Float!, $quantity: Int!, $price_tiers: [PriceTierInput!]) {
            ProductShopPriceSave(product_id: $product_id, shop_id: $shop_id, price: $price, quantity: $quantity, price_tiers: $price_tiers) { value }
        }
    ';

    private const UPDATE = /** @lang GraphQL */ '
        mutation ($id: Int, $shop_id: Int, $price: Float, $price_tiers: [PriceTierInput!]) {
            ProductUpdate(id: $id, shop_id: $shop_id, price: $price, price_tiers: $price_tiers) { code }
        }
    ';

    private User $user;
    private Shop $shop;
    private Product $product;
    private ProductShopPriceService $tiers;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами: без
        // сброса слушатель ProductUpdated регистрируется только в первом тесте
        // процесса, и карточное событие не попадает в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);

        $this->user = $this->adminUser();
        $this->shop = $this->makeShop();
        $this->product = Product::create([
            'title' => 'Товар с лесенкой ' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
        $this->tiers = app(ProductShopPriceService::class);

        $this->setPriceTiersModule(true);
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'Tier Shop ' . uniqid(),
            'code' => 'tier-out-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function seedLadder(?Shop $shop = null): void
    {
        $this->tiers->syncPriceTiers($this->product->id, ($shop ?? $this->shop)->id, PriceTierLadder::normalize([
            ['min_quantity' => 3, 'price' => 100],
        ]));
    }

    /** @return array<string, mixed> */
    private function lastPayload(string $eventType, ?string $shopCode = null): array
    {
        $event = EventOutbox::where('entity_type', 'products')
            ->where('entity_id', $this->product->id)
            ->where('event_type', $eventType)
            ->orderByDesc('id')
            ->get()
            ->first(fn (EventOutbox $row) => $shopCode === null || ($row->payload['shop_code'] ?? null) === $shopCode);

        $this->assertNotNull($event, "событие {$eventType} не опубликовано");

        return $event->payload;
    }

    public function test_price_updated_carries_new_ladder_and_shop_code(): void
    {
        $this->actingAs($this->user)->graphQL(self::SAVE, [
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'quantity' => 7,
            'price_tiers' => [
                ['min_quantity' => 5, 'value' => 199],
                ['min_quantity' => 2, 'value' => 249],
            ],
        ])->assertJsonMissing(['errors']);

        $payload = $this->lastPayload('price_updated');

        $this->assertSame($this->shop->code, $payload['shop_code']);
        $this->assertEquals(279.0, $payload['price']);
        $this->assertEquals([
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ], $payload['price_tiers']);
        $this->assertArrayNotHasKey('value', $payload['price_tiers'][0], 'в событии ключ цены ступени — price');
    }

    public function test_price_updated_without_argument_carries_stored_ladder(): void
    {
        $this->seedLadder();

        $this->actingAs($this->user)->graphQL(self::SAVE, [
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 300.0,
            'quantity' => 1,
        ])->assertJsonMissing(['errors']);

        $this->assertEquals(
            [['min_quantity' => 3, 'price' => 100]],
            $this->lastPayload('price_updated')['price_tiers']
        );
    }

    public function test_price_updated_carries_empty_list_when_no_ladder(): void
    {
        $this->tiers->save($this->product, $this->shop, 279.0, 1);

        $payload = $this->lastPayload('price_updated');

        $this->assertArrayHasKey('price_tiers', $payload);
        $this->assertSame([], $payload['price_tiers']);
    }

    public function test_apply_to_all_carries_ladder_for_every_shop(): void
    {
        $second = $this->makeShop();

        $this->tiers->applyToAll($this->product, 279.0, 3, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
        ]));

        foreach ([$this->shop, $second] as $shop) {
            $this->assertEquals(
                [['min_quantity' => 2, 'price' => 249]],
                $this->lastPayload('price_updated', $shop->code)['price_tiers']
            );
        }
    }

    public function test_tiers_only_change_publishes_offer_in_network_mode(): void
    {
        Price::create([
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 279,
            'step' => 1,
        ]);

        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        $payload = $this->lastPayload('price_updated');

        $this->assertEquals(279.0, $payload['price'], 'цена оффера берётся сохранённая');
        $this->assertEquals([['min_quantity' => 2, 'price' => 249]], $payload['price_tiers']);
    }

    public function test_card_event_carries_ladder_of_product_shop_in_regular_mode(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);

        $this->actingAs($this->user)->graphQL(self::UPDATE, [
            'id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'price' => 279.0,
            'price_tiers' => [['min_quantity' => 2, 'value' => 249]],
        ])->assertJsonMissing(['errors']);

        $payload = $this->lastPayload('updated');

        $this->assertEquals([['min_quantity' => 2, 'price' => 249]], $payload['price_tiers']);
    }

    public function test_to_event_takes_ladder_of_event_shop_only(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);
        $other = $this->makeShop();
        $this->seedLadder();
        $this->tiers->syncPriceTiers($this->product->id, $other->id, PriceTierLadder::normalize([
            ['min_quantity' => 9, 'price' => 1],
        ]));

        $transformer = app(ProductEventTransformer::class);

        $this->assertEquals(
            [['min_quantity' => 3, 'price' => 100]],
            $transformer->toEvent($this->product->fresh())['price_tiers']
        );
        $this->assertEquals(
            [['min_quantity' => 9, 'price' => 1]],
            $transformer->toEvent($this->product->fresh(), $other)['price_tiers']
        );
    }

    public function test_card_event_has_no_ladder_key_in_network_mode(): void
    {
        $this->seedLadder();

        $this->assertArrayNotHasKey(
            'price_tiers',
            app(ProductEventTransformer::class)->toEvent($this->product->fresh())
        );
    }

    public function test_flag_disabled_keeps_events_without_the_key(): void
    {
        $this->seedLadder();
        $this->setPriceTiersModule(false);

        $this->tiers->save($this->product, $this->shop, 279.0, 1);
        $this->assertArrayNotHasKey('price_tiers', $this->lastPayload('price_updated'));

        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);
        $this->assertArrayNotHasKey(
            'price_tiers',
            app(ProductEventTransformer::class)->toEvent($this->product->fresh())
        );
    }

    public function test_apply_to_all_without_ladder_reads_stored_ladders_once_per_chunk(): void
    {
        $second = $this->makeShop();
        $this->seedLadder();
        $this->seedLadder($second);

        $shopsWithCode = Shop::query()->whereNotNull('code')->where('code', '!=', '')->count();

        DB::enableQueryLog();
        $this->tiers->applyToAll($this->product, 279.0, 3);
        $tierQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => mb_strpos($query['query'], 'from `price_tiers`') !== false);
        DB::disableQueryLog();

        $this->assertCount((int) ceil($shopsWithCode / 200), $tierQueries, 'один запрос лесенок на чанк магазинов, не на магазин');

        foreach ([$this->shop, $second] as $shop) {
            $this->assertEquals(
                [['min_quantity' => 3, 'price' => 100]],
                $this->lastPayload('price_updated', $shop->code)['price_tiers']
            );
        }
    }

    public function test_delete_removes_ladder_of_pair_and_publishes_empty_ladder(): void
    {
        $other = $this->makeShop();
        $this->seedLadder();
        $this->seedLadder($other);
        $this->tiers->save($this->product, $this->shop, 279.0, 5);

        $this->assertTrue($this->tiers->delete($this->product, $this->shop));

        $this->assertSame([], $this->tiers->ladderFor($this->product->id, $this->shop->id));
        $this->assertCount(1, $this->tiers->ladderFor($this->product->id, $other->id), 'лесенка другого магазина не тронута');

        $payload = $this->lastPayload('price_updated', $this->shop->code);

        $this->assertEquals(0.0, $payload['price']);
        $this->assertArrayHasKey('price_tiers', $payload);
        $this->assertSame([], $payload['price_tiers']);
    }

    public function test_delete_mutation_removes_ladder(): void
    {
        $this->seedLadder();
        $this->tiers->save($this->product, $this->shop, 279.0, 5);

        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
            mutation ($product_id: Int!, $shop_id: Int!) {
                ProductShopPriceDelete(product_id: $product_id, shop_id: $shop_id)
            }
        ', ['product_id' => $this->product->id, 'shop_id' => $this->shop->id])
            ->assertJson(['data' => ['ProductShopPriceDelete' => true]]);

        $this->assertDatabaseMissing('price_tiers', ['product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
        $this->assertSame([], $this->lastPayload('price_updated', $this->shop->code)['price_tiers']);
    }

    public function test_delete_with_flag_disabled_keeps_ladder_and_event_without_key(): void
    {
        $this->seedLadder();
        $this->setPriceTiersModule(false);
        $this->tiers->save($this->product, $this->shop, 279.0, 5);

        $this->tiers->delete($this->product, $this->shop);

        $this->assertDatabaseHas('price_tiers', ['product_id' => $this->product->id, 'shop_id' => $this->shop->id]);
        $this->assertArrayNotHasKey('price_tiers', $this->lastPayload('price_updated', $this->shop->code));
    }

    public function test_resync_command_publishes_offers_with_ladders(): void
    {
        $this->seedLadder();
        Price::create([
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::PRICE_DEFAULT,
            'value' => 279,
            'step' => 1,
        ]);

        $this->artisan('products:resync-showcase', [
            '--ids' => (string) $this->product->id,
            '--with-offers' => true,
            '--sleep' => 0,
        ])->assertSuccessful();

        $payload = $this->lastPayload('price_updated', $this->shop->code);

        $this->assertEquals(279.0, $payload['price']);
        $this->assertEquals([['min_quantity' => 3, 'price' => 100]], $payload['price_tiers']);
    }
}
