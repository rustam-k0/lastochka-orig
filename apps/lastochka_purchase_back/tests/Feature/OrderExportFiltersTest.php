<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Экспорт заказов: структура листа по товарам и применение фильтров списка.
 * Спека: docs/superpowers/specs/2026-07-30-orders-courier-filter-slots-export-design.md
 */
class OrderExportFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/purchase/admin/orders/export';

    private function makeShop(string $title): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => $title,
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeClient(): int
    {
        return \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAdminToken(): string
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Admin',
            'email' => uniqid('oef_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    /** Токен пользователя с ролью shop, без единой привязки в user_shops. */
    private function makeShopRoleTokenWithoutShop(): string
    {
        $userId = \DB::table('users')->insertGetId([
            'name' => 'Shop no binding',
            'email' => uniqid('oef_noshop_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::SHOP, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    private function download(array $body, string $token): Spreadsheet
    {
        $response = $this->postJson(self::URL, $body, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'order_export_') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = (new Xlsx())->load($path);
        unlink($path);

        return $spreadsheet;
    }

    /** @return array<string> Значения первой строки листа */
    private function headerRow(Spreadsheet $spreadsheet): array
    {
        return array_map(
            static fn ($cell) => (string) $cell,
            $spreadsheet->getActiveSheet()->rangeToArray('A1:Z1')[0]
        );
    }

    /** @return array<string> Значения строки данных ($row, по умолчанию первая строка данных) */
    private function dataRow(Spreadsheet $spreadsheet, int $row = 2): array
    {
        return array_map(
            static fn ($cell) => (string) $cell,
            $spreadsheet->getActiveSheet()->rangeToArray('A' . $row . ':O' . $row)[0]
        );
    }

    /** Товар для позиции заказа: у products без дефолта только title/status_id/unit_type_id. */
    private function makeProduct(string $title): int
    {
        return \DB::table('products')->insertGetId([
            'title' => $title,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_PIECE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string> Значения колонки A начиная со второй строки */
    private function orderNumbers(Spreadsheet $spreadsheet): array
    {
        $values = [];
        $sheet = $spreadsheet->getActiveSheet();

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $value = (string) $sheet->getCell('A' . $row)->getValue();

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function test_items_sheet_keeps_its_columns(): void
    {
        $token = $this->makeAdminToken();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('OEF shop header'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'slot_start_date' => '2026-08-01 10:00:00',
            'slot_end_date' => '2026-08-01 11:00:00',
        ]);

        $productId = $this->makeProduct('OEF header product');

        \DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => 3,
            'price' => 150.75,
            'price_during_order' => 145.5,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $spreadsheet = $this->download(['order_ids' => [$order->id]], $token);

        $this->assertSame([
            '№ заказа',
            'Дата',
            'Покупатель',
            'Телефон',
            'Статус',
            'Сумма заказа',
            'Магазин',
            'Тип оплаты',
            'Статус оплаты',
            'Адрес доставки',
            'Слот доставки',
            'Товар',
            'Количество',
            'Цена за ед.',
            'Сумма позиции',
        ], array_slice($this->headerRow($spreadsheet), 0, 15));

        $row = $this->dataRow($spreadsheet);

        $this->assertSame($order->number, $row[0]); // A — № заказа
        $this->assertSame($order->created_at->format('d.m.Y H:i'), $row[1]); // B — Дата
        $this->assertSame('01.08.2026, 10:00 — 11:00', $row[10]); // K — Слот доставки
        $this->assertSame('OEF header product', $row[11]); // L — Товар
        $this->assertSame('3', $row[12]); // M — Количество
        $this->assertSame('145.5', $row[13]); // N — Цена за ед.
        // O — итог строки: до сборки price в БД — цена за единицу, итог = 3 × 145.5
        $this->assertSame('436.5', $row[14]); // O — Сумма позиции
        $this->assertSame('шт', $this->unitCell($spreadsheet)); // R — Ед. изм.
    }

    /** Весовой товар с фасовкой 300 г (база кг). */
    private function makePortionedProduct(string $title): int
    {
        return \DB::table('products')->insertGetId([
            'title' => $title,
            'status_id' => Status::CODE_ACTIVE,
            'unit_type_id' => Type::UNIT_GRAM,
            'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
            'info_weight' => 300,
            'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unitCell(Spreadsheet $spreadsheet, int $row = 2): string
    {
        return (string) $spreadsheet->getActiveSheet()->getCell('R' . $row)->getValue();
    }

    private function exportWeightedItem(array $item): array
    {
        $token = $this->makeAdminToken();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('OEF weighted shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
        ]);

        \DB::table('order_items')->insert(array_merge([
            'order_id' => $order->id,
            'product_id' => $this->makePortionedProduct('OEF филе грудки'),
            'price_during_order' => 178.2,
            'status_id' => Status::ORDER_ITEM_WAITING,
            'created_at' => now(),
            'updated_at' => now(),
        ], $item));

        $spreadsheet = $this->download(['order_ids' => [$order->id]], $token);

        return [$this->dataRow($spreadsheet), $this->unitCell($spreadsheet)];
    }

    public function test_items_sheet_shows_weighted_item_before_assembly_in_grams(): void
    {
        // 3 порции по 300 г, в БД до сборки price = цена порции.
        [$row, $unit] = $this->exportWeightedItem(['quantity' => 3, 'price' => 178.2]);

        $this->assertSame('900', $row[12]); // M — Количество
        $this->assertSame('534.6', $row[14]); // O — Сумма позиции
        $this->assertSame('г', $unit);
    }

    public function test_items_sheet_shows_weighted_item_after_assembly_by_actual_weight(): void
    {
        // AssemblyFinish: взвешено 528 г → 2 порции, price = итог строки.
        [$row, $unit] = $this->exportWeightedItem(['quantity' => 2, 'actual_weight' => 528, 'price' => 313.63]);

        $this->assertSame('528', $row[12]); // M — Количество
        $this->assertSame('313.63', $row[14]); // O — Сумма позиции
        $this->assertSame('г', $unit);
    }

    public function test_items_sheet_shows_issued_label_for_completed_pickup(): void
    {
        $token = $this->makeAdminToken();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('OEF status pickup shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_FINISHED,
            'type_id' => Type::RECEIVE_PICKUP,
        ]);

        $row = $this->dataRow($this->download(['order_ids' => [$order->id]], $token));

        // Лист по товарам зеркалит таблицу «Заказы»: завершённый самовывоз — «Выдан».
        $this->assertSame('Выдан', $row[4]);
    }

    public function test_items_sheet_shows_delivered_label_for_completed_delivery(): void
    {
        $token = $this->makeAdminToken();
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop('OEF status delivery shop'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_FINISHED,
            'type_id' => Type::RECEIVE_DELIVERY,
        ]);

        $row = $this->dataRow($this->download(['order_ids' => [$order->id]], $token));

        $this->assertSame('Доставлен', $row[4]);
    }

    public function test_shop_user_without_bound_shop_gets_empty_sheet_not_error(): void
    {
        $token = $this->makeShopRoleTokenWithoutShop();

        // Заказ существует в системе, но у пользователя нет привязки в
        // user_shops — выгрузка не должна ни упасть, ни отдать чужие заказы.
        Order::factory()->create([
            'shop_id' => $this->makeShop('OEF shop without user binding'),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
        ]);

        $spreadsheet = $this->download([], $token);

        $this->assertSame(1, $spreadsheet->getActiveSheet()->getHighestRow());
        $this->assertSame([
            '№ заказа',
            'Дата',
            'Покупатель',
            'Телефон',
            'Статус',
            'Сумма заказа',
            'Магазин',
            'Тип оплаты',
            'Статус оплаты',
            'Адрес доставки',
            'Слот доставки',
            'Товар',
            'Количество',
            'Цена за ед.',
            'Сумма позиции',
        ], array_slice($this->headerRow($spreadsheet), 0, 15));
    }

    public function test_export_applies_status_filter(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop status');
        $clientId = $this->makeClient();

        $cancelled = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => \App\Models\Status::ORDER_CANCELLED,
            'uuid' => 'oef-cancelled-' . uniqid(),
        ]);
        $created = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => \App\Models\Status::ORDER_CREATED,
            'uuid' => 'oef-created-' . uniqid(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'shops' => [$shopId],
            'statuses' => [\App\Models\Status::ORDER_CANCELLED],
        ], $token));

        $this->assertContains($cancelled->number, $numbers);
        $this->assertNotContains($created->number, $numbers);
    }

    public function test_export_applies_amount_range_filter(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop amount');
        $clientId = $this->makeClient();

        $cheap = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'amount' => 100,
            'uuid' => 'oef-cheap-' . uniqid(),
        ]);
        $pricey = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'amount' => 5000,
            'uuid' => 'oef-pricey-' . uniqid(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'shops' => [$shopId],
            'amount_from' => 1000,
        ], $token));

        $this->assertContains($pricey->number, $numbers);
        $this->assertNotContains($cheap->number, $numbers);
    }

    public function test_export_applies_courier_filter(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop courier');
        $clientId = $this->makeClient();

        $assigned = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => 'oef-assigned-' . uniqid(),
        ]);
        $bare = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => 'oef-bare-' . uniqid(),
        ]);

        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Courier',
            'email' => uniqid('oefc_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $courierId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId,
            'order_id' => $assigned->id,
            'status_id' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'shops' => [$shopId],
            'couriers' => [$courierId],
        ], $token));

        $this->assertContains($assigned->number, $numbers);
        $this->assertNotContains($bare->number, $numbers);
    }

    public function test_export_applies_collector_filter(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop collector');
        $clientId = $this->makeClient();

        $assembled = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => 'oef-assembled-' . uniqid(),
        ]);
        $bare = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => 'oef-nocollector-' . uniqid(),
        ]);

        $collectorId = \DB::table('users')->insertGetId([
            'name' => 'Collector',
            'email' => uniqid('oefcol_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('assemblies')->insert([
            'order_id' => $assembled->id,
            'user_id' => $collectorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'shops' => [$shopId],
            'collectors' => [$collectorId],
        ], $token));

        $this->assertContains($assembled->number, $numbers);
        $this->assertNotContains($bare->number, $numbers);
    }

    public function test_order_ids_branch_ignores_screen_filters(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop ids');
        $clientId = $this->makeClient();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'status_id' => \App\Models\Status::ORDER_CREATED,
            'uuid' => 'oef-ids-' . uniqid(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'order_ids' => [$order->id],
            'statuses' => [\App\Models\Status::ORDER_CANCELLED],
        ], $token));

        $this->assertContains($order->number, $numbers);
    }

    /**
     * Task 2 перевёл экспорт на OrderFilter::title(), который матчит и uuid,
     * и id (выравнивание со списком заказов). Фиксируем, что поиск по
     * подстроке uuid реально находит заказ через экспорт.
     */
    public function test_export_applies_title_filter_matching_uuid(): void
    {
        $token = $this->makeAdminToken();
        $shopId = $this->makeShop('OEF shop title uuid');
        $clientId = $this->makeClient();

        $needle = 'oef-uuid-needle-' . uniqid();

        $matching = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => $needle,
        ]);
        $other = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
            'uuid' => 'oef-other-' . uniqid(),
        ]);

        $numbers = $this->orderNumbers($this->download([
            'shops' => [$shopId],
            'title' => $needle,
        ], $token));

        $this->assertContains($matching->number, $numbers);
        $this->assertNotContains($other->number, $numbers);
    }
}
