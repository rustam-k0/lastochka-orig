<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductOneCCardMasterTest extends TestCase
{
    use RefreshDatabase;

    private const EXTERNAL_ID = 'aabbccdd-1111-2222-3333-444444444444';

    private EventConsumer $consumer;

    private Store $store;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $this->store = Store::factory()->create(['code' => 'CM_STORE']);
    }

    private function setOneCCardMaster(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'onec_card_master' => ['label' => '1С ведёт карточку товара', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function send(array $overrides, string $eventType = 'updated', string $eventSource = '1c'): void
    {
        $this->minute--;
        $result = $this->consumer->processEvent(array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => self::EXTERNAL_ID,
            'source' => '1c',
            'event_source' => $eventSource,
            'shop_code' => 'CM_STORE',
            'title' => 'Товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides));

        $this->assertSame('ack', $result);
    }

    private function product(): Product
    {
        return Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();
    }

    public function test_flag_on_updates_sku(): void
    {
        $this->setOneCCardMaster(true);
        $this->send(['sku' => 'CM-1'], 'created');
        $this->send(['sku' => 'CM-2']);

        $this->assertSame('CM-2', $this->product()->sku);
    }

    public function test_flag_on_sku_collision_gives_synthetic(): void
    {
        $this->setOneCCardMaster(true);
        Product::factory()->create(['store_id' => $this->store->id, 'sku' => 'CM-3']);
        $this->send(['sku' => 'CM-1'], 'created');
        $this->send(['sku' => 'CM-3']);

        $this->assertSame('1C-CM-3-aabbccdd', Product::withTrashed()->where('store_id', $this->store->id)->where('sku', 'like', '1C-%')->value('sku'));
    }

    public function test_synthetic_sku_from_admin_is_not_ping_ponged(): void
    {
        $this->setOneCCardMaster(true);
        $this->send(['sku' => 'CM-4'], 'created');
        $this->send(['sku' => '1C-CM-4-aabbccdd'], 'updated', 'admin');
        $this->send(['sku' => 'CM-4']);

        $this->assertSame('CM-4', $this->product()->sku, 'событие курьерки не меняет sku, событие 1С считает текущий своим');
    }

    public function test_flag_on_existing_synthetic_sku_is_kept_on_resend(): void
    {
        $this->setOneCCardMaster(true);
        Product::factory()->create(['store_id' => $this->store->id, 'sku' => 'CM-10']);
        $this->send(['sku' => 'CM-10'], 'created');

        $product = Product::withTrashed()->where('store_id', $this->store->id)->where('sku', 'like', '1C-%')->firstOrFail();
        $this->assertSame('1C-CM-10-aabbccdd', $product->sku);

        $this->send(['sku' => 'CM-10', 'title' => 'Новое название 10']);

        $product->refresh();
        $this->assertSame('1C-CM-10-aabbccdd', $product->sku, 'повторное событие 1С с тем же sku не меняет уже присвоенный синтетический');
        $this->assertSame('Новое название 10', $product->title);
    }

    public function test_flag_on_synthetic_sku_with_counter_suffix_is_kept_on_resend(): void
    {
        $this->setOneCCardMaster(true);
        Product::factory()->create(['store_id' => $this->store->id, 'sku' => 'CM-11']);
        Product::factory()->create(['store_id' => $this->store->id, 'sku' => '1C-CM-11-aabbccdd']);
        $this->send(['sku' => 'CM-11'], 'created');

        $product = Product::withTrashed()->where('store_id', $this->store->id)->where('sku', '1C-CM-11-aabbccdd-1')->firstOrFail();
        $this->assertSame('1C-CM-11-aabbccdd-1', $product->sku);

        $this->send(['sku' => 'CM-11']);

        $product->refresh();
        $this->assertSame('1C-CM-11-aabbccdd-1', $product->sku, 'повторное событие 1С не меняет синтетический sku с counter-суффиксом');
    }

    public function test_flag_off_keeps_sku(): void
    {
        $this->setOneCCardMaster(false);
        $this->send(['sku' => 'CM-1'], 'created');
        $this->send(['sku' => 'CM-2']);

        $this->assertSame('CM-1', $this->product()->sku);
    }

    public function test_flag_on_absent_keys_do_not_wipe_fields(): void
    {
        $this->setOneCCardMaster(true);
        $this->send([
            'sku' => 'CM-5',
            'description' => 'Описание',
            'meta_title' => 'Мета',
            'barcodes' => ['4600000000017'],
            'weight' => 500,
            'nds' => 3,
        ], 'created');

        $this->send(['sku' => 'CM-5', 'title' => 'Новое название']);

        $product = $this->product();
        $this->assertSame('Новое название', $product->title);
        $this->assertSame('Описание', $product->description);
        $this->assertSame('Мета', $product->meta_title);
        $this->assertSame(['4600000000017'], $product->barcodes);
        $this->assertSame(500, (int) $product->weight);
        $this->assertSame(3, (int) $product->vat_rate);
    }

    public function test_flag_on_explicit_null_clears_field(): void
    {
        $this->setOneCCardMaster(true);
        $this->send(['sku' => 'CM-6', 'description' => 'Описание'], 'created');
        $this->send(['sku' => 'CM-6', 'description' => null]);

        $this->assertNull($this->product()->description);
    }

    public function test_flag_off_absent_description_is_wiped_as_before(): void
    {
        $this->setOneCCardMaster(false);
        $this->send(['sku' => 'CM-7', 'description' => 'Описание'], 'created');
        $this->send(['sku' => 'CM-7']);

        $this->assertNull($this->product()->description);
    }

    public function test_flag_on_photos_from_1c_are_not_downloaded(): void
    {
        $this->setOneCCardMaster(true);
        Http::fake();

        $this->send(['sku' => 'CM-8', 'image_url' => 'https://img.test/a.jpg'], 'created');
        $this->send(['sku' => 'CM-8', 'image_url' => 'https://img.test/b.jpg', 'video_path' => null]);

        Http::assertNothingSent();
        $this->assertSame(0, $this->product()->images()->count());
    }

    public function test_flag_off_photo_download_is_attempted_as_before(): void
    {
        $this->setOneCCardMaster(false);
        Http::fake(['https://img.test/*' => Http::response('', 404)]);

        $this->send(['sku' => 'CM-9', 'image_url' => 'https://img.test/a.jpg'], 'created');

        Http::assertSent(fn ($request) => $request->url() === 'https://img.test/a.jpg');
    }
}
