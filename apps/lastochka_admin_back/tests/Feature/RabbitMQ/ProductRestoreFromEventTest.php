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
 * Регресс на восстановление мягко удалённого товара по событию из 1С.
 *
 * Баг: EventConsumer проверял поддержку мягкого удаления через
 * method_exists($modelClass, 'withTrashed'). withTrashed() — макрос Builder'а,
 * который регистрирует SoftDeletingScope::extend(), а не метод модели, поэтому
 * проверка всегда возвращала false и ветка withTrashed() была мёртвым кодом.
 * В результате любое событие по уже удалённой сущности падало с
 * «Entity not found: N», и товар, удалённый по признаку DelProd, невозможно
 * было вернуть, даже когда 1С снимала признак.
 *
 * Обнаружено на dev при e2e-прогоне обмена 2026-07-31.
 */
class ProductRestoreFromEventTest extends TestCase
{
    use DatabaseTransactions;

    private const EXTERNAL_ID = 'guid-restore-regression-test';

    /**
     * Реальный магазин обязателен: afterUpdate резолвит shop_id по shop_code и
     * для неизвестного кода подставляет несуществующий id, что ловит FK
     * products.shop_id → shops.id.
     */
    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title' => 'regr-shop-'.uniqid(),
            'code' => 'regr-'.uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    public function test_soft_deleted_product_is_restored_by_update_event(): void
    {
        $shop = $this->makeShop();

        $product = Product::query()->create([
            'shop_id' => $shop->id,
            'title' => 'Товар для регресса восстановления',
            'articul' => 'REGR-'.uniqid(),
            'unit_type_id' => 2,
            'status_id' => 3,
        ]);
        $product->delete();

        $this->assertNotNull($product->fresh()->deleted_at, 'предусловие: товар мягко удалён');

        EventSyncMetadata::query()->create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => self::EXTERNAL_ID,
            'source' => '1c',
            'shop_code' => $shop->code,
        ]);

        $consumer = new EventConsumer('products', [
            'model' => Product::class,
            'transformer' => ProductEventTransformer::class,
        ]);

        $result = $consumer->processEvent([
            'event_id' => 'restore-regression-'.uniqid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => self::EXTERNAL_ID,
            'source' => '1c',
            'shop_code' => $shop->code,
            'sku' => $product->articul,
            'title' => 'Товар для регресса восстановления',
            'price' => 178,
            'unit_type' => 'piece',
            // Явный null = 1С сняла признак DelProd, товар нужно вернуть.
            'deleted_at' => null,
        ]);

        $this->assertSame('ack', $result);
        $this->assertNull(
            Product::withTrashed()->find($product->id)->deleted_at,
            'товар должен быть восстановлен событием с deleted_at = null'
        );
    }

    public function test_live_product_is_soft_deleted_by_event_with_deleted_at(): void
    {
        $shop = $this->makeShop();

        $product = Product::query()->create([
            'shop_id' => $shop->id,
            'title' => 'Товар для регресса удаления',
            'articul' => 'REGR-'.uniqid(),
            'unit_type_id' => 2,
            'status_id' => 3,
        ]);

        EventSyncMetadata::query()->create([
            'entity_type' => 'products',
            'entity_id' => $product->id,
            'external_id' => self::EXTERNAL_ID.'-del',
            'source' => '1c',
            'shop_code' => $shop->code,
        ]);

        $consumer = new EventConsumer('products', [
            'model' => Product::class,
            'transformer' => ProductEventTransformer::class,
        ]);

        $result = $consumer->processEvent([
            'event_id' => 'delete-regression-'.uniqid(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => self::EXTERNAL_ID.'-del',
            'source' => '1c',
            'shop_code' => $shop->code,
            'sku' => $product->articul,
            'title' => 'Товар для регресса удаления',
            'price' => 178,
            'unit_type' => 'piece',
            'deleted_at' => '2026-07-31T20:13:39+03:00',
        ]);

        $this->assertSame('ack', $result);
        $this->assertNotNull(
            Product::withTrashed()->find($product->id)->deleted_at,
            'товар должен быть мягко удалён событием с непустым deleted_at'
        );
    }
}
