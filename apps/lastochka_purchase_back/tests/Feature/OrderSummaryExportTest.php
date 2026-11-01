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
 * Сводная выгрузка заказов (format=summary): строка = заказ.
 * Спека: docs/superpowers/specs/2026-07-30-orders-courier-filter-slots-export-design.md
 */
class OrderSummaryExportTest extends TestCase
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
            'email' => uniqid('osx_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    private function setModules(array $modules): void
    {
        \DB::table('admin_settings')->updateOrInsert(
            ['key' => 'project_modules'],
            ['value' => json_encode($modules), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function download(array $body, string $token): Spreadsheet
    {
        $response = $this->postJson(self::URL, $body, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'order_summary_') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = (new Xlsx())->load($path);
        unlink($path);

        return $spreadsheet;
    }

    /**
     * order_items.product_id — NOT NULL с внешним ключом на products,
     * поэтому под каждую позицию нужен реальный товар.
     * У products без дефолта только title, status_id и unit_type_id.
     */
    private function makeProduct(string $title): int
    {
        return \DB::table('products')->insertGetId([
            'title' => $title,
            'status_id' => 1,
            'unit_type_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrderWithItems(string $marker, int $itemsCount, array $attrs = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'shop_id' => $this->makeShop('OSX shop ' . $marker),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'uuid' => 'osx-' . $marker . '-' . uniqid(),
        ], $attrs));

        for ($i = 0; $i < $itemsCount; $i++) {
            \DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => $this->makeProduct('OSX product ' . $marker . '-' . $i),
                'quantity' => 1,
                'price' => 100,
                'price_during_order' => 100,
                'status_id' => 1,
                'is_supplemented' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $order;
    }

    public function test_summary_sheet_has_one_row_per_order(): void
    {
        $this->setModules([]);
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('rows1', 3);

        $sheet = $this->download(['order_ids' => [$order->id], 'format' => 'summary'], $token)->getActiveSheet();

        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertSame($order->number, (string) $sheet->getCell('A2')->getValue());
    }

    public function test_summary_sheet_columns(): void
    {
        $this->setModules([]);
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('cols1', 1);

        $sheet = $this->download(['order_ids' => [$order->id], 'format' => 'summary'], $token)->getActiveSheet();
        $header = array_map(static fn ($cell) => (string) $cell, $sheet->rangeToArray('A1:N1')[0]);

        $this->assertSame([
            '№ заказа',
            'Дата',
            'Слот доставки',
            'Покупатель',
            'Телефон',
            'Статус',
            'Магазин',
            'Тип',
            'Способ оплаты',
            'Статус оплаты',
            'Сумма заказа',
            'Адрес доставки',
            'Сборщик',
            'Курьер',
        ], $header);
        $this->assertSame('', (string) $sheet->getCell('O1')->getValue());
    }

    public function test_heavy_column_appears_only_with_module(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('heavy1', 1, ['is_heavy' => 1]);

        $this->setModules(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => true]]);
        $sheet = $this->download(['order_ids' => [$order->id], 'format' => 'summary'], $token)->getActiveSheet();

        $this->assertSame('Тяжёлый', (string) $sheet->getCell('O1')->getValue());
        $this->assertSame('Да', (string) $sheet->getCell('O2')->getValue());
    }

    public function test_pickup_finished_order_is_labeled_issued(): void
    {
        $this->setModules([]);
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('issued1', 1, [
            'status_id' => Status::ORDER_FINISHED,
            'type_id' => Type::RECEIVE_PICKUP,
        ]);

        $sheet = $this->download(['order_ids' => [$order->id], 'format' => 'summary'], $token)->getActiveSheet();

        $this->assertSame('Выдан', (string) $sheet->getCell('F2')->getValue());
        $this->assertSame('Самовывоз', (string) $sheet->getCell('H2')->getValue());
    }

    public function test_assembled_order_with_courier_is_labeled_assigned(): void
    {
        $this->setModules(['courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => true]]);
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('assigned1', 1, [
            'status_id' => Status::ORDER_ASSEMBLED,
            'type_id' => Type::RECEIVE_DELIVERY,
        ]);

        $courierId = \DB::table('users')->insertGetId([
            'name' => 'Пётр',
            'surname' => 'Иванов',
            'email' => uniqid('osxc_') . '@test.local',
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
            'order_id' => $order->id,
            'status_id' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sheet = $this->download(['order_ids' => [$order->id], 'format' => 'summary'], $token)->getActiveSheet();

        $this->assertSame('Назначен курьер', (string) $sheet->getCell('F2')->getValue());
        $this->assertSame('Иванов Пётр', (string) $sheet->getCell('N2')->getValue());
    }

    public function test_default_format_stays_items(): void
    {
        $this->setModules([]);
        $token = $this->makeAdminToken();
        $order = $this->makeOrderWithItems('default1', 2);

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();

        $this->assertSame('Покупатель', (string) $sheet->getCell('C1')->getValue());
        $this->assertSame(3, $sheet->getHighestRow());
    }

    public function test_invalid_format_is_rejected(): void
    {
        $token = $this->makeAdminToken();

        $this->postJson(self::URL, ['format' => 'pdf'], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(422);
    }
}
