<?php

namespace Tests\Feature\Transformers;

use App\Models\AdminSetting;
use App\Models\AssemblyZone;
use App\Models\Category;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Снапшот промокода заказа (тип + номинал) — курьерка обязана знать не только
 * процент, но и фиксированный номинал: у fixed-промокода discount_percent пуст,
 * и после сборки скидка пересчитывалась вслепую (прод-заказы 200 и 207,
 * 2026-09-10: минус 300 ₽ скидки, завышенный чек).
 */
class OrderPromocodeSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();
    }

    private function basePayload(array $extra = []): array
    {
        return array_merge([
            'id' => 'promo-snapshot-' . uniqid(),
            'shop_code' => 'default',
            'event_type' => 'created',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 800,
            'items_subtotal' => 1000,
            'shipping_total' => 100,
        ], $extra);
    }

    /** @test */
    public function from_event_maps_fixed_promocode_snapshot(): void
    {
        $attributes = $this->transformer->fromEvent($this->basePayload([
            'discount_total' => 300,
            'promocode' => [
                'code' => 'ЛАСТОЧКА300',
                'discount_percent' => null,
                'discount_type' => 'fixed',
                'discount_value' => 300,
            ],
        ]));

        $this->assertSame('ЛАСТОЧКА300', $attributes['promocode_code']);
        $this->assertSame('fixed', $attributes['promocode_type']);
        $this->assertSame(300.0, (float) $attributes['promocode_value']);
    }

    /** @test */
    public function from_event_maps_percent_promocode_snapshot(): void
    {
        $attributes = $this->transformer->fromEvent($this->basePayload([
            'discount_total' => 100,
            'promocode' => [
                'code' => 'SUMMER10',
                'discount_percent' => 10,
                'discount_type' => 'percent',
                'discount_value' => 10,
            ],
        ]));

        $this->assertSame('percent', $attributes['promocode_type']);
        $this->assertSame(10.0, (float) $attributes['promocode_value']);
        $this->assertSame(10, $attributes['discount_percent']);
    }

    /** @test */
    public function from_event_derives_fixed_snapshot_from_discount_total(): void
    {
        // Витрина форка ещё не обновлена: тип и номинал не пришли, но скидка
        // есть, а процента нет — значит промокод фиксированный.
        $attributes = $this->transformer->fromEvent($this->basePayload([
            'discount_total' => 300,
            'promocode' => ['code' => 'ЛАСТОЧКА300', 'discount_percent' => null],
        ]));

        $this->assertSame('fixed', $attributes['promocode_type']);
        $this->assertSame(300.0, (float) $attributes['promocode_value']);
    }

    /** @test */
    public function from_event_leaves_snapshot_empty_without_discount(): void
    {
        $attributes = $this->transformer->fromEvent($this->basePayload([
            'discount_total' => 0,
        ]));

        $this->assertArrayNotHasKey('promocode_type', $attributes);
        $this->assertArrayNotHasKey('promocode_value', $attributes);
    }

    /** @test */
    public function zone_split_copies_promocode_snapshot_to_children(): void
    {
        AdminSetting::setValue(
            'project_modules',
            json_encode(['assembly_zones' => ['enabled' => true]])
        );

        $shop = ShopFactory::new()->create([
            'code' => 'promo-snapshot-' . uniqid(),
            'title' => 'Promo Snapshot Shop',
            'commission' => 0,
            'deposit' => 0,
            'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time' => 30,
            'slot_max_orders' => 10,
            'type_id' => Type::DELIVERY_COURIER,
            'longitude' => 37.6,
            'latitude' => 55.7,
        ]);

        $client = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'uuid' => 'promo-snapshot-order-' . uniqid(),
            'client_id' => $client->id,
            'shop_id' => $shop->id,
            'type_id' => Type::DELIVERY_COURIER,
            'status_id' => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount' => 800,
            'origin_amount' => 700,
            'discount' => 300,
            'promocode_code' => 'ЛАСТОЧКА300',
            'promocode_type' => 'fixed',
            'promocode_value' => 300,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ]));

        foreach (['Снапшот-зона A', 'Снапшот-зона B'] as $index => $title) {
            $zone = AssemblyZone::create([
                'title' => $title . ' ' . uniqid(),
                'color' => '#' . substr(md5($title . uniqid()), 0, 6),
                'is_active' => true,
                'position' => 1,
            ]);

            $category = Category::create([
                'title' => 'Cat-' . uniqid(),
                'assembly_zone_id' => $zone->id,
                'is_active' => true,
            ]);

            $product = Product::withoutEvents(fn () => Product::create([
                'title' => 'Product-' . uniqid(),
                'articul' => 'art-' . uniqid(),
                'shop_id' => $shop->id,
                'status_id' => Status::CODE_ACTIVE,
                'unit_type_id' => Type::UNIT_PIECE,
            ]));
            $product->categories()->attach($category->id);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 500 - $index * 100,
            ]);
        }

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $method = new \ReflectionMethod($this->transformer, 'splitOrderByZones');
        $method->setAccessible(true);
        $method->invoke($this->transformer, $order);

        $children = Order::where('parent_order_id', $order->id)->get();
        $this->assertCount(2, $children);

        foreach ($children as $child) {
            $this->assertSame('fixed', $child->promocode_type, 'Подзаказ наследует тип промокода');
            $this->assertSame(300.0, (float) $child->promocode_value, 'Подзаказ наследует номинал промокода');
            $this->assertSame(0.0, (float) $child->discount, 'Скидка остаётся на родителе');
        }
    }

    /** @test */
    public function update_event_does_not_derive_snapshot_from_current_discount(): void
    {
        // order.updated из курьерки несёт уже пересчитанную скидку (могла быть
        // урезана до суммы товаров). Выводить из неё номинал промокода нельзя —
        // иначе он «залипнет» на урезанном значении.
        $attributes = $this->transformer->fromEvent($this->basePayload([
            'event_type' => 'updated',
            'discount_total' => 250,
            'promocode' => ['code' => 'ЛАСТОЧКА300', 'discount_percent' => null],
        ]));

        $this->assertArrayNotHasKey('promocode_type', $attributes);
        $this->assertArrayNotHasKey('promocode_value', $attributes);
    }
}
