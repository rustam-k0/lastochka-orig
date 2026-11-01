<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Строка «было до замены» (`_origin`, item_status=replaced) несёт количество в
 * наименьших единицах (г/мл/шт): витрина пишет его как есть с единицей
 * smallestMeasurementUnit (MID-267). Порции переводятся через размер порции.
 *
 * Прод 14.10: admin_back слал порции как есть — у клиента «Огурцы Местные 1 г»
 * вместо 500 г (33 из 86 замен весовых в октябре).
 */
class OrderReplacedOriginQuantityTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'origin-qty-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_ASSEMBLED,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
    }

    private function makeProduct(?int $infoWeight, ?int $infoWeightTypeId): int
    {
        return \DB::table('products')->insertGetId([
            'title' => 'Origin qty product ' . uniqid(),
            'articul' => 'OQ-' . uniqid(),
            'unit_type_id' => 1,
            'info_weight' => $infoWeight,
            'info_weight_type_id' => $infoWeightTypeId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function originQuantity(?int $originInfoWeight, ?int $originType, int $originPortions): int
    {
        $order = $this->makeOrder();
        $originProductId = $this->makeProduct($originInfoWeight, $originType);
        $productId = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);

        $itemId = \DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $productId,
            'origin_product_id' => $originProductId,
            'origin_price' => 100,
            'origin_quantity' => $originPortions,
            'quantity' => 1,
            'price' => 100,
            'price_during_order' => 100,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = app(OrderEventTransformer::class)->toEvent(
            $order->fresh()->load(['orderItems.product', 'orderItems.originProduct'])
        );
        $origin = collect($event['items'])->firstWhere('id', $itemId . '_origin');
        $this->assertNotNull($origin, 'строка _origin не попала в событие');

        return (int) $origin['quantity'];
    }

    /** @test */
    public function portioned_origin_is_sent_in_grams(): void
    {
        $this->assertSame(900, $this->originQuantity(300, Type::INFO_WEIGHT_GRAM, 3));
    }

    /** @test */
    public function kilogram_origin_is_sent_in_grams(): void
    {
        $this->assertSame(1000, $this->originQuantity(null, Type::INFO_WEIGHT_KILOGRAM, 1));
    }

    /** @test */
    public function piece_origin_is_sent_in_pieces(): void
    {
        $this->assertSame(2, $this->originQuantity(null, Type::INFO_WEIGHT_PIECE, 2));
    }

    /** @test */
    public function origin_without_unit_is_sent_in_pieces(): void
    {
        $this->assertSame(2, $this->originQuantity(null, null, 2));
    }

    /** @test */
    public function items_carry_portion_size_for_1c_export(): void
    {
        // 1c_parser переводит невзвешенную весовую позицию в кг: порции × portion_size.
        $order = $this->makeOrder();
        $portioned = $this->makeProduct(300, Type::INFO_WEIGHT_GRAM);
        $kilogram = $this->makeProduct(null, Type::INFO_WEIGHT_KILOGRAM);
        $piece = $this->makeProduct(null, Type::INFO_WEIGHT_PIECE);
        foreach ([$portioned, $kilogram, $piece] as $productId) {
            \DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $productId, 'quantity' => 2,
                'price' => 100, 'price_during_order' => 100, 'status_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $event = app(OrderEventTransformer::class)->toEvent($order->fresh()->load(['orderItems.product']));
        $sizes = collect($event['items'])->pluck('portion_size')->all();
        sort($sizes);

        $this->assertSame([1, 300, 1000], $sizes);
    }
}
