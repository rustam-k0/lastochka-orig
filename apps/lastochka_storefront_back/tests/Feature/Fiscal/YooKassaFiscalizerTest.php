<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\Payment\Fiscal\YooKassaFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class YooKassaFiscalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_sale_is_noop_handled_by_acquiring(): void
    {
        $order = Order::factory()->create(['total' => 100]);

        $result = app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Card, 100.0);

        $this->assertSame('succeeded', $result->status);
    }

    public function test_cash_sale_creates_independent_receipt(): void
    {
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $fake);

        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $result = app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $this->assertSame('pending', $result->status);
        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id,
            'provider' => 'yookassa',
            'status' => 'pending',
        ]);
        $this->assertCount(1, $fake->callsFor('createIndependentReceipt'));
    }

    /**
     * LASTOCHKI-JAMI-11651 fix: PaymentReceipt.items независимого (cash) чека
     * ЮKassa должен храниться в едином (unified) виде — `description`/`amount`
     * (СКАЛЯР, не вложенный `{value, currency}`)/`quantity`/`vat_code` — том же,
     * что используют онлайн-чеки ЮKassa (`ReceiptSyncService::mapItems()`) и АТОЛ
     * (`AtolFiscalizer::unifyItems()`). Иначе `OrderReceiptResource::withVat()`
     * (общий для всех провайдеров) считает `(float)` от массива и получает vat=0.
     */
    public function test_cash_sale_stores_items_in_unified_shape_not_raw_api_shape(): void
    {
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $fake);

        $order = Order::factory()->hasItems(1)->create(['total' => 100, 'delivery_cost' => 0]);

        app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $receipt = PaymentReceipt::where('order_id', $order->id)
            ->where('provider', ReceiptProvider::Yookassa->value)
            ->firstOrFail();
        $item = $receipt->items[0];

        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('amount', $item);
        $this->assertArrayHasKey('quantity', $item);
        $this->assertArrayHasKey('vat_code', $item);
        $this->assertIsNotArray($item['amount']);
        $this->assertIsNotArray($item['quantity']);
        $this->assertArrayNotHasKey('payment_subject', $item);
        $this->assertArrayNotHasKey('payment_mode', $item);
        $this->assertSame('100.00', $item['amount']);
        // Стоимости строки в формате ЮKassa нет — считаем произведением при сохранении,
        // чтобы клиент читал `sum` одинаково у обоих провайдеров.
        $this->assertSame('100.00', $item['sum']);
        // Позиция без собственного vat_rate → дефолт services.yookassa.vat_code.
        // Дефолт намеренно оставлен 4 (20%): его смена меняла бы чеки таких
        // позиций молча, переключение делается через YOOKASSA_VAT_CODE.
        $this->assertSame(4, $item['vat_code']);
    }

    /**
     * LASTOCHKI-JAMI-11651 fix: конец в конец — независимый (cash) чек ЮKassa,
     * созданный реальным `fiscalizeSale()`, должен рендериться `OrderReceiptResource`
     * с НЕНУЛЕВЫМ vat_amount (до фикса — `(float)` от массива `{value,currency}` давал 0).
     * vat_code=4 (20%, "в том числе"): 100.00 × 20/120 = 16.67.
     */
    public function test_cash_receipt_renders_correct_non_zero_vat_amount_via_api(): void
    {
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $fake);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->hasItems(1)->create(['total' => 100, 'delivery_cost' => 0]);

        app(YooKassaFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $response = $this->actingAs($user)->getJson("/api/v1/orders/{$order->id}/receipts");

        $response->assertOk()
            ->assertJsonPath('data.0.items.0.vat_code', 4)
            ->assertJsonPath('data.0.items.0.vat_rate', '20')
            ->assertJsonPath('data.0.items.0.vat_amount', '16.67')
            ->assertJsonPath('data.0.vat.0.vat_code', 4)
            ->assertJsonPath('data.0.vat.0.amount', '16.67')
            ->assertJsonPath('data.0.vatTotal', '16.67');
    }
}
