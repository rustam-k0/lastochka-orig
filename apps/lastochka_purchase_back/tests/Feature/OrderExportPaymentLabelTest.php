<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

class OrderExportPaymentLabelTest extends TestCase
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
            'email' => uniqid('export_admin_') . '@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScope('onlyAdmin')->findOrFail($userId);
        $user->assignRole(Role::findOrCreate(Role::ADMIN, 'api'));

        return $user->createToken('test')->plainTextToken;
    }

    /** Скачать экспорт по конкретному order_id и вернуть значение колонки I («Статус оплаты»). */
    private function paymentLabelFor(int $orderId, string $token): string
    {
        $response = $this->postJson(self::URL, ['order_ids' => [$orderId]], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'order_export_') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        $reader = new Xlsx();
        $spreadsheet = $reader->load($path);
        $value = (string) $spreadsheet->getActiveSheet()->getCell('I2')->getValue();

        unlink($path);

        return $value;
    }

    public function test_export_shows_not_paid_for_refunded_order(): void
    {
        $shopId   = $this->makeShop('Export refund shop');
        $clientId = $this->makeClient();
        $token    = $this->makeAdminToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->paid = true;
        $order->payment_status = 'refunded';
        $order->save();

        $this->assertSame('Не оплачен', $this->paymentLabelFor($order->id, $token));
    }

    public function test_export_shows_paid_for_genuinely_paid_order(): void
    {
        $shopId   = $this->makeShop('Export paid shop');
        $clientId = $this->makeClient();
        $token    = $this->makeAdminToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 1,
        ]);
        $order->paid = true;
        $order->payment_status = 'paid';
        $order->save();

        $this->assertSame('Оплачен', $this->paymentLabelFor($order->id, $token));
    }

    public function test_export_shows_not_paid_for_unpaid_order(): void
    {
        $shopId   = $this->makeShop('Export unpaid shop');
        $clientId = $this->makeClient();
        $token    = $this->makeAdminToken();

        $order = Order::factory()->create([
            'shop_id' => $shopId,
            'client_id' => $clientId,
            'payment_type_id' => 2,
        ]);

        $this->assertSame('Не оплачен', $this->paymentLabelFor($order->id, $token));
    }
}
