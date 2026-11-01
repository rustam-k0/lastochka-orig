<?php

namespace Tests\Feature;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Banner;
use App\Models\BonusTransaction;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\ProductImage;
use App\Models\ProductIssueReport;
use App\Models\ProductReview;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\StoreReview;
use App\Models\Story;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetworkCatalogResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode(['network_catalog' => ['label' => 'x', 'enabled' => true]])]
        );
    }

    public function test_refuses_without_confirm(): void
    {
        $this->artisan('network-catalog:reset')->assertExitCode(1);
    }

    public function test_purges_catalog(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => 1, 'quantity' => 1]);

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('store_products')->count());
        $this->assertNotSame(0, DB::table('stores')->count());
    }

    public function test_refuses_when_module_disabled(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            ['value' => json_encode(['network_catalog' => ['label' => 'x', 'enabled' => false]])]
        );

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(1);
    }

    public function test_refuses_on_production(): void
    {
        app()['env'] = 'production';

        try {
            $this->artisan('network-catalog:reset --confirm')->assertExitCode(1);
        } finally {
            app()['env'] = 'testing';
        }
    }

    public function test_purges_all_catalog_satellite_tables(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);
        $category = Category::factory()->create();

        // Прямые дети products, покрытые исходным наброском ТЗ
        Favorite::factory()->create(['product_id' => $product->id]);
        CartItem::factory()->create(['product_id' => $product->id]);
        ProductGroupItem::factory()->create([
            'product_id' => $product->id,
            'product_group_id' => ProductGroup::factory(),
        ]);
        ProductImage::factory()->create(['product_id' => $product->id]);
        // ProductAttribute без HasFactory/фабрики в проекте — создаём напрямую
        ProductAttribute::create(['product_id' => $product->id, 'code' => 'color', 'name' => 'Цвет', 'value' => 'red']);
        $product->categories()->attach($category->id, ['position' => 0]);
        StoreProduct::create(['store_id' => $store->id, 'product_id' => $product->id, 'price' => 1, 'quantity' => 1]);

        // Дополнительно найденные аудитом схемы таблицы с обязательным FK на products
        ComparisonItem::factory()->create(['product_id' => $product->id, 'comparison_id' => Comparison::factory()]);
        EstimateItem::factory()->create(['product_id' => $product->id, 'estimate_id' => Estimate::factory()]);
        Preorder::factory()->create(['product_id' => $product->id, 'store_id' => $store->id]);
        ProductIssueReport::factory()->create(['product_id' => $product->id, 'store_id' => $store->id]);
        ProductReview::factory()->create(['product_id' => $product->id, 'store_id' => $store->id]);

        $promotion = Promotion::factory()->create(['store_id' => $store->id]);
        $product->promotions()->attach($promotion->id);

        $promocode = Promocode::factory()->create();
        $promocode->products()->attach($product->id);

        DB::table('product_documents')->insert([
            'product_id' => $product->id,
            'title' => 'doc',
            'file_path' => '/x.pdf',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('configurable_attributes')->insert([
            'product_id' => $product->id,
            'attribute_code' => 'color',
            'attribute_name' => 'Цвет',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplementId = DB::table('supplements')->insertGetId([
            'title' => 'Соус',
            'product_id' => $product->id,
            'type' => 'one',
            'is_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplementValueId = DB::table('supplement_values')->insertGetId([
            'title' => 'Соус острый',
            'price' => 50,
            'supplement_id' => $supplementId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cartItemId = DB::table('cart_items')->first()->id;
        $cartItemSupplementId = DB::table('cart_item_supplements')->insertGetId([
            'cart_item_id' => $cartItemId,
            'supplement_id' => $supplementId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('join_cart_item_supplements_supplement_values')->insert([
            'cart_item_supplement_id' => $cartItemSupplementId,
            'supplement_value_id' => $supplementValueId,
        ]);

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

        $satelliteTables = [
            'favorites', 'cart_items', 'cart_item_supplements',
            'join_cart_item_supplements_supplement_values', 'supplement_values', 'supplements',
            'product_group_items', 'product_images', 'product_attributes', 'category_product',
            'comparison_items', 'estimate_items', 'preorders', 'product_issue_reports',
            'product_reviews', 'promotion_products', 'promocode_products', 'product_documents',
            'configurable_attributes', 'store_products', 'products',
        ];

        foreach ($satelliteTables as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Таблица {$table} не была зачищена");
        }

        // Родительские сущности (не каталог по дизайну) остаются нетронутыми
        $this->assertNotSame(0, DB::table('stores')->count());
        $this->assertNotSame(0, DB::table('categories')->count());
        $this->assertNotSame(0, DB::table('comparisons')->count());
        $this->assertNotSame(0, DB::table('estimates')->count());
        $this->assertNotSame(0, DB::table('promotions')->count());
        $this->assertNotSame(0, DB::table('promocodes')->count());
    }

    public function test_nullifies_banner_and_story_product_links_without_deleting_them(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        $banner = Banner::factory()->create(['store_id' => $store->id, 'link_product_id' => $product->id]);
        $story = Story::factory()->create(['link_product_id' => $product->id]);

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

        $this->assertNotSame(0, DB::table('banners')->count());
        $this->assertNotSame(0, DB::table('stories')->count());
        $this->assertNull($banner->fresh()->link_product_id);
        $this->assertNull($story->fresh()->link_product_id);
    }

    public function test_blocks_when_orders_exist_without_with_orders_flag(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(1);

        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_with_orders_purges_orders_and_their_dependents(): void
    {
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id]);

        // Обязательные (не nullable) дети orders — проверяют порядок truncate (FK enforced на SQLite)
        DB::table('bonus_holds')->insert([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'amount' => 100,
            'status' => 'held',
            'created_at' => now(),
        ]);
        DB::table('yandex_delivery_claims')->insert([
            'order_id' => $order->id,
            'status' => 'new',
            'due_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        BonusTransaction::factory()->create(['order_id' => $order->id, 'user_id' => $order->user_id]);

        $this->artisan('network-catalog:reset --confirm --with-orders')->assertExitCode(0);

        foreach (['orders', 'order_items', 'bonus_holds', 'yandex_delivery_claims', 'bonus_transactions'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Таблица {$table} не была зачищена");
        }
    }

    public function test_with_orders_preserves_non_order_payment_and_bonus_transactions(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        // Привязаны к заказу — должны быть удалены
        $orderPayment = PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
        ]);
        $orderBonus = BonusTransaction::factory()->create(['order_id' => $order->id, 'user_id' => $order->user_id]);

        // Без заказа: bindCard() (PaymentService.php:667) и ручная/офлайн бонусная
        // операция (BonusService.php:334,449) — должны пережить --with-orders
        $bindCardPayment = PaymentTransaction::create([
            'order_id' => null,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 1,
            'metadata' => ['bind_user_id' => 1],
        ]);
        $manualBonus = BonusTransaction::factory()->create(['order_id' => null]);

        $this->artisan('network-catalog:reset --confirm --with-orders')->assertExitCode(0);

        $this->assertModelMissing($orderPayment);
        $this->assertModelMissing($orderBonus);
        $this->assertModelExists($bindCardPayment);
        $this->assertModelExists($manualBonus);
        $this->assertSame(1, DB::table('payment_transactions')->count());
        $this->assertSame(1, DB::table('bonus_transactions')->count());
    }

    public function test_with_orders_nullifies_store_review_and_promocode_usage_order_links(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $review = StoreReview::factory()->create(['order_id' => $order->id, 'store_id' => $order->store_id]);

        $promocode = Promocode::factory()->create();
        DB::table('promocode_usages')->insert([
            'promocode_id' => $promocode->id,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('network-catalog:reset --confirm --with-orders')->assertExitCode(0);

        $this->assertNotSame(0, DB::table('store_reviews')->count());
        $this->assertNotSame(0, DB::table('promocode_usages')->count());
        $this->assertNull($review->fresh()->order_id);
        $this->assertNull(DB::table('promocode_usages')->first()->order_id);
    }

    public function test_clears_event_sync_metadata_only_for_products(): void
    {
        DB::table('event_sync_metadata')->insert([
            [
                'entity_type' => 'products', 'entity_id' => 1, 'source' => '1c_parser',
                'external_id' => 'guid-1', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'entity_type' => 'categories', 'entity_id' => 1, 'source' => '1c_parser',
                'external_id' => 'guid-2', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

        $this->assertSame(0, DB::table('event_sync_metadata')->where('entity_type', 'products')->count());
        $this->assertSame(1, DB::table('event_sync_metadata')->where('entity_type', 'categories')->count());
    }

    /**
     * --with-orders обязан чистить и синк-метаданные заказов: TRUNCATE orders
     * сбрасывает AUTO_INCREMENT, и уцелевшие event_sync_metadata указывают
     * либо в никуда («Entity not found» → событие теряется после ретраев),
     * либо в чужую новую строку с переиспользованным id (тихий мёрж).
     * Пойма́но на dev после включения network_catalog (MID-235).
     */
    public function test_with_orders_purges_order_sync_metadata_and_outbox(): void
    {
        DB::table('event_sync_metadata')->insert([
            [
                'entity_type' => 'orders', 'entity_id' => 999999, 'source' => 'admin',
                'external_id' => 'stale-order', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'entity_type' => 'order_items', 'entity_id' => 999999, 'source' => 'admin',
                'external_id' => 'stale-order-item', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'entity_type' => 'customers', 'entity_id' => 1, 'source' => 'admin',
                'external_id' => 'keep-customer', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('event_outbox')->insert([
            'entity_type' => 'orders', 'entity_id' => 999999, 'event_type' => 'created',
            'event_id' => (string) \Illuminate\Support\Str::uuid(), 'routing_key' => 'order.created',
            'payload' => '{}', 'created_at' => now(),
        ]);

        $this->artisan('network-catalog:reset --confirm --with-orders')->assertExitCode(0);

        $this->assertSame(0, DB::table('event_sync_metadata')->whereIn('entity_type', ['orders', 'order_items'])->count());
        $this->assertSame(0, DB::table('event_outbox')->whereIn('entity_type', ['orders', 'order_items'])->count());
        $this->assertSame(1, DB::table('event_sync_metadata')->where('entity_type', 'customers')->count());
    }

    public function test_resets_meili_index(): void
    {
        /** @var FakeSearchClient $search */
        $search = app(SearchClientInterface::class);
        $search->indexDocument(['id' => 1, 'title' => 'товар']);
        $this->assertTrue($search->hasDocument(1));

        $this->artisan('network-catalog:reset --confirm')->assertExitCode(0);

        $this->assertFalse($search->hasDocument(1));
    }
}
