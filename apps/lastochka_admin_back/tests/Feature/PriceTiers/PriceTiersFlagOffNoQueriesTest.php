<?php

declare(strict_types=1);

namespace Tests\Feature\PriceTiers;

use App\Events\ProductUpdated;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\InteractsWithPriceTiers;
use Tests\TestCase;

/**
 * Страж (MID-308): при выключенном модуле «Цены от количества» к таблице
 * price_tiers не выполняется НИ ОДНОГО запроса. На форке в окне между
 * выкладкой кода и миграцией таблицы ещё нет: любой запрос к ней уронил бы
 * публикацию карточки, приём цен 1С или карточку товара в админке.
 *
 * Считаются только обращения к таблице (from/into/update/join `price_tiers`):
 * одноимённая колонка order_items.price_tiers сюда не относится.
 */
class PriceTiersFlagOffNoQueriesTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;
    use InteractsWithPriceTiers;

    private Shop $shop;
    private Product $product;
    private ProductShopPriceService $tiers;

    /** @var array<int, string> */
    private array $tierTableQueries = [];

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

        Cache::forever(NetworkCatalogMode::CACHE_KEY, false);

        $this->shop = Shop::create([
            'title' => 'Guard Shop ' . uniqid(),
            'code' => 'tier-guard-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $this->product = Product::create([
            'title' => 'Guard product ' . uniqid(),
            'articul' => 'GUARD-' . uniqid(),
            'shop_id' => $this->shop->id,
            'unit_type_id' => Type::UNIT_PIECE,
            'status_id' => 1,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE,
            'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
        ]);
        $this->tiers = app(ProductShopPriceService::class);

        // Лесенка в таблице есть (модуль включали раньше): страж ловит не
        // только запись, но и чтение.
        $this->tiers->syncPriceTiers($this->product->id, $this->shop->id, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
        ]));

        $this->setPriceTiersModule(false);

        DB::listen(function (QueryExecuted $query) {
            if (preg_match('/\b(from|into|update|join)\s+`price_tiers`/iu', $query->sql) === 1) {
                $this->tierTableQueries[] = $query->sql;
            }
        });
    }

    protected function tearDown(): void
    {
        Cache::forget(NetworkCatalogMode::CACHE_KEY);
        Cache::forget(NetworkCatalogMode::ANCHOR_CACHE_KEY);
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    private function assertNoTierTableQueries(): void
    {
        $this->assertSame([], $this->tierTableQueries, 'при выключенном модуле к таблице price_tiers обращаться нельзя');
    }

    private function outboxCount(string $eventType): int
    {
        return EventOutbox::where('entity_type', 'products')
            ->where('entity_id', $this->product->id)
            ->where('event_type', $eventType)
            ->count();
    }

    public function test_recorder_sees_price_tiers_queries_when_flag_enabled(): void
    {
        // Контроль самого стража: при включённом модуле те же действия таблицу
        // читают, значит пустой список ниже — заслуга гейта, а не слепого фильтра.
        $this->setPriceTiersModule(true);

        event(new ProductUpdated($this->product->fresh()));

        $this->assertNotSame([], $this->tierTableQueries);
    }

    public function test_card_event_publication_does_not_touch_the_table(): void
    {
        event(new ProductUpdated($this->product->fresh()));

        $this->assertSame(1, $this->outboxCount('updated'), 'предусловие: карточное событие реально опубликовано');
        $this->assertNoTierTableQueries();
    }

    public function test_offer_events_and_offer_removal_do_not_touch_the_table(): void
    {
        Cache::forever(NetworkCatalogMode::CACHE_KEY, true);

        $this->tiers->save($this->product, $this->shop, 279.0, 5);
        $this->tiers->applyToAll($this->product, 289.0, 5);
        $this->tiers->delete($this->product, $this->shop);

        $this->assertGreaterThanOrEqual(3, $this->outboxCount('price_updated'), 'предусловие: офферные события опубликованы');
        $this->assertNoTierTableQueries();
    }

    public function test_graphql_product_card_and_update_do_not_touch_the_table(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            query ($id: Int) {
                admin_products_item(id: $id) { id price_tiers { shop_id min_quantity value } }
            }
        ', ['id' => $this->product->id])
            ->assertJson(['data' => ['admin_products_item' => ['id' => $this->product->id, 'price_tiers' => []]]]);

        $this->actingAs($user)->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int, $shop_id: Int, $price: Float) {
                ProductUpdate(id: $id, shop_id: $shop_id, price: $price) { code }
            }
        ', ['id' => $this->product->id, 'shop_id' => $this->shop->id, 'price' => 279.0])
            ->assertJson(['data' => ['ProductUpdate' => ['code' => 200]]]);

        $this->assertNoTierTableQueries();
    }

    public function test_inbound_price_event_with_ladder_does_not_touch_the_table(): void
    {
        config()->set('rabbitmq-events.enabled', false);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($this->product, [
            'event_id' => (string) Str::uuid(),
            'price' => 279.00,
            'unit_type' => 'piece',
            'measurement_unit' => 'piece',
            'shop_code' => $this->shop->code,
            'price_tiers' => [['min_quantity' => 5, 'price' => 199]],
        ]);

        $this->assertEqualsWithDelta(279.0, (float) Price::where('product_id', $this->product->id)
            ->where('shop_id', $this->shop->id)->value('value'), 0.001, 'предусловие: цена применена');
        $this->assertNoTierTableQueries();
    }

    public function test_inbound_price_batch_with_ladder_does_not_touch_the_table(): void
    {
        config()->set('rabbitmq-events.enabled', false);

        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $this->product->id,
            'source' => '1c',
            'external_id' => 'guard-ext',
            'shop_code' => $this->shop->code,
            'last_event_id' => (string) Str::uuid(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        foreach ([false, true] as $bulk) {
            config()->set('rabbitmq-events.bulk_price_batch', $bulk);

            app(ProductBatchHandler::class)->handle([
                'event_id' => (string) Str::uuid(),
                'event_timestamp' => now()->addSeconds($bulk ? 2 : 1)->toIso8601String(),
                'event_type' => 'price_batch_updated',
                'event_source' => '1c',
                'source' => '1c',
                'shop_code' => $this->shop->code,
                'items' => [
                    ['id' => 'guard-ext', 'price' => $bulk ? 299.00 : 289.00, 'price_tiers' => [['min_quantity' => 5, 'price' => 199]]],
                ],
            ]);
        }

        $this->assertEqualsWithDelta(299.0, (float) Price::where('product_id', $this->product->id)
            ->where('shop_id', $this->shop->id)->value('value'), 0.001, 'предусловие: оба батча применили цену');
        $this->assertNoTierTableQueries();
    }
}
