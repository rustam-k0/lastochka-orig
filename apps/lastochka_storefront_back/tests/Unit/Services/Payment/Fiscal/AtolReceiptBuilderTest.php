<?php
declare(strict_types=1);
namespace Tests\Unit\Services\Payment\Fiscal;
use App\Enums\PaymentType;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Services\Payment\Fiscal\AtolReceiptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AtolReceiptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_receipt_uses_payment_type_0_and_full_payment(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'seller_name' => 'ООО Ромашка',
                'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 100.0);

        $this->assertSame(0, $receipt['payments'][0]['type']);
        $this->assertSame(100.0, $receipt['payments'][0]['sum']);
        $this->assertSame('full_payment', $receipt['items'][0]['payment_method']);
        $this->assertSame('7700000000', $receipt['company']['inn']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($receipt['items'], 'sum')), 0.001);
    }

    public function test_card_receipt_uses_payment_type_1_and_full_prepayment(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru']]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 50]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Card, 50.0);

        $this->assertSame(1, $receipt['payments'][0]['type']);
        $this->assertSame('full_prepayment', $receipt['items'][0]['payment_method']);
    }

    /**
     * ATOL flat-credentials bug: `IntegrationConfigEventTransformer::toEvent` on the admin
     * side publishes `credentials = $config->credentials[$config->driver]` — only the active
     * driver's sub-dict, FLAT. So on storefront, `IntegrationConfigService::getCredentials('fiscal')`
     * returns the flat driver dict directly, WITHOUT an `atol` wrapper key (confirmed live:
     * `['atol']['login']` was null on real dev data). Before the fix `['atol'] ?? []` always
     * resolved to `[]` on such data → inn/vat/etc were silently empty in every ATOL receipt.
     * This seeds credentials exactly as real RabbitMQ propagation produces them (no `atol` key).
     */
    public function test_flat_credentials_from_rabbitmq_propagation_are_read_without_atol_wrapper(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => [
                'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru', 'vat' => 'vat20',
            ]]);
        $order = Order::factory()->hasItems(1)->create(['total' => 100]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 100.0);

        $this->assertSame('77', $receipt['company']['inn']);
        $this->assertSame('vat20', $receipt['items'][0]['vat']['type']);
    }

    /**
     * Исторический кейс с ATOL test stand: товар "Чернослив 30/40", quantity=1000
     * (граммы), total=689. Раньше строка уходила в граммах: `price = round(689/1000, 2)
     * = 0.69`, `0.69 * 1000 = 690 ≠ 689` → ATOL код 16 "Неверная цена (сумма)",
     * и её схлопывали в quantity=1. Теперь весовая позиция пробивается в килограммах:
     * 1000 г = 1.000 кг, price = 689 ₽/кг, единица «кг» (тег 1197 в ФФД 1.05).
     */
    public function test_weighted_gram_item_is_fiscalized_in_kilograms(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        // delivery_cost=0 явно: `OrderFactory` иначе рандомизирует его (0/200/300),
        // что добавило бы вторую строку "Доставка" и сломало точные assertSame ниже.
        $order = Order::factory()
            ->hasItems(1, ['title' => 'Чернослив 30/40', 'quantity' => 1000, 'price' => 689, 'total' => 689,
                'measurement_unit' => 'gram'])
            ->create(['total' => 689, 'delivery_cost' => 0]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 689.0);

        $item = $receipt['items'][0];
        $this->assertSame(1.0, $item['quantity']);
        $this->assertSame(689.0, $item['price']);
        $this->assertSame(689.0, $item['sum']);
        $this->assertSame('кг', $item['measurement_unit']);
        $this->assertArrayNotHasKey('measure', $item, 'ФФД 1.05: тега 2108 нет');
        $this->assertSame(689.0, $receipt['total']);
        $this->assertEqualsWithDelta(689.0, array_sum(array_column($receipt['items'], 'sum')), 0.001);
    }

    /**
     * Живой баг (прод, заказ 178, ФД 79): «Голубика Вес» 630 г за 378 ₽ ушла в чек как
     * `price=0.60, quantity=630` — цена за грамм и количество в граммах без единицы
     * измерения. Клиент увидел «0.60 ₽ × 630». Инвариант ATOL при этом случайно сошёлся
     * (0.60 × 630 = 378.00), поэтому схлопывание не сработало — в отличие от соседних
     * весовых позиций того же чека (персик 1070 г / 295.32 ₽ и т.п.), которые ушли
     * «1 × сумма». Ожидание: 600 ₽/кг × 0.63 кг = 378 ₽, единица «кг».
     */
    public function test_blueberry_630g_for_378_is_fiscalized_as_600_per_kg_times_0_63(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'none', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()
            ->hasItems(1, ['title' => 'Голубика Вес', 'quantity' => 630, 'price' => 600, 'total' => 378,
                'measurement_unit' => 'gram', 'actual_weight' => 630, 'vat_rate' => 3])
            ->create(['total' => 378, 'delivery_cost' => 0]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Card, 378.0);

        $item = $receipt['items'][0];
        $this->assertSame(0.63, $item['quantity']);
        $this->assertSame(600.0, $item['price']);
        $this->assertSame(378.0, $item['sum']);
        $this->assertSame('кг', $item['measurement_unit']);
        $this->assertSame('vat10', $item['vat']['type']);
    }

    /**
     * ФФД 1.2 (APIv5): единица измерения — числовой тег 2108 (`measure`), строкового
     * `measurement_unit` в схеме v5 нет (стенд отвечает «All values fail against the
     * false schema»). Килограмм = 11, литр = 41 (проверено на testonline.atol.ru v5).
     */
    public function test_ffd_12_weighted_items_use_measure_11_for_kg_and_41_for_liter(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru',
                'ffd_version' => '1.2', 'payment_object' => 'commodity']]]);
        $order = Order::factory()->create(['total' => 512.85, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Голубика Вес', 'sku' => 'A', 'price' => 600, 'quantity' => 630, 'total' => 378,
                'measurement_unit' => 'gram', 'created_at' => now()],
            ['title' => 'Молоко разливное', 'sku' => 'B', 'price' => 89.90, 'quantity' => 1500, 'total' => 134.85,
                'measurement_unit' => 'milliliter', 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 512.85);

        [$kg, $liter] = $receipt['items'];
        $this->assertSame(0.63, $kg['quantity']);
        $this->assertSame(600.0, $kg['price']);
        $this->assertSame(11, $kg['measure']);
        $this->assertArrayNotHasKey('measurement_unit', $kg, 'v5 отвергает строковый measurement_unit');
        $this->assertSame(1.5, $liter['quantity']);
        $this->assertSame(89.90, $liter['price']);
        $this->assertSame(134.85, $liter['sum']);
        $this->assertSame(41, $liter['measure']);
    }

    /**
     * ФФД 1.05: миллилитры → литры с единицей «л»; storage-единицы kilogram/liter
     * (если вдруг встретятся) — количество как есть, без деления на 1000.
     */
    public function test_ffd_105_milliliter_and_native_kilogram_liter_units(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->create(['total' => 1334.85, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Молоко разливное', 'sku' => 'B', 'price' => 89.90, 'quantity' => 1500, 'total' => 134.85,
                'measurement_unit' => 'milliliter', 'created_at' => now()],
            ['title' => 'Картофель', 'sku' => 'C', 'price' => 50, 'quantity' => 2, 'total' => 100,
                'measurement_unit' => 'kilogram', 'created_at' => now()],
            ['title' => 'Квас', 'sku' => 'D', 'price' => 100, 'quantity' => 11, 'total' => 1100,
                'measurement_unit' => 'liter', 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 1334.85);

        [$ml, $kg, $l] = $receipt['items'];
        $this->assertSame([1.5, 89.90, 'л'], [$ml['quantity'], $ml['price'], $ml['measurement_unit']]);
        $this->assertSame([2.0, 50.0, 'кг'], [$kg['quantity'], $kg['price'], $kg['measurement_unit']]);
        $this->assertSame([11.0, 100.0, 'л'], [$l['quantity'], $l['price'], $l['measurement_unit']]);
    }

    /**
     * Штучная позиция (piece или NULL — старые заказы) — количество как есть,
     * единица не проставляется (в ФФД 1.05 тег 1197 необязателен; поведение до фикса).
     */
    public function test_piece_and_null_unit_items_keep_quantity_and_have_no_unit(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->create(['total' => 350, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Чай', 'sku' => 'A', 'price' => 100, 'quantity' => 2, 'total' => 200,
                'measurement_unit' => 'piece', 'created_at' => now()],
            ['title' => 'Старый заказ', 'sku' => 'B', 'price' => 50, 'quantity' => 3, 'total' => 150,
                'measurement_unit' => null, 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 350.0);

        foreach ($receipt['items'] as $item) {
            $this->assertArrayNotHasKey('measurement_unit', $item);
            $this->assertArrayNotHasKey('measure', $item);
        }
        $this->assertSame([2.0, 100.0], [$receipt['items'][0]['quantity'], $receipt['items'][0]['price']]);
        $this->assertSame([3.0, 50.0], [$receipt['items'][1]['quantity'], $receipt['items'][1]['price']]);
    }

    /**
     * Цена за кг восстанавливается из sum/quantity и округляется до копейки, поэтому
     * price × quantity может разойтись с sum на доли копейки (156.00 × 1.078 = 168.168
     * при sum 168.17) или ровно на копейку (33.33 × 3 = 99.99 при sum 100.00). Стенды
     * АТОЛ v4 и v5 такие чеки принимают (ФД выписан), 2 копейки — уже код 16.
     * Строки в пределах ±1 коп остаются весовыми, не схлопываются.
     */
    public function test_weighted_lines_within_one_kopeck_keep_kilograms(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->create(['total' => 268.17, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Нектарин Отборный', 'sku' => 'A', 'price' => 156, 'quantity' => 1078, 'total' => 168.17,
                'measurement_unit' => 'gram', 'created_at' => now()],
            ['title' => 'Капуста', 'sku' => 'B', 'price' => 33.33, 'quantity' => 3000, 'total' => 100,
                'measurement_unit' => 'gram', 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 268.17);

        [$nectarine, $cabbage] = $receipt['items'];
        $this->assertSame([1.078, 156.0, 168.17, 'кг'],
            [$nectarine['quantity'], $nectarine['price'], $nectarine['sum'], $nectarine['measurement_unit']]);
        $this->assertSame([3.0, 33.33, 100.0, 'кг'],
            [$cabbage['quantity'], $cabbage['price'], $cabbage['sum'], $cabbage['measurement_unit']]);
        $this->assertEqualsWithDelta(268.17, array_sum(array_column($receipt['items'], 'sum')), 0.001);
    }

    /**
     * Расхождение больше копейки (16.67 × 6 = 100.02 при sum 100.00) АТОЛ отвергает
     * кодом 16 — такую строку по-прежнему схлопываем в 1 условную единицу. Вместе с
     * количеством должна уйти и весовая единица: «1 кг × 100 ₽» было бы ложью.
     */
    public function test_weighted_line_beyond_one_kopeck_collapses_and_drops_unit(): void
    {
        foreach (['1.05' => 'measurement_unit', '1.2' => 'measure'] as $ffd => $unitKey) {
            IntegrationConfig::query()->delete();
            IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
                'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                    'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru',
                    'ffd_version' => $ffd]]]);
            $order = Order::factory()
                ->hasItems(1, ['title' => 'Арбуз', 'quantity' => 6000, 'price' => 16.67, 'total' => 100,
                    'measurement_unit' => 'gram'])
                ->create(['total' => 100, 'delivery_cost' => 0]);

            $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 100.0);

            $item = $receipt['items'][0];
            $this->assertSame(1.0, $item['quantity'], "ffd {$ffd}");
            $this->assertSame(100.0, $item['price'], "ffd {$ffd}");
            $this->assertSame(100.0, $item['sum'], "ffd {$ffd}");
            if ($ffd === '1.2') {
                $this->assertSame(0, $item['measure'], 'схлопнутая строка — штуки');
            } else {
                $this->assertArrayNotHasKey('measurement_unit', $item);
            }
        }
    }

    /**
     * Прод-чек 73 (заказ 194, 2026-09-10): позиция «Тыква» 2510 г ушла как
     * price=56.39, quantity=2.51, sum=141.55, а 56.39 × 2.51 = 141.5389 — расхождение
     * 1.11 коп. Допуск сравнивал произведение, ОКРУГЛЁННОЕ до копеек (round(14153.89)
     * = 14154 против 14155 = ровно 1 коп), поэтому строка проходила как «в пределах
     * копейки» и ККТ отвечал «[16] Неверная цена (сумма)». Порог ККТ проверен на
     * стенде АТОЛ: sum 141.53 и 141.54 дают ФД, 141.52 и 141.55 — код 16.
     * Сравнивать нужно ТОЧНОЕ произведение, а такую строку схлопывать.
     */
    public function test_weighted_line_beyond_one_kopeck_of_exact_product_collapses(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()
            ->hasItems(1, ['title' => 'Тыква', 'quantity' => 2510, 'price' => 55, 'total' => 138.05,
                'measurement_unit' => 'gram'])
            ->create(['total' => 138.05, 'delivery_cost' => 0]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 141.55);

        $item = $receipt['items'][0];
        $this->assertSame(1.0, $item['quantity'], 'строка схлопнута в 1 условную единицу');
        $this->assertSame(141.55, $item['price']);
        $this->assertSame(141.55, $item['sum']);
        $this->assertArrayNotHasKey('measurement_unit', $item, 'единица сброшена на штуки');
    }

    /**
     * Прод-чек 291 (заказ 450, 2026-09-25, GlitchTip 847): промокод растянул
     * «Картофель Белый» 2082 г до sum 71.61, цена вышла 34.39, а 34.39 × 2.082 =
     * 71.59998 — расхождение 1.002 коп. У произведения пять знаков после запятой,
     * сравнение в сотых долях копейки округляло его до 71.6000 и видело ровно 1 коп,
     * строка проходила допуск, ККТ ответил «[16] Неверная цена (сумма)». На стенде
     * АТОЛ v4 тот же чек со схлопнутой строкой прошёл (ФД 33145).
     */
    public function test_weighted_line_beyond_one_kopeck_by_fifth_decimal_collapses(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()
            ->hasItems(1, ['title' => 'Картофель Белый', 'quantity' => 2082, 'price' => 44, 'total' => 91.61,
                'measurement_unit' => 'gram'])
            ->create(['total' => 91.61, 'delivery_cost' => 0]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Card, 71.61);

        $item = $receipt['items'][0];
        $this->assertSame(1.0, $item['quantity'], 'строка схлопнута в 1 условную единицу');
        $this->assertSame(71.61, $item['price']);
        $this->assertSame(71.61, $item['sum']);
        $this->assertArrayNotHasKey('measurement_unit', $item, 'единица сброшена на штуки');
    }

    /**
     * Обычная (не весовая) позиция: quantity=2, price=100, sum=200 — price*quantity
     * уже точно равно sum, схлопывания быть не должно, quantity/price сохраняются.
     */
    public function test_non_weighted_item_keeps_quantity_and_price_when_invariant_already_holds(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()
            ->hasItems(1, ['quantity' => 2, 'price' => 100, 'total' => 200])
            ->create(['total' => 200, 'delivery_cost' => 0]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 200.0);

        $item = $receipt['items'][0];
        $this->assertSame(2.0, $item['quantity']);
        $this->assertSame(100.0, $item['price']);
        $this->assertSame(200.0, $item['sum']);
    }

    /**
     * ФФД 1.2 (APIv5): признак предмета расчёта — числовой код (тег 1212),
     * и в каждой позиции обязателен measure (тег 2108, 0 = штуки/единицы).
     */
    public function test_ffd_12_receipt_uses_numeric_payment_object_and_measure(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru',
                'ffd_version' => '1.2', 'payment_object' => 'commodity']]]);
        $order = Order::factory()
            ->hasItems(1, ['quantity' => 2, 'price' => 100, 'total' => 200])
            ->create(['total' => 500, 'delivery_cost' => 300]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 500.0);

        $this->assertCount(2, $receipt['items']);
        $this->assertSame(1, $receipt['items'][0]['payment_object'], 'commodity → код 1 в APIv5');
        $this->assertSame(0, $receipt['items'][0]['measure']);
        $this->assertSame(4, $receipt['items'][1]['payment_object'], 'Доставка (service) → код 4 в APIv5');
        $this->assertSame(0, $receipt['items'][1]['measure']);
    }

    /**
     * ФФД 1.05 (или отсутствие ffd_version — все существующие конфиги):
     * формат APIv4 не меняется — строковый payment_object, без measure.
     */
    public function test_ffd_105_receipt_keeps_string_payment_object_and_no_measure(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru',
                'payment_object' => 'commodity']]]);
        $order = Order::factory()
            ->hasItems(1, ['quantity' => 2, 'price' => 100, 'total' => 200])
            ->create(['total' => 500, 'delivery_cost' => 300]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 500.0);

        $this->assertSame('commodity', $receipt['items'][0]['payment_object']);
        $this->assertArrayNotHasKey('measure', $receipt['items'][0]);
        $this->assertSame('service', $receipt['items'][1]['payment_object']);
        $this->assertArrayNotHasKey('measure', $receipt['items'][1]);
    }

    /**
     * Живой баг (заказ 162 на проде, ФД 26): сборщик отменил 4 позиции из 12,
     * `orders.total` пересчитался правильно (765.20 вместо 984.90), но в чек ушли
     * ВСЕ 12 позиций — `lines()` итерировал `$order->items` без фильтра по статусу.
     * `distribute()` затем пропорционально ужал 984.90 → 765.20, из-за чего в чеке
     * оказались и не отданные клиенту товары, и заниженные цены у отданных
     * (252 ₽ за 6 бутылок превратились в 195.79 ₽ за 1 шт).
     *
     * После фильтра база активных позиций равна сумме платежа → ratio = 1.0,
     * цены и количества сохраняются как есть.
     */
    public function test_cancelled_and_replaced_items_are_excluded_from_receipt(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->create(['total' => 250, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Отдан клиенту', 'sku' => 'A', 'price' => 100, 'quantity' => 2, 'total' => 200,
                'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Отменён сборщиком', 'sku' => 'B', 'price' => 150, 'quantity' => 1, 'total' => 150,
                'status' => 'cancelled', 'created_at' => now()],
            ['title' => 'Заменён на другой', 'sku' => 'C', 'price' => 80, 'quantity' => 1, 'total' => 80,
                'status' => 'replaced', 'created_at' => now()],
            // Старые заказы до появления колонки status: NULL — позиция активная.
            ['title' => 'Без статуса', 'sku' => 'D', 'price' => 50, 'quantity' => 1, 'total' => 50,
                'status' => null, 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 250.0);

        $names = array_column($receipt['items'], 'name');
        $this->assertSame(['Отдан клиенту', 'Без статуса'], $names);
        $this->assertSame(2.0, $receipt['items'][0]['quantity'], 'ratio=1.0 → количество не схлопывается');
        $this->assertSame(100.0, $receipt['items'][0]['price']);
        $this->assertSame(200.0, $receipt['items'][0]['sum']);
        $this->assertSame(50.0, $receipt['items'][1]['sum']);
        $this->assertEqualsWithDelta(250.0, array_sum(array_column($receipt['items'], 'sum')), 0.001);
    }

    /**
     * Живой баг (прод, заказ 363, GlitchTip 844): клиент вернул курьеру при доставке
     * «Лук Красный» (весовой, 500 г) и «Картофель Фри» (штучный). delivery_back
     * присылает полностью возвращённую позицию со статусом `confirmed`, но с
     * `quantity = 0` и `line_total = 0` (tg-14265), и в витрине она остаётся среди
     * `activeItems`. Строки с нулевым количеством уходили в чек, а АТОЛ отвергал его
     * целиком: code 32 «NumberTooSmall: #/receipt.items[3].quantity; …items[6]…».
     * Товар клиенту не продан, поэтому в чеке продажи его быть не должно.
     */
    public function test_items_fully_returned_to_courier_are_excluded_from_receipt(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income_outcome',
                'payment_address' => 'shop.example', 'vat' => 'none', 'company_email' => 'kassa@example.ru']]]);
        // Состояние витрины на момент доставки: 450.54 за товары + 100 доставка − 300 промокод.
        $order = Order::factory()->create(['total' => 250.54, 'delivery_cost' => 100, 'discount' => 300]);
        $order->items()->createMany([
            ['title' => 'Помидоры Местные', 'sku' => 'A', 'price' => 58.5, 'quantity' => 526, 'total' => 61.54,
                'measurement_unit' => 'gram', 'actual_weight' => 526, 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Кинза', 'sku' => 'B', 'price' => 16, 'quantity' => 2, 'total' => 32,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Листья Салата', 'sku' => 'C', 'price' => 39, 'quantity' => 2, 'total' => 78,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Лук Красный', 'sku' => 'D', 'price' => 42, 'quantity' => 0, 'total' => 0,
                'measurement_unit' => 'gram', 'actual_weight' => 0, 'returned_quantity' => 1,
                'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Хлебушек С Кунжутом', 'sku' => 'E', 'price' => 12, 'quantity' => 8, 'total' => 96,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Ассорти Острое', 'sku' => 'F', 'price' => 90, 'quantity' => 1, 'total' => 90,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Картофель Фри 1кг', 'sku' => 'G', 'price' => 127.9, 'quantity' => 0, 'total' => 0,
                'measurement_unit' => 'piece', 'returned_quantity' => 1, 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Багет Чесночный', 'sku' => 'H', 'price' => 48, 'quantity' => 1, 'total' => 48,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
            ['title' => 'Багет Французский', 'sku' => 'I', 'price' => 45, 'quantity' => 1, 'total' => 45,
                'measurement_unit' => 'piece', 'status' => 'confirmed', 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 250.54);

        $names = array_column($receipt['items'], 'name');
        $this->assertNotContains('Лук Красный', $names);
        $this->assertNotContains('Картофель Фри 1кг', $names);
        $this->assertCount(8, $receipt['items'], '7 отданных товаров + доставка');
        foreach ($receipt['items'] as $item) {
            $this->assertGreaterThan(0, $item['quantity'], "строка '{$item['name']}': АТОЛ не принимает quantity 0");
        }
        $this->assertEqualsWithDelta(250.54, array_sum(array_column($receipt['items'], 'sum')), 0.001);
        $this->assertSame(250.54, $receipt['total']);
    }

    /**
     * Вырожденный случай: активных позиций не осталось (например, чек возврата по
     * заказу, где сборщик отменил всё). Пустой `items` ATOL отвергает целиком,
     * поэтому подставляем одну обобщённую строку на всю сумму — как это уже делает
     * `ReceiptBuilder::distribute()` для ЮKassa.
     */
    public function test_receipt_falls_back_to_single_line_when_all_items_are_cancelled(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()->create(['total' => 300, 'delivery_cost' => 0]);
        $order->items()->createMany([
            ['title' => 'Отменён', 'sku' => 'A', 'price' => 300, 'quantity' => 1, 'total' => 300,
                'status' => 'cancelled', 'created_at' => now()],
        ]);

        $receipt = app(AtolReceiptBuilder::class)->build($order->fresh(), PaymentType::Cash, 300.0);

        $this->assertCount(1, $receipt['items']);
        $this->assertSame('Оплата заказа', $receipt['items'][0]['name']);
        $this->assertSame(1.0, $receipt['items'][0]['quantity']);
        $this->assertSame(300.0, $receipt['items'][0]['price']);
        $this->assertSame(300.0, $receipt['items'][0]['sum']);
        $this->assertSame('vat20', $receipt['items'][0]['vat']['type']);
    }

    /**
     * Мульти-строчный чек: весовой товар + доставка. Каждая строка обязана
     * удовлетворять price*quantity==sum, и сумма всех sum должна совпадать
     * с target (иначе итог чека разъедется с суммой платежа).
     */
    public function test_multi_line_receipt_with_weighted_item_and_delivery_all_lines_satisfy_invariant(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['inn' => '7700000000', 'sno' => 'usn_income',
                'payment_address' => 'shop.example', 'vat' => 'vat20', 'company_email' => 'kassa@example.ru']]]);
        $order = Order::factory()
            ->hasItems(1, ['title' => 'Чернослив 30/40', 'quantity' => 1000, 'price' => 689, 'total' => 689,
                'measurement_unit' => 'gram'])
            ->create(['total' => 989, 'delivery_cost' => 300]);

        $receipt = app(AtolReceiptBuilder::class)->build($order, PaymentType::Cash, 989.0);

        $this->assertCount(2, $receipt['items']);
        $sumOfSums = 0.0;
        foreach ($receipt['items'] as $item) {
            $this->assertSame(
                round($item['sum'], 2),
                round($item['price'] * $item['quantity'], 2),
                "line '{$item['name']}' must satisfy price*quantity==sum"
            );
            $sumOfSums += $item['sum'];
        }
        $this->assertEqualsWithDelta(989.0, $sumOfSums, 0.001);
        $this->assertSame(989.0, $receipt['total']);
    }
}
