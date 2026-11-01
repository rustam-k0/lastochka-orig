<?php
declare(strict_types=1);
namespace Tests\Feature\Fiscal;
use App\Enums\PaymentType;
use App\Enums\ReceiptProvider;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Services\Payment\Fiscal\AtolFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AtolFiscalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscalize_sale_registers_and_stores_pending_receipt(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U1', 'status' => 'wait']),
        ]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $result = app(AtolFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $this->assertSame('pending', $result->status);
        $this->assertSame('U1', $result->externalId);
        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id, 'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'U1', 'status' => 'pending', 'type' => 'payment',
        ]);
    }

    /**
     * Task 13 fix: два РАЗНЫХ частичных возврата одного заказа обязаны получить
     * РАЗНЫЙ external_id (иначе АТОЛ задедупит второй чек по одному external_id
     * "{order}-sell_refund" и он никогда не зарегистрируется).
     */
    public function test_fiscalize_refund_with_different_refund_ids_produces_distinct_external_ids(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell_refund*' => Http::sequence()
                ->push(['uuid' => 'RF1', 'status' => 'wait'])
                ->push(['uuid' => 'RF2', 'status' => 'wait']),
        ]);
        $order = Order::factory()->hasItems(1)->create(['total' => 1000]);

        $first = app(AtolFiscalizer::class)->fiscalizeRefund($order, 400.0, 'rf-aaa');
        $second = app(AtolFiscalizer::class)->fiscalizeRefund($order, 300.0, 'rf-bbb');

        $this->assertSame('RF1', $first->externalId);
        $this->assertSame('RF2', $second->externalId);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/grp/sell_refund')
            && ($request->data()['external_id'] ?? null) === "{$order->id}-refund-rf-aaa");
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/grp/sell_refund')
            && ($request->data()['external_id'] ?? null) === "{$order->id}-refund-rf-bbb");

        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id, 'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'RF1', 'status' => 'pending', 'type' => 'refund',
        ]);
        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id, 'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'RF2', 'status' => 'pending', 'type' => 'refund',
        ]);
        $this->assertSame(
            2,
            PaymentReceipt::where('order_id', $order->id)->where('type', 'refund')->count(),
        );
    }

    /**
     * Обратная совместимость: без refundId (напр. legacy-путь) — прежний
     * operation-based external_id "{order}-sell_refund", повтор webhook'а по
     * тому же возврату остаётся идемпотентным (одна строка payment_receipts).
     */
    public function test_fiscalize_refund_without_refund_id_keeps_legacy_external_id_idempotent(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell_refund*' => Http::response(['uuid' => 'RF9', 'status' => 'wait']),
        ]);
        $order = Order::factory()->hasItems(1)->create(['total' => 1000]);

        app(AtolFiscalizer::class)->fiscalizeRefund($order, 1000.0);
        app(AtolFiscalizer::class)->fiscalizeRefund($order, 1000.0);

        $this->assertSame(
            1,
            PaymentReceipt::where('order_id', $order->id)->where('type', 'refund')->count(),
        );
        $this->assertDatabaseHas('payment_receipts', [
            'order_id' => $order->id, 'provider' => ReceiptProvider::Atol->value,
            'external_id' => 'RF9', 'status' => 'pending', 'type' => 'refund',
        ]);
    }

    /**
     * ATOL-11651 fix: PaymentReceipt.items должен храниться в едином (unified) виде,
     * общем с YooKassa (`description`/`amount`/`quantity`/`sum`/`vat_code`), а не в сыром
     * формате запроса к ATOL API (`name`/`vat`). Иначе OrderReceiptResource
     * (общий для обоих провайдеров) читает чужие ключи и считает vat_amount=0.
     */
    public function test_fiscalize_sale_stores_items_in_unified_shape_not_raw_atol_shape(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U2', 'status' => 'wait']),
        ]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100, 'delivery_cost' => 0]);

        app(AtolFiscalizer::class)->fiscalizeSale($order, PaymentType::Cash, 100.0);

        $receipt = PaymentReceipt::where('order_id', $order->id)
            ->where('provider', ReceiptProvider::Atol->value)
            ->firstOrFail();
        $item = $receipt->items[0];

        $this->assertArrayHasKey('description', $item);
        $this->assertArrayHasKey('amount', $item);
        $this->assertArrayHasKey('quantity', $item);
        $this->assertArrayHasKey('sum', $item);
        $this->assertArrayHasKey('vat_code', $item);
        $this->assertArrayNotHasKey('name', $item);
        $this->assertArrayNotHasKey('vat', $item);
        // Нет явного vat в creds ('vat' не задан) → AtolReceiptBuilder использует
        // дефолт 'vat20' → unifyItems() маппит его в vat_code=4.
        $this->assertSame(4, $item['vat_code']);
    }

    /**
     * Весовая позиция хранится так, как ушла в чек: количество в кг с точностью до
     * грамма (3 знака — `1.078`, а не `1.08`, иначе `OrderReceiptResource::withVat`
     * считает НДС от 156 × 1.08 = 168.48 вместо 168.17), плюс человекочитаемая единица
     * `unit` («кг»/«л», null для штучных) — мобилке нечем иначе отличить «× 0.63 кг»
     * от «× 0.63 шт». Формат `amount`/`quantity` для штучных не меняется («1.00»).
     */
    public function test_fiscalize_sale_stores_weighted_items_with_gram_precision_and_unit(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U3', 'status' => 'wait']),
        ]);
        $order = Order::factory()->create(['total' => 621.07, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Голубика Вес', 'sku' => 'A', 'price' => 600, 'quantity' => 630, 'total' => 378,
                'measurement_unit' => 'gram', 'created_at' => now()],
            ['title' => 'Нектарин Отборный', 'sku' => 'B', 'price' => 156, 'quantity' => 1078, 'total' => 168.17,
                'measurement_unit' => 'gram', 'created_at' => now()],
            ['title' => 'Чай', 'sku' => 'C', 'price' => 74.90, 'quantity' => 1, 'total' => 74.90,
                'measurement_unit' => 'piece', 'created_at' => now()],
        ]);

        app(AtolFiscalizer::class)->fiscalizeSale($order->fresh(), PaymentType::Card, 621.07);

        $items = PaymentReceipt::where('order_id', $order->id)->firstOrFail()->items;
        $this->assertSame(['description' => 'Голубика Вес', 'quantity' => '0.63', 'amount' => '600.00', 'unit' => 'кг'],
            array_intersect_key($items[0], array_flip(['description', 'quantity', 'amount', 'unit'])));
        $this->assertSame(['quantity' => '1.078', 'amount' => '156.00', 'unit' => 'кг'],
            array_intersect_key($items[1], array_flip(['quantity', 'amount', 'unit'])));
        $this->assertSame(['quantity' => '1.00', 'amount' => '74.90', 'unit' => null],
            array_intersect_key($items[2], array_flip(['quantity', 'amount', 'unit'])));
    }

    /**
     * Стоимость строки хранится ровно та, что ушла в фискальный документ, а не
     * восстанавливается из `amount × quantity`. Цена весовой позиции — производная
     * (`round(sum / quantity, 2)`), и ККТ принимает расхождение произведения с суммой
     * строки до копейки: 141.53 ₽ за 2.51 кг дают цену 56.39 ₽/кг, обратное умножение —
     * 141.5389, то есть 141.54 после округления. Без хранимого `sum` клиент показал бы
     * в чеке копейку, которой в ФД нет.
     */
    public function test_fiscalize_sale_stores_exact_line_sum_from_receipt_not_price_times_quantity(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U4', 'status' => 'wait']),
        ]);
        $order = Order::factory()->create(['total' => 141.53, 'delivery_cost' => 0]);
        $order->items()->create([
            'title' => 'Тыква', 'sku' => 'D', 'price' => 56.39, 'quantity' => 2510, 'total' => 141.53,
            'measurement_unit' => 'gram', 'created_at' => now(),
        ]);

        app(AtolFiscalizer::class)->fiscalizeSale($order->fresh(), PaymentType::Card, 141.53);

        $item = PaymentReceipt::where('order_id', $order->id)->firstOrFail()->items[0];
        $this->assertSame('141.53', $item['sum']);
        $this->assertSame('56.39', $item['amount']);
        $this->assertSame('2.51', $item['quantity']);
        // Ровно то расхождение, ради которого поле и хранится.
        $this->assertSame('141.54', number_format(56.39 * 2.51, 2, '.', ''));
    }
}
