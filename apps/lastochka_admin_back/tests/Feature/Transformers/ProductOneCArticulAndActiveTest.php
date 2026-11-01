<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\Shop;
use App\Models\Status;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithOneCCardMaster;
use Tests\TestCase;

class ProductOneCArticulAndActiveTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCCardMaster;

    private const EXTERNAL_ID = 'aabbccdd-1111-2222-3333-444444444444';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        $this->shop = Shop::create(['title' => 'Articul shop', 'code' => 'AR_' . Str::random(6)]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function batch(array $item, int $minutesAgo, string $eventSource = '1c'): void
    {
        app(ProductBatchHandler::class)->handle([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->subMinutes($minutesAgo)->toIso8601String(),
            'event_type' => 'batch_updated',
            'event_source' => $eventSource,
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'items' => [array_merge([
                'id' => self::EXTERNAL_ID,
                'title' => 'Товар',
                'price' => 100,
                'unit_type' => 'piece',
                'active' => true,
            ], $item)],
        ]);
    }

    private function product(): Product
    {
        $meta = EventSyncMetadata::query()
            ->where('entity_type', 'products')->where('source', '1c')
            ->where('external_id', self::EXTERNAL_ID)->where('shop_code', $this->shop->code)
            ->firstOrFail();

        return Product::withTrashed()->findOrFail($meta->entity_id);
    }

    public function test_flag_on_updates_articul(): void
    {
        $this->setOneCCardMaster(true);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->batch(['sku' => 'AR-2'], 5);

        $this->assertSame('AR-2', $this->product()->articul);
    }

    public function test_flag_on_collision_gives_synthetic_articul(): void
    {
        $this->setOneCCardMaster(true);
        Product::query()->create(['title' => 'Чужой', 'shop_id' => $this->shop->id, 'articul' => 'AR-3']);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->batch(['sku' => 'AR-3'], 5);

        $this->assertSame('1C-AR-3-aabbccdd', $this->product()->articul);
    }

    public function test_flag_on_synthetic_articul_of_same_sku_is_kept(): void
    {
        $this->setOneCCardMaster(true);
        Product::query()->create(['title' => 'Чужой', 'shop_id' => $this->shop->id, 'articul' => 'AR-4']);
        $this->batch(['sku' => 'AR-4'], 10);
        $this->assertSame('1C-AR-4-aabbccdd', $this->product()->articul);

        $this->batch(['sku' => 'AR-4', 'title' => 'Переименован'], 5);

        $product = $this->product();
        $this->assertSame('1C-AR-4-aabbccdd', $product->articul);
        $this->assertSame('Переименован', $product->title);
    }

    public function test_flag_off_keeps_articul(): void
    {
        $this->setOneCCardMaster(false);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->batch(['sku' => 'AR-2'], 5);

        $this->assertSame('AR-1', $this->product()->articul);
    }

    public function test_non_1c_event_source_keeps_articul(): void
    {
        $this->setOneCCardMaster(true);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->batch(['sku' => 'AR-2'], 5, 'admin');

        $this->assertSame('AR-1', $this->product()->articul);
    }

    public function test_flag_on_active_false_unpublishes_and_logs_history(): void
    {
        $this->setOneCCardMaster(true);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->assertSame(Status::CODE_ACTIVE, (int) $this->product()->status_id);

        $this->batch(['sku' => 'AR-1', 'active' => false], 5);

        $product = $this->product();
        $this->assertSame(Status::CODE_NOT_PUBLISHED, (int) $product->status_id);
        $this->assertTrue(ProductHistory::query()
            ->where('product_id', $product->id)
            ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
            ->where('comment', 'Статус из 1С')
            ->exists());
    }

    public function test_flag_off_active_false_keeps_status(): void
    {
        $this->setOneCCardMaster(false);
        $this->batch(['sku' => 'AR-1'], 10);
        $this->batch(['sku' => 'AR-1', 'active' => false], 5);

        $this->assertSame(Status::CODE_ACTIVE, (int) $this->product()->status_id);
    }
}
