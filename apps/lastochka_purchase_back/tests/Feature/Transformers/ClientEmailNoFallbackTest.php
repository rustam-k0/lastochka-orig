<?php

namespace Tests\Feature\Transformers;

use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Email клиента в событии заказа — опциональное поле: обе целевые колонки
 * (clients.email, client_order_infos.email) nullable.
 *
 * Раньше consumer подставлял захардкоженные адреса на домене стороннего проекта
 * (no-email-XXXX@gorbushka.com / noreply@gorbushka.com). Платформенный код не должен
 * генерировать бренд-специфичные фейковые данные — отсутствующий email пишется как NULL.
 */
class ClientEmailNoFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private function invoke(string $method, array $args)
    {
        $ref = new ReflectionMethod(OrderEventTransformer::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(app(OrderEventTransformer::class), ...$args);
    }

    private function makeClientId(): int
    {
        return (int) DB::table('clients')->insertGetId([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'is_b2b' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function find_or_create_client_leaves_email_null_when_absent_in_event()
    {
        $phone = '7' . random_int(1000000000, 9999999999);

        $clientId = $this->invoke('findOrCreateClient', [[
            'event_id' => 'evt-' . uniqid(),
            'client_id' => 'ext-client-' . uniqid(),
            'client_source' => 'storefront',
            'client_info' => ['phone' => $phone, 'name' => 'Тест'],
        ]]);

        $this->assertNotNull($clientId, 'клиент должен быть создан');
        $this->assertNull(
            DB::table('clients')->where('id', $clientId)->value('email'),
            'email отсутствует в событии → в clients.email должен быть NULL, а не фейковый адрес'
        );
    }

    /** @test */
    public function process_client_order_info_leaves_email_null_when_absent_in_event()
    {
        $orderId = DB::table('orders')->insertGetId([
            'uuid' => 'email-null-' . uniqid(),
            'client_id' => $this->makeClientId(),
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 500,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::findOrFail($orderId);

        $this->invoke('processClientOrderInfo', [$order, [
            'client_info' => ['name' => 'Тест', 'phone' => '79990000000'],
        ]]);

        $this->assertNull(
            DB::table('client_order_infos')->where('order_id', $order->id)->value('email'),
            'email отсутствует в событии → в client_order_infos.email должен быть NULL, а не фейковый адрес'
        );
    }
}
