<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductOneCAttributesMergeTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    private Store $store;

    private int $minute = 30;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));
        $this->store = Store::factory()->create(['code' => 'ATTR_STORE']);
    }

    private function setOneCCardMaster(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(['onec_card_master' => ['label' => '1С ведёт карточку товара', 'enabled' => $enabled]], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function send(array $attributes, string $eventType): void
    {
        $this->minute--;
        $this->consumer->processEvent([
            'event_id' => Str::uuid()->toString(),
            'event_type' => $eventType,
            'event_timestamp' => now()->subMinutes($this->minute)->toIso8601String(),
            'id' => 'attr-guid-1',
            'source' => '1c',
            'event_source' => '1c',
            'shop_code' => 'ATTR_STORE',
            'sku' => 'ATTR-1',
            'title' => 'Сыр',
            'price' => 300.00,
            'active' => true,
            'attributes' => $attributes,
        ]);
    }

    private function attributesByCode(): array
    {
        $product = Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();

        return ProductAttribute::query()->where('product_id', $product->id)->pluck('value', 'code')->all();
    }

    public function test_flag_on_merges_by_code(): void
    {
        $this->setOneCCardMaster(true);
        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Россия'],
            ['code' => 'proteins', 'name' => 'Белки', 'value' => '1'],
        ], 'created');

        $this->send([
            ['code' => 'proteins', 'name' => 'Белки', 'value' => '25,5'],
            ['code' => 'shelf_life', 'name' => 'Срок хранения', 'value' => '6 месяцев'],
            ['code' => 'broken', 'name' => 'Без значения'],
        ], 'updated');

        $this->assertSame(
            ['country' => 'Россия', 'proteins' => '25,5', 'shelf_life' => '6 месяцев'],
            collect($this->attributesByCode())->sortKeys()->all()
        );
        $product = Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertEquals(25.5, (float) $product->proteins);
    }

    public function test_flag_on_keeps_description_and_group_when_keys_absent(): void
    {
        $this->setOneCCardMaster(true);
        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Россия', 'description' => 'Страна производства', 'group' => 'origin'],
        ], 'created');

        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Беларусь'],
        ], 'updated');

        $product = Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();
        $attribute = ProductAttribute::query()->where('product_id', $product->id)->where('code', 'country')->firstOrFail();
        $this->assertSame('Беларусь', $attribute->value);
        $this->assertSame('Страна производства', $attribute->description);
        $this->assertSame('origin', $attribute->group_code);
    }

    public function test_flag_on_explicit_null_description_and_group_clear_them(): void
    {
        $this->setOneCCardMaster(true);
        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Россия', 'description' => 'Страна производства', 'group' => 'origin'],
        ], 'created');

        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Россия', 'description' => null, 'group' => null],
        ], 'updated');

        $product = Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();
        $attribute = ProductAttribute::query()->where('product_id', $product->id)->where('code', 'country')->firstOrFail();
        $this->assertNull($attribute->description);
        $this->assertNull($attribute->group_code);
    }

    public function test_flag_on_duplicate_code_in_payload_last_wins(): void
    {
        $this->setOneCCardMaster(true);
        $this->send([
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Россия'],
            ['code' => 'country', 'name' => 'Страна', 'value' => 'Беларусь'],
            ['code' => 'proteins', 'name' => 'Белки', 'value' => '2'],
        ], 'created');

        $product = Product::withTrashed()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame(1, ProductAttribute::query()->where('product_id', $product->id)->where('code', 'country')->count());
        $this->assertSame(['country' => 'Беларусь', 'proteins' => '2'], collect($this->attributesByCode())->sortKeys()->all());
        $this->assertSame(
            [1, 2],
            ProductAttribute::query()->where('product_id', $product->id)->orderBy('position')->pluck('position')->all()
        );
    }

    public function test_flag_off_replaces_list_as_before(): void
    {
        $this->setOneCCardMaster(false);
        $this->send([['code' => 'country', 'name' => 'Страна', 'value' => 'Россия']], 'created');
        $this->send([['code' => 'shelf_life', 'name' => 'Срок хранения', 'value' => '6 месяцев']], 'updated');

        $this->assertSame(['shelf_life' => '6 месяцев'], $this->attributesByCode());
    }
}
