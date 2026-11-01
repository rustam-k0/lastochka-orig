<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\Models\SupplementValue;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupplementProductSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Прогоняет событие product.updated через трансформер (тот же паттерн, что ProductSupplementsSyncTest).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function consumeProductEvent(array $eventData): void
    {
        $transformer = new ProductEventTransformer;

        $product = Product::where('store_id', function ($query) use ($eventData) {
            $query->select('id')
                ->from('stores')
                ->where('code', $eventData['shop_code'])
                ->limit(1);
        })->where(function ($q) use ($eventData) {
            // Основной товар уже существует в БД; afterUpdate обновляет его добавки
        })->first();

        // Находим продукт по shop_code и id из eventData через EventSyncMetadata
        $store = Store::where('code', $eventData['shop_code'])->firstOrFail();

        $metadata = EventSyncMetadata::where('entity_type', 'products')
            ->where('external_id', $eventData['id'])
            ->first();

        if ($metadata) {
            $product = Product::find($metadata->entity_id);
        } else {
            $product = Product::where('store_id', $store->id)->first();
        }

        if ($product === null) {
            return;
        }

        $transformer->afterUpdate($product, $eventData);
    }

    public function test_incoming_product_event_creates_catalog_supplement(): void
    {
        $store = Store::factory()->create(['code' => 'sauce-shop']);

        // Создаём товар-добавку (соус)
        $sauce = Product::factory()->for($store)->create(['sku' => 'SAUCE-001']);

        // Регистрируем соус в EventSyncMetadata — именно так резолвится external_id
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $sauce->id,
            'external_id' => 'sauce-guid',
            'source' => '1c',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        // Создаём основной товар (тот, которому добавляем supplement)
        $main = Product::factory()->for($store)->create(['sku' => 'MAIN-001']);

        // Регистрируем основной товар в EventSyncMetadata
        EventSyncMetadata::create([
            'entity_type' => 'products',
            'entity_id' => $main->id,
            'external_id' => 'main-guid',
            'source' => '1c',
            'shop_code' => $store->code,
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subMinute(),
        ]);

        $transformer = new ProductEventTransformer;

        $transformer->afterUpdate($main, [
            'id' => 'main-guid',
            'shop_code' => $store->code,
            'source' => '1c',
            'supplements' => [[
                'title' => 'Соус', 'type' => 'many', 'is_required' => false,
                'values' => [[
                    'title' => 'Сырный', 'price' => null,
                    'product_external_id' => 'sauce-guid', 'max_quantity' => 3, 'track_stock' => null,
                ]],
            ]],
        ]);

        $value = SupplementValue::where('title', 'Сырный')->firstOrFail();
        $this->assertSame($sauce->id, $value->product_id);
        $this->assertSame(3, $value->max_quantity);
    }

    public function test_virtual_supplement_value_without_product_external_id_keeps_product_id_null(): void
    {
        $store = Store::factory()->create(['code' => 'virtual-shop']);
        $main = Product::factory()->for($store)->create(['sku' => 'MAIN-VIRTUAL']);

        $transformer = new ProductEventTransformer;

        $transformer->afterUpdate($main, [
            'id' => 'main-virtual-guid',
            'shop_code' => $store->code,
            'source' => '1c',
            'supplements' => [[
                'title' => 'Добавка без товара', 'type' => 'one', 'is_required' => false,
                'values' => [[
                    'title' => 'Виртуальное значение', 'price' => 50,
                    // product_external_id НЕ передаётся — виртуальная добавка
                ]],
            ]],
        ]);

        $value = SupplementValue::where('title', 'Виртуальное значение')->firstOrFail();
        $this->assertNull($value->product_id);
        $this->assertSame(50, $value->price);
    }

    public function test_unsynced_product_external_id_results_in_null_product_id(): void
    {
        $store = Store::factory()->create(['code' => 'unknown-shop']);
        $main = Product::factory()->for($store)->create(['sku' => 'MAIN-UNKNOWN']);

        $transformer = new ProductEventTransformer;

        // Товар с external_id 'nonexistent-guid' не зарегистрирован в EventSyncMetadata
        $transformer->afterUpdate($main, [
            'id' => 'main-unknown-guid',
            'shop_code' => $store->code,
            'source' => '1c',
            'supplements' => [[
                'title' => 'Добавка', 'type' => 'one', 'is_required' => false,
                'values' => [[
                    'title' => 'Незнакомый товар', 'price' => null,
                    'product_external_id' => 'nonexistent-guid',
                    'max_quantity' => 1, 'track_stock' => null,
                ]],
            ]],
        ]);

        // Value должен быть создан с product_id=null (не терять конфигурацию)
        $value = SupplementValue::where('title', 'Незнакомый товар')->firstOrFail();
        $this->assertNull($value->product_id);
        $this->assertSame(1, $value->max_quantity);
    }
}
