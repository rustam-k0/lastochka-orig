<?php

declare(strict_types=1);

namespace Tests\Unit\RabbitMQ\Transformers;

use App\RabbitMQ\Transformers\ProductGroupEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGroupEventTransformerPositionTest extends TestCase
{
    // Нужен только для того, чтобы существовали таблицы event_sync_metadata
    // и stores — fromEvent() вызывает resolveStoreId() ДО маппинга sort_order,
    // и без таблиц упадёт на SQLSTATE no such table. Сами записи не создаём:
    // store_id=null не мешает проверке маппинга position.
    use RefreshDatabase;

    private ProductGroupEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductGroupEventTransformer();
    }

    public function test_maps_sort_order_to_position(): void
    {
        $attrs = $this->transformer->fromEvent($this->basePayload(['sort_order' => 7]));

        $this->assertSame(7, $attrs['position']);
    }

    public function test_does_not_set_position_when_sort_order_absent(): void
    {
        $data = $this->basePayload();
        unset($data['sort_order']);

        $attrs = $this->transformer->fromEvent($data);

        // Conditional set: ключ position должен отсутствовать,
        // чтобы не затереть существующее значение при partial update.
        $this->assertArrayNotHasKey('position', $attrs);
    }

    public function test_handles_zero_sort_order(): void
    {
        $attrs = $this->transformer->fromEvent($this->basePayload(['sort_order' => 0]));

        $this->assertSame(0, $attrs['position']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt-' . uniqid(),
            'id' => 100,
            'source' => 'admin_back',
            'shop_id' => 'doesnt-matter',
            'shop_source' => 'doesnt-matter',
            'title' => 'Group A',
        ], $overrides);
    }
}
