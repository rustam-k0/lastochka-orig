<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Колонки «Сборщик» и «Курьер» в листе выгрузки по товарам.
 * Спека: docs/superpowers/specs/2026-07-30-orders-courier-filter-slots-export-design.md
 */
class OrderExportStaffColumnsTest extends TestCase
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
            'email' => uniqid('oes_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    private function makeStaff(string $name, string $surname, string $patronymic = ''): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name,
            'surname' => $surname,
            'patronymic' => $patronymic ?: null,
            'email' => uniqid('oess_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignCollector(int $orderId, int $userId): void
    {
        \DB::table('assemblies')->insert([
            'order_id' => $orderId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignCourier(int $orderId, int $userId): void
    {
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('delivery_orders')->insert([
            'delivery_id' => $deliveryId,
            'order_id' => $orderId,
            'status_id' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function softDeleteUser(int $userId): void
    {
        \DB::table('users')->where('id', $userId)->update(['deleted_at' => now()]);
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

    private function makeOrder(string $marker): Order
    {
        return Order::factory()->create([
            'shop_id' => $this->makeShop('OES shop ' . $marker),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'uuid' => 'oes-' . $marker . '-' . uniqid(),
        ]);
    }

    public function test_staff_columns_are_appended_to_items_sheet(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrder('staff1');
        $this->assignCollector($order->id, $this->makeStaff('Иван', 'Петров', 'Сергеевич'));
        $this->assignCourier($order->id, $this->makeStaff('Пётр', 'Иванов'));

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();

        $this->assertSame('Сборщик', (string) $sheet->getCell('P1')->getValue());
        $this->assertSame('Курьер', (string) $sheet->getCell('Q1')->getValue());
        $this->assertSame('Петров Иван Сергеевич', (string) $sheet->getCell('P2')->getValue());
        $this->assertSame('Иванов Пётр', (string) $sheet->getCell('Q2')->getValue());
    }

    public function test_several_couriers_are_joined_with_comma(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrder('staff2');
        $this->assignCourier($order->id, $this->makeStaff('Пётр', 'Иванов'));
        $this->assignCourier($order->id, $this->makeStaff('Семён', 'Сидоров'));

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();
        $value = (string) $sheet->getCell('Q2')->getValue();

        $this->assertStringContainsString('Иванов Пётр', $value);
        $this->assertStringContainsString('Сидоров Семён', $value);
        $this->assertStringContainsString(', ', $value);
    }

    public function test_order_without_staff_exports_empty_cells(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrder('staff3');

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();

        $this->assertSame('', (string) $sheet->getCell('P2')->getValue());
        $this->assertSame('', (string) $sheet->getCell('Q2')->getValue());
    }

    public function test_child_order_staff_is_shown_on_parent(): void
    {
        $token = $this->makeAdminToken();
        $parent = $this->makeOrder('parent4');
        $child = Order::factory()->create([
            'shop_id' => $parent->shop_id,
            'client_id' => $parent->client_id,
            'parent_order_id' => $parent->id,
            'payment_type_id' => 1,
            'uuid' => 'oes-child4-' . uniqid(),
        ]);
        $this->assignCollector($child->id, $this->makeStaff('Иван', 'Петров'));

        $sheet = $this->download(['order_ids' => [$parent->id]], $token)->getActiveSheet();

        $this->assertSame('Петров Иван', (string) $sheet->getCell('P2')->getValue());
    }

    public function test_reassigned_collector_uses_latest_assembly(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrder('staff5');
        $this->assignCollector($order->id, $this->makeStaff('Иван', 'Первый'));
        $this->assignCollector($order->id, $this->makeStaff('Пётр', 'Второй'));

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();

        $this->assertSame('Второй Пётр', (string) $sheet->getCell('P2')->getValue());
    }

    /**
     * Парити с поштучным OrderCollectorResolver::latest('id')->first(): если у
     * сборки с максимальным id пользователь мягко удалён, побеждает именно она
     * (колонка пустая), а не откатывается на предыдущую живую сборку.
     */
    public function test_collector_column_is_empty_when_latest_assembly_user_is_soft_deleted(): void
    {
        $token = $this->makeAdminToken();
        $order = $this->makeOrder('staff6');
        $this->assignCollector($order->id, $this->makeStaff('Иван', 'Первый'));
        $deletedUserId = $this->makeStaff('Пётр', 'Второй');
        $this->assignCollector($order->id, $deletedUserId);
        $this->softDeleteUser($deletedUserId);

        $sheet = $this->download(['order_ids' => [$order->id]], $token)->getActiveSheet();

        $this->assertSame('', (string) $sheet->getCell('P2')->getValue());
    }
}
