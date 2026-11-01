<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Events\ProductUpdated;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\StockModeChange;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\Stock\OneCTrackStockApplier;
use App\Services\Stock\StockMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTrackStockEventTest extends TestCase
{
    use DatabaseTransactions;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        $this->shop = Shop::create(['title' => 'Track stock shop', 'code' => 'TS_' . Str::random(6)]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function batch(array $item, int $minutesAgo): void
    {
        app(ProductBatchHandler::class)->handle([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->subMinutes($minutesAgo)->toIso8601String(),
            'event_type' => 'batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'items' => [array_merge([
                'sku' => 'TRACK-' . $item['id'],
                'title' => 'Товар остатков',
                'price' => 100,
                'unit_type' => 'piece',
                'active' => true,
            ], $item)],
        ]);
    }

    private function productByExternal(string $id): Product
    {
        return Product::query()->where('articul', 'TRACK-' . $id)->firstOrFail();
    }

    public function test_false_sets_ignore_and_logs_change(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id, 'track_stock' => false], 10);

        $product = $this->productByExternal($id);
        $this->assertSame(StockMode::IGNORE, $product->stock_mode);
        $this->assertSame(StockMode::IGNORE, $product->effective_stock_mode);
        $this->assertTrue(StockModeChange::query()
            ->where('entity_type', 'product')->where('entity_id', $product->id)
            ->where('new_mode', StockMode::IGNORE)->whereNull('user_id')->exists());
    }

    public function test_true_returns_to_category_mode(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id, 'track_stock' => false], 10);
        $this->batch(['id' => $id, 'track_stock' => true], 5);

        $product = $this->productByExternal($id);
        $this->assertNull($product->stock_mode);
        $this->assertSame(StockMode::NORMAL, $product->effective_stock_mode);
    }

    public function test_fixed_mode_is_not_touched(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id], 10);
        $product = $this->productByExternal($id);
        $product->forceFill(['stock_mode' => StockMode::FIXED, 'fixed_stock_quantity' => 5])->save();

        $this->batch(['id' => $id, 'track_stock' => false], 5);

        $this->assertSame(StockMode::FIXED, $product->fresh()->stock_mode);
    }

    public function test_absent_key_keeps_manual_mode(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id], 10);
        $product = $this->productByExternal($id);
        $product->forceFill(['stock_mode' => StockMode::IGNORE])->save();

        $this->batch(['id' => $id, 'title' => 'Новое название'], 5);

        $this->assertSame(StockMode::IGNORE, $product->fresh()->stock_mode);
    }

    public function test_false_does_not_cancel_fixed_limit_inherited_from_category(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id], 10);
        $product = $this->productByExternal($id);
        $product->forceFill(['stock_mode' => null, 'effective_stock_mode' => StockMode::FIXED, 'effective_fixed_stock' => 5])->save();

        $this->batch(['id' => $id, 'track_stock' => false], 5);

        $fresh = $product->fresh();
        $this->assertNull($fresh->stock_mode);
        $this->assertSame(StockMode::FIXED, $fresh->effective_stock_mode);
        $this->assertFalse(StockModeChange::query()->where('entity_type', 'product')->where('entity_id', $product->id)->exists());
    }

    public function test_fixed_from_category_is_read_from_database_not_stale_model(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id], 10);
        $stale = $this->productByExternal($id);
        // Категории привязаны позже (шаг 4b пересчитывает effective на fresh()-копии),
        // в памяти у обработчика режим ещё прежний.
        Product::query()->whereKey($stale->id)->update(['effective_stock_mode' => StockMode::FIXED, 'effective_fixed_stock' => 5]);

        $changed = app(OneCTrackStockApplier::class)->apply($stale, false);

        $this->assertFalse($changed);
        $this->assertNull($stale->fresh()->stock_mode);
    }

    public function test_unrecognized_value_does_not_touch_mode_and_warns(): void
    {
        foreach (['Да', 'Y'] as $value) {
            $id = 'ts-' . Str::random(6);
            $this->batch(['id' => $id], 10);
            $product = $this->productByExternal($id);

            Log::spy();
            $this->batch(['id' => $id, 'track_stock' => $value], 5);

            $this->assertNull($product->fresh()->stock_mode, "track_stock={$value} не должен выключать учёт остатков");
            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($message, $context = []) => str_contains($message, 'track_stock') && ($context['track_stock'] ?? null) === $value)
                ->once();
        }
    }

    public function test_null_returns_to_category_mode(): void
    {
        $id = 'ts-' . Str::random(6);
        $this->batch(['id' => $id, 'track_stock' => false], 10);

        $this->batch(['id' => $id, 'track_stock' => null], 5);

        $this->assertNull($this->productByExternal($id)->stock_mode);
    }

    public function test_recognized_values_keep_previous_semantics(): void
    {
        foreach ([[false, StockMode::IGNORE], [0, StockMode::IGNORE], ['false', StockMode::IGNORE], [true, null], [1, null], ['true', null]] as [$value, $expected]) {
            $id = 'ts-' . Str::random(6);
            $this->batch(['id' => $id], 10);
            $product = $this->productByExternal($id);
            $product->forceFill(['stock_mode' => $expected === null ? StockMode::IGNORE : null])->save();

            $this->batch(['id' => $id, 'track_stock' => $value], 5);

            $this->assertSame($expected, $product->fresh()->stock_mode, 'track_stock=' . var_export($value, true));
        }
    }

    public function test_track_stock_event_carries_attributes_from_same_item(): void
    {
        $id = 'ts-' . Str::random(6);
        $snapshots = [];
        Event::listen(ProductUpdated::class, function (ProductUpdated $event) use (&$snapshots) {
            if ($event->product->stock_mode === StockMode::IGNORE) {
                $snapshots[] = ProductAttribute::query()->where('product_id', $event->product->id)->count();
            }
        });

        $this->batch([
            'id' => $id,
            'track_stock' => false,
            'attributes' => [['code' => 'shelf_life', 'name' => 'Срок хранения', 'value' => '6 месяцев']],
        ], 10);

        $this->assertNotEmpty($snapshots, 'смена режима остатков публикует ProductUpdated');
        $this->assertSame(1, $snapshots[0], 'ProductUpdated от смены режима уже содержит характеристики позиции');
    }
}
