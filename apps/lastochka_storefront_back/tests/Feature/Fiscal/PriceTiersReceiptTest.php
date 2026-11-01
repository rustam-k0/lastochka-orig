<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Payment\Fiscal\AtolReceiptBuilder;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\TogglesPriceTiersModule;
use Tests\TestCase;

/**
 * Инвариант чека АТОЛ «цена × количество = сумма» для позиции на ступени цены:
 * при оформлении и после сборки на другое количество.
 */
final class PriceTiersReceiptTest extends TestCase
{
    use RefreshDatabase;
    use TogglesPriceTiersModule;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receiptLine(Order $order): array
    {
        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, (float) $order->fresh()->total);

        return $receipt['items'][0];
    }

    public function test_receipt_line_keeps_price_times_quantity_equals_sum(): void
    {
        $this->setPriceTiersModule(true);

        $user = User::factory()->create();
        $store = Store::factory()->create([
            'code' => 'TIERS_RCPT', 'is_active' => true, 'is_pickup_enabled' => true, 'is_open_24_7' => true,
            'slot_duration' => 60, 'min_pickup_order_amount' => 0, 'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'], 'is_bonus_enabled' => false,
            'delivery_price' => 0, 'free_delivery_from' => null,
        ]);
        $product = Product::factory()->for($store)->create([
            'title' => 'Вино красное', 'price' => 279, 'quantity' => 100, 'quantity_step' => 1, 'measurement_unit' => 'piece',
        ]);
        $this->seedPriceTiers($product, $store->id, [
            ['min_quantity' => 1, 'price' => 279],
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create(['store_id' => $store->id, 'quantity' => 5, 'price' => 279]);

        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $order = Order::findOrFail(app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ))['orderId']);

        $line = $this->receiptLine($order);
        $this->assertSame(199.0, $line['price']);
        $this->assertSame(5.0, $line['quantity']);
        $this->assertSame(995.0, $line['sum']);
        $this->assertEqualsWithDelta($line['sum'], $line['price'] * $line['quantity'], 0.001);

        // Сборщик положил 3 вместо 5: ступень 249, курьерка прислала цену за единицу и итог строки.
        $item = $order->items()->firstOrFail();
        (new OrderEventTransformer)->afterUpdate($order, [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'TIERS_RCPT',
            'status' => 'assembled',
            'items' => [[
                'id' => (string) $item->id,
                'source' => config('rabbitmq-events.source'),
                'product_name' => $item->title,
                'quantity' => 3,
                'unit_price' => 249.0,
                'unit_price_effective' => 249.0,
                'line_total' => 747.0,
                'item_status' => 'confirmed',
            ]],
        ]);

        $line = $this->receiptLine($order);
        $this->assertSame(249.0, $line['price']);
        $this->assertSame(3.0, $line['quantity']);
        $this->assertSame(747.0, $line['sum']);
        $this->assertEqualsWithDelta($line['sum'], $line['price'] * $line['quantity'], 0.001);
    }
}
