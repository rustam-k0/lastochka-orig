<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Enums\PaymentTransactionStatus;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentReceipt;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Http\Resources\Api\V1\OrderReceiptResource;
use App\Services\Payment\Fiscal\AtolReceiptBuilder;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ставка НДС 22% (ФЗ от 28.11.2025 №425-ФЗ, действует с 01.01.2026).
 *
 * `lastochka_1c_parser::normalizeNdsToVatCode` кодирует её как vat_code 11
 * (расчётная 22/122 — код 12) и присылает в поле `nds`. storefront этих кодов
 * не знал: `Vat::rateFromCode` отдавал 0%, а `AtolReceiptBuilder::vatType`
 * проваливался в дефолт из настроек интеграции — в чек уходило «без НДС».
 * На проде это 10 525 товаров из 13 183.
 *
 * Приём ставки кассой проверен на тестовом стенде АТОЛ (ФФД 1.05, APIv4):
 * vat22 → ФД 99916, vat122 → ФД 103644, оба status=done.
 */
final class VatRate22Test extends TestCase
{
    use RefreshDatabase;

    public function test_rate_from_code_knows_22_percent(): void
    {
        $this->assertSame(22, Vat::rateFromCode(11));
        $this->assertSame(22, Vat::rateFromCode(12));
        // Прежние ставки не задеты
        $this->assertSame(10, Vat::rateFromCode(3));
        $this->assertSame(20, Vat::rateFromCode(4));
        $this->assertSame(0, Vat::rateFromCode(1));
    }

    public function test_included_vat_for_22_percent(): void
    {
        // НДС «в том числе»: 122 × 22 / 122 = 22.00
        $this->assertSame(22.0, Vat::includedVat(122.0, 22));
        $this->assertSame(140.78, Vat::includedVat(780.70, 22));
    }

    public function test_atol_builder_maps_22_percent_codes(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => [
                'mode' => 'test', 'ffd_version' => '1.05', 'login' => 'l', 'password' => 'p',
                'group_code' => 'g', 'inn' => '1234567890', 'sno' => 'osn',
                'payment_address' => 'https://shop.test/', 'vat' => 'none',
            ],
        ]);

        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembled, 'payment_type' => PaymentType::Card,
            'subtotal' => 244, 'total' => 244, 'items_count' => 2,
            'shipping_method' => ShippingMethod::Courier,
        ]);

        foreach ([[11, 'Товар 22%'], [12, 'Товар 22/122']] as [$code, $title]) {
            $product = Product::factory()->create(['store_id' => $store->id, 'vat_rate' => $code]);
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id,
                'title' => $title, 'sku' => 'SKU-'.$code, 'quantity' => 1,
                'price' => 122, 'total' => 122, 'vat_rate' => $code,
            ]);
        }

        $receipt = app(AtolReceiptBuilder::class)
            ->build($order->fresh(), PaymentType::Card, 244.0);

        $vatTypes = array_column(array_column($receipt['items'], 'vat'), 'type');

        $this->assertContains('vat22', $vatTypes);
        $this->assertContains('vat122', $vatTypes);
        // Дефолт из настроек («без НДС») больше не подменяет реальную ставку
        $this->assertNotContains('none', $vatTypes);
    }

    public function test_receipt_resource_exposes_22_percent_and_vat_total(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Delivered, 'payment_type' => PaymentType::Card,
            'subtotal' => 157, 'total' => 157, 'items_count' => 2,
            'shipping_method' => ShippingMethod::Courier,
        ]);

        $receipt = PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'ext-vat-22',
            'type' => 'payment',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 157,
            'items' => [
                ['description' => 'Кофе', 'quantity' => '1.00', 'amount' => '122.00', 'vat_code' => 11],
                ['description' => 'Хлеб', 'quantity' => '1.00', 'amount' => '35.00', 'vat_code' => 3],
            ],
            'raw' => [],
        ]);

        $json = OrderReceiptResource::make($receipt)->toArray(request());

        $rates = array_column($json['items'], 'vat_rate');
        $this->assertSame(['22', '10'], $rates);

        $amounts = array_column($json['items'], 'vat_amount');
        $this->assertSame('22.00', $amounts[0]);   // 122 × 22/122
        $this->assertSame('3.18', $amounts[1]);    // 35 × 10/110

        // Свод по ставкам: две группы, «общей ставки заказа» не существует
        $this->assertCount(2, $json['vat']);

        // Итого НДС — сумма по всем ставкам, чтобы клиент не складывал сам
        $this->assertSame('25.18', $json['vatTotal']);
    }
}
