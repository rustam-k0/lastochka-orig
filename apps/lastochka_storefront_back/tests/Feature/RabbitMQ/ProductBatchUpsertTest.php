<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * product.batch_updated: каждая позиция делегируется в штатный
 * EventConsumer::processEvent (upsert со всеми механиками пайплайна).
 * event_type позиции = 'updated' (create-or-update).
 */
final class ProductBatchUpsertTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function handle(array $overrides = []): string
    {
        return app(ProductBatchHandler::class)->handle(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_type' => 'batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => 'BT_001',
            'items' => [],
        ], $overrides));
    }

    public function test_creates_new_and_updates_existing_in_one_batch(): void
    {
        Store::factory()->create(['code' => 'BT_001']);

        // существующий товар — через одиночное created (сид)
        (new EventConsumer('products', config('rabbitmq-events.entities.products')))
            ->processEvent([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'created',
                'event_timestamp' => now()->subHour()->toIso8601String(),
                'id' => 'ext-old',
                'source' => '1c',
                'shop_code' => 'BT_001',
                'sku' => 'SKU-OLD',
                'title' => 'Старое название',
                'price' => 50.00,
                'active' => true,
            ]);

        $result = $this->handle([
            'items' => [
                [
                    'id' => 'ext-old',
                    'sku' => 'SKU-OLD',
                    'title' => 'Новое название',
                    'price' => 55.00,
                    'active' => true,
                ],
                [
                    'id' => 'ext-new',
                    'sku' => 'SKU-NEW',
                    'title' => 'Новый товар из батча',
                    'price' => 149.99,
                    'quantity' => 10,
                    'active' => true,
                ],
            ],
        ]);

        $this->assertSame('ack', $result);

        $this->assertSame('Новое название', Product::where('sku', 'SKU-OLD')->firstOrFail()->title);

        $created = Product::where('sku', 'SKU-NEW')->firstOrFail();
        $this->assertSame('Новый товар из батча', $created->title);

        // metadata новой позиции создана штатным пайплайном
        $meta = EventSyncMetadata::where('entity_type', 'products')
            ->where('source', '1c')
            ->where('shop_code', 'BT_001')
            ->where('external_id', 'ext-new')
            ->firstOrFail();
        $this->assertSame($created->id, (int) $meta->entity_id);
    }

    public function test_redelivery_of_batch_message_is_full_noop(): void
    {
        Store::factory()->create(['code' => 'BT_001']);

        // Один и тот же payload (фиксированный event_id) отправляем дважды —
        // имитация retry/redelivery пакета. Второй вызов должен быть
        // полным no-op: товар создаётся один раз, карточка не меняется.
        $payload = [
            'event_id' => (string) Str::uuid(),
            'items' => [
                [
                    'id' => 'ext-redelivery',
                    'sku' => 'SKU-REDELIVERY',
                    'title' => 'Товар для проверки повторной доставки',
                    'price' => 99.99,
                    'active' => true,
                ],
            ],
        ];

        $first = $this->handle($payload);
        $this->assertSame('ack', $first);

        $second = $this->handle($payload);
        $this->assertSame('ack', $second);

        $this->assertSame(1, Product::where('sku', 'SKU-REDELIVERY')->count());
        $this->assertSame(
            'Товар для проверки повторной доставки',
            Product::where('sku', 'SKU-REDELIVERY')->firstOrFail()->title
        );
    }

    public function test_duplicate_id_in_batch_first_wins(): void
    {
        Store::factory()->create(['code' => 'BT_001']);

        $this->handle([
            'items' => [
                ['id' => 'ext-dup', 'sku' => 'SKU-DUP', 'title' => 'Первый', 'price' => 10.00, 'active' => true],
                ['id' => 'ext-dup', 'sku' => 'SKU-DUP', 'title' => 'Второй', 'price' => 20.00, 'active' => true],
            ],
        ]);

        $this->assertSame(1, Product::where('sku', 'SKU-DUP')->count());
        $this->assertSame('Первый', Product::where('sku', 'SKU-DUP')->firstOrFail()->title);
    }

    public function test_invalid_item_does_not_fail_batch(): void
    {
        Store::factory()->create(['code' => 'BT_001']);

        $result = $this->handle([
            'items' => [
                ['id' => 'ext-broken'], // нет title — не пройдёт validate пайплайна
                ['id' => 'ext-ok', 'sku' => 'SKU-OK', 'title' => 'Валидный', 'price' => 10.00, 'active' => true],
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(1, Product::where('sku', 'SKU-OK')->count());
        $this->assertSame(0, Product::where('title', '')->count());
    }
}
