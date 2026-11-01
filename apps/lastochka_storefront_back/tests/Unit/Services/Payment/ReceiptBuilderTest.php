<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\ReceiptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Чек для онлайн-кассы YooKassa (54-ФЗ).
 *
 * Главный инвариант: сумма позиций чека ДОЛЖНА совпадать с суммой платежа
 * до копейки, иначе YooKassa отклоняет запрос ("Receipt is missing or illegal"
 * либо несоответствие сумм).
 */
final class ReceiptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ReceiptBuilder();
    }

    private function orderWithItems(User $user, array $orderAttrs, array $items): Order
    {
        $order = Order::factory()->for($user)->for(Store::factory())->create($orderAttrs);

        foreach ($items as $item) {
            $order->items()->create([
                'title' => $item['title'],
                'sku' => 'SKU-'.$item['title'],
                'price' => $item['price'],
                'quantity' => $item['quantity'] ?? 1,
                'total' => $item['total'],
                'created_at' => now(),
            ]);
        }

        return $order->fresh(['items', 'user']);
    }

    public function test_for_order_items_sum_equals_total_without_discount(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567', 'email' => 'a@b.ru']);

        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 100, 'total' => 400,
        ], [
            ['title' => 'Товар A', 'price' => 200, 'total' => 200],
            ['title' => 'Товар B', 'price' => 100, 'total' => 100],
        ]);

        $receipt = $this->builder->forOrder($order);

        $this->assertEqualsWithDelta(400.0, $receipt->total(), 0.001);

        $arr = $receipt->toArray();
        $this->assertSame('79991234567', $arr['customer']['phone']);
        $this->assertSame('a@b.ru', $arr['customer']['email']);
        $this->assertSame(4, $arr['items'][0]['vat_code']);
        $this->assertArrayNotHasKey('tax_system_code', $arr);
        // Доставка отдельной позицией.
        $this->assertSame('Доставка', $arr['items'][count($arr['items']) - 1]['description']);
    }

    public function test_for_order_distributes_discount_and_bonus_to_match_total(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        // subtotal 1000, discount 150, bonus_spent 50, delivery 0 → total 800.
        $order = $this->orderWithItems($user, [
            'subtotal' => 1000, 'discount' => 150, 'bonus_spent' => 50, 'delivery_cost' => 0, 'total' => 800,
        ], [
            ['title' => 'Товар A', 'price' => 600, 'total' => 600],
            ['title' => 'Товар B', 'price' => 400, 'total' => 400],
        ]);

        $receipt = $this->builder->forOrder($order);

        $this->assertEqualsWithDelta(800.0, $receipt->total(), 0.001);
        foreach ($receipt->items as $item) {
            $this->assertGreaterThan(0.0, $item->amount, 'Позиция чека не должна быть нулевой');
        }
    }

    public function test_for_order_sums_exactly_on_fractional_split(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        // 3 позиции 33/33/34 (итого 100), discount 10 → total 90 (дробное распределение).
        $order = $this->orderWithItems($user, [
            'subtotal' => 100, 'discount' => 10, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 90,
        ], [
            ['title' => 'A', 'price' => 33, 'total' => 33],
            ['title' => 'B', 'price' => 33, 'total' => 33],
            ['title' => 'C', 'price' => 34, 'total' => 34],
        ]);

        $receipt = $this->builder->forOrder($order);

        // Сумма до копейки строго равна сумме платежа.
        $this->assertSame('90.00', number_format($receipt->total(), 2, '.', ''));
    }

    public function test_normalize_phone_with_8_prefix(): void
    {
        $user = User::factory()->create(['phone' => '89991234567', 'email' => null]);

        $order = $this->orderWithItems($user, [
            'subtotal' => 100, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 100,
        ], [
            ['title' => 'A', 'price' => 100, 'total' => 100],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();

        $this->assertSame('79991234567', $arr['customer']['phone']);
        $this->assertArrayNotHasKey('email', $arr['customer']);
    }

    public function test_for_bind_single_service_item(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        $receipt = $this->builder->forBind($user);
        $arr = $receipt->toArray();

        $this->assertCount(1, $arr['items']);
        $this->assertSame('1.00', $arr['items'][0]['amount']['value']);
        $this->assertSame(4, $arr['items'][0]['vat_code']);
        $this->assertSame('service', $arr['items'][0]['payment_subject']);
        $this->assertSame('79991234567', $arr['customer']['phone']);
    }

    public function test_for_client_order_sum_equals_total_amount(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        $order1 = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 100, 'total' => 400,
        ], [['title' => 'A', 'price' => 300, 'total' => 300]]);

        $order2 = $this->orderWithItems($user, [
            'subtotal' => 500, 'discount' => 50, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 450,
        ], [['title' => 'B', 'price' => 500, 'total' => 500]]);

        $receipt = $this->builder->forClientOrder([$order1, $order2], 850.0);

        $this->assertEqualsWithDelta(850.0, $receipt->total(), 0.001);
    }

    public function test_tax_system_code_from_config(): void
    {
        config(['services.yookassa.tax_system_code' => 2]);

        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 100, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 100,
        ], [['title' => 'A', 'price' => 100, 'total' => 100]]);

        $arr = $this->builder->forOrder($order)->toArray();

        $this->assertSame(2, $arr['tax_system_code']);
    }

    private function setPaymentCredentials(array $yookassa): void
    {
        IntegrationConfig::create([
            'integration_type' => 'payment',
            'driver' => 'yookassa',
            'credentials' => ['yookassa' => $yookassa],
            'is_active' => true,
        ]);
    }

    public function test_for_order_returns_null_when_send_receipt_disabled(): void
    {
        $this->setPaymentCredentials(['send_receipt' => false]);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $this->assertNull($this->builder->forOrder($order));
    }

    public function test_for_bind_returns_null_when_send_receipt_disabled(): void
    {
        $this->setPaymentCredentials(['send_receipt' => false]);
        $user = User::factory()->create(['phone' => '+79991234567']);

        $this->assertNull($this->builder->forBind($user));
    }

    public function test_for_order_builds_receipt_when_send_receipt_absent(): void
    {
        // credentials не заданы вовсе → дефолт true → чек строится
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $this->assertNotNull($this->builder->forOrder($order));
    }

    public function test_vat_code_from_credentials_applied_to_items(): void
    {
        $this->setPaymentCredentials(['vat_code' => '3']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertSame(3, $arr['items'][0]['vat_code']);
    }

    public function test_empty_vat_code_falls_back_to_config_default(): void
    {
        $this->setPaymentCredentials(['vat_code' => '']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertSame(4, $arr['items'][0]['vat_code']); // config default
    }

    public function test_tax_system_code_from_credentials_applied(): void
    {
        $this->setPaymentCredentials(['tax_system_code' => '2']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertSame(2, $arr['tax_system_code']);
    }

    public function test_empty_tax_system_code_not_sent(): void
    {
        $this->setPaymentCredentials(['tax_system_code' => '']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertArrayNotHasKey('tax_system_code', $arr);
    }

    public function test_tax_system_code_none_sentinel_not_sent(): void
    {
        $this->setPaymentCredentials(['tax_system_code' => 'none']);
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = $this->orderWithItems($user, [
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ], [
            ['title' => 'Товар A', 'price' => 300, 'total' => 300],
        ]);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertArrayNotHasKey('tax_system_code', $arr);
    }

    public function test_receipt_uses_per_item_vat_rate(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = Order::factory()->for($user)->for(Store::factory())->create([
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 300,
        ]);
        $order->items()->create([
            'title' => 'Товар A', 'sku' => 'A', 'price' => 300, 'quantity' => 1,
            'total' => 300, 'vat_rate' => 3, 'created_at' => now(),
        ]);
        $order = $order->fresh(['items', 'user']);

        $arr = $this->builder->forOrder($order)->toArray();
        $this->assertSame(3, $arr['items'][0]['vat_code']);
    }

    /**
     * Отменённые и заменённые при сборке позиции не должны попадать в чек:
     * `orders.total` считается только по активным позициям
     * (`OrderEventTransformer` исключает статусы cancelled/replaced), поэтому
     * позиции по полному составу разъезжаются с суммой платежа, а
     * `distribute()` молча размазывает разницу по всем строкам — в чеке
     * оказываются не отданные клиенту товары и заниженные цены у отданных.
     */
    public function test_cancelled_and_replaced_items_are_excluded_from_receipt(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = Order::factory()->for($user)->for(Store::factory())->create([
            'subtotal' => 250, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 0, 'total' => 250,
        ]);
        $order->items()->createMany([
            ['title' => 'Отдан клиенту', 'sku' => 'A', 'price' => 100, 'quantity' => 2, 'total' => 200,
                'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Отменён сборщиком', 'sku' => 'B', 'price' => 150, 'quantity' => 1, 'total' => 150,
                'status' => 'cancelled', 'created_at' => now()],
            ['title' => 'Заменён на другой', 'sku' => 'C', 'price' => 80, 'quantity' => 1, 'total' => 80,
                'status' => 'replaced', 'created_at' => now()],
            ['title' => 'Без статуса', 'sku' => 'D', 'price' => 50, 'quantity' => 1, 'total' => 50,
                'status' => null, 'created_at' => now()],
        ]);
        $order = $order->fresh(['items', 'user']);

        $arr = $this->builder->forOrder($order)->toArray();

        $this->assertSame(['Отдан клиенту', 'Без статуса'], array_column($arr['items'], 'description'));
        $this->assertSame('200.00', $arr['items'][0]['amount']['value']);
        $this->assertSame('50.00', $arr['items'][1]['amount']['value']);
    }

    public function test_receipt_falls_back_to_global_vat_when_item_has_no_rate(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);
        $order = Order::factory()->for($user)->for(Store::factory())->create([
            'subtotal' => 300, 'discount' => 0, 'bonus_spent' => 0, 'delivery_cost' => 100, 'total' => 400,
        ]);
        $order->items()->create([
            'title' => 'Товар A', 'sku' => 'A', 'price' => 300, 'quantity' => 1,
            'total' => 300, 'vat_rate' => null, 'created_at' => now(),
        ]);
        $order = $order->fresh(['items', 'user']);

        $arr = $this->builder->forOrder($order)->toArray();
        // товар без vat_rate → глобальный дефолт 4; доставка тоже глобальный 4
        $this->assertSame(4, $arr['items'][0]['vat_code']);
        $this->assertSame('Доставка', $arr['items'][count($arr['items']) - 1]['description']);
        $this->assertSame(4, $arr['items'][count($arr['items']) - 1]['vat_code']);
    }
}
