<?php

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Shop;
use App\Transformers\ProductEventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Пересланное `product.updated` по товару, которого курьерка не знает.
 *
 * Инцидент Foodlink 16.09.2026: Bagisto переслал 888 обновлений с чужим
 * источником (source=retailbridge, event_source=bagisto). Привязки под этим
 * ключом не было, консьюмер создал 741 дубль товаров магазина. Создавать товар
 * по `updated` может только владелец идентичности (event_source === source),
 * это и есть самовосстановление (resync 1С). Пересланное событие пропускается,
 * и после пропуска не должна оставаться привязка без entity_id: иначе
 * следующее событие создаст товар веткой «Orphan metadata» в обход хуков.
 */
class ProductForwardedUpdateCreateTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title' => 'fwd-shop-'.uniqid(),
            'code' => 'fwd-'.uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function consumer(): EventConsumer
    {
        return new EventConsumer('products', [
            'model' => Product::class,
            'transformer' => ProductEventTransformer::class,
        ]);
    }

    private function event(Shop $shop, string $id, string $source, string $eventSource, string $title): array
    {
        return [
            'event_id' => 'fwd-'.uniqid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'event_source' => $eventSource,
            'id' => $id,
            'source' => $source,
            'shop_code' => $shop->code,
            'sku' => 'FWD-'.uniqid(),
            'title' => $title,
            'price' => 661,
            'unit_type' => 'piece',
            'active' => true,
        ];
    }

    public function test_forwarded_update_for_unknown_product_does_not_create_it(): void
    {
        $shop = $this->makeShop();
        $title = 'Пересланный товар '.uniqid();
        $id = 'fwd-19-'.uniqid();

        $result = $this->consumer()->processEvent($this->event($shop, $id, 'retailbridge', 'bagisto', $title));

        $this->assertSame('ack', $result);
        $this->assertSame(0, Product::withTrashed()->where('title', $title)->count());
        $this->assertSame(0, EventSyncMetadata::query()
            ->where('entity_type', 'products')->where('source', 'retailbridge')->where('external_id', $id)->count());
    }

    public function test_repeated_forwarded_update_does_not_create_product_via_orphan_metadata(): void
    {
        $shop = $this->makeShop();
        $title = 'Повтор пересланного '.uniqid();
        $id = 'fwd-20-'.uniqid();

        $this->consumer()->processEvent($this->event($shop, $id, 'retailbridge', 'bagisto', $title));
        $this->consumer()->processEvent($this->event($shop, $id, 'retailbridge', 'bagisto', $title));

        $this->assertSame(0, Product::withTrashed()->where('title', $title)->count());
    }

    public function test_owner_update_for_unknown_product_still_creates_it(): void
    {
        $shop = $this->makeShop();
        $title = 'Самовосстановление '.uniqid();

        $result = $this->consumer()->processEvent($this->event($shop, 'guid-'.uniqid(), '1c', '1c', $title));

        $this->assertSame('ack', $result);
        $this->assertSame(1, Product::query()->where('title', $title)->where('shop_id', $shop->id)->count());
    }
}
