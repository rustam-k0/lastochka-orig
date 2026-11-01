<?php

namespace Tests\Feature\Delivery;

use App\Jobs\Delivery\CreateCdekShipmentJob;
use App\Jobs\Delivery\CreateRussianPostShipmentJob;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Вес посылки СДЭК / Почты: actual_weight — итог строки в граммах, его нельзя
 * умножать на порции (528 г × 2 порции = 1056 г), и правило «больше 50 — граммы,
 * иначе кг» превращало 40 г в 40 кг.
 */
class ShipmentWeightUnitsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function order(?int $totalWeight): Order
    {
        $clientId = Client::create(['phone' => '7' . random_int(1000000000, 9999999999), 'password' => bcrypt('x')])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'ship-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_ASSEMBLED,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'total_weight' => $totalWeight,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));
    }

    private function addItem(Order $order, array $product, array $item): void
    {
        $productId = Product::create(array_merge([
            'title' => 'P ' . uniqid(),
            'articul' => 'SH-' . uniqid(),
            'status_id' => 1,
        ], $product))->id;

        \DB::table('order_items')->insert(array_merge([
            'order_id' => $order->id,
            'product_id' => $productId,
            'price' => 100,
            'price_during_order' => 100,
            'status_id' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ], $item));
    }

    private function fixture(?int $totalWeight): Order
    {
        $order = $this->order($totalWeight);
        // Филе: 2 порции по 300 г, взвешено 528 г
        $this->addItem($order, [
            'unit_type_id' => Type::UNIT_FOOD, 'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM, 'info_weight' => 300,
        ], ['quantity' => 2, 'actual_weight' => 528]);
        // Специи: 2 шт по 40 г
        $this->addItem($order, [
            'unit_type_id' => Type::UNIT_PIECE, 'base_unit_type_id' => Type::INFO_WEIGHT_PIECE,
            'info_weight_type_id' => Type::INFO_WEIGHT_PIECE, 'weight' => 40,
        ], ['quantity' => 2]);

        return $order->fresh()->load('orderItems.product');
    }

    private function invokePrivate(object $job, string $method, Order $order): mixed
    {
        $ref = new \ReflectionMethod($job, $method);
        $ref->setAccessible(true);

        return $ref->invoke($job, $order);
    }

    public function test_cdek_package_weight_sums_lines_in_grams(): void
    {
        $package = $this->invokePrivate(new CreateCdekShipmentJob(1), 'buildPackage', $this->fixture(null));

        // 528 г + 2 × 40 г
        $this->assertSame(608, $package['weight']);
    }

    public function test_cdek_package_weight_prefers_order_weight_snapshot(): void
    {
        $package = $this->invokePrivate(new CreateCdekShipmentJob(1), 'buildPackage', $this->fixture(650));

        $this->assertSame(650, $package['weight']);
    }

    public function test_russian_post_items_give_grams_per_unit(): void
    {
        $stub = $this->invokePrivate(new CreateRussianPostShipmentJob(1), 'buildShipmentStub', $this->fixture(null));

        $lines = collect($stub->items)->map(fn ($i) => [(int) $i->weight, (int) $i->quantity])->sortBy(0)->values()->all();

        // Взвешенная строка — её вес одной «штукой», штучная — вес штуки × штуки
        $this->assertSame([[40, 2], [528, 1]], $lines);
    }
}
