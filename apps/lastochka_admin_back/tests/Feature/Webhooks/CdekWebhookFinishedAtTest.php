<?php

namespace Tests\Feature\Webhooks;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T4: CDEK-заказы завершаются именно webhook'ом (CdekWebhookController),
 * поэтому переход в ORDER_FINISHED здесь тоже должен проставлять finished_at
 * (иначе вкладка «Статистика» недосчитает выручку по CDEK). Гейт по null
 * идемпотентен — ретрай webhook'а не сдвигает отметку.
 */
class CdekWebhookFinishedAtTest extends TestCase
{
    use DatabaseTransactions;

    private function persistCdekOrder(int $statusId, string $uuid, $finishedAt = null): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'cdek-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => 2,
            'payment_type_id' => 1,
            'cdek_order_uuid' => $uuid,
            'finished_at' => $finishedAt,
        ]));
    }

    public function test_cdek_completed_webhook_stamps_finished_at(): void
    {
        $uuid = 'cdek-uuid-' . uniqid();
        $order = $this->persistCdekOrder(Status::ORDER_IN_DELIVERY, $uuid, null);

        // DELIVERED_TO_RECIPIENT → completed → ORDER_FINISHED
        $resp = $this->postJson('/action/webhooks/cdek', [
            'type' => 'ORDER_STATUS',
            'uuid' => $uuid,
            'attributes' => ['code' => 'DELIVERED_TO_RECIPIENT'],
        ]);

        $resp->assertOk();

        $reloaded = Order::find($order->id);
        $this->assertSame(Status::ORDER_FINISHED, (int) $reloaded->status_id);
        $this->assertNotNull($reloaded->finished_at);
    }

    public function test_cdek_repeated_completed_webhook_does_not_overwrite_finished_at(): void
    {
        $uuid = 'cdek-uuid-' . uniqid();
        $past = now()->subDays(2)->startOfSecond();
        $order = $this->persistCdekOrder(Status::ORDER_FINISHED, $uuid, $past);

        // Повторный completed на уже завершённый заказ — отметку не трогаем.
        $resp = $this->postJson('/action/webhooks/cdek', [
            'type' => 'ORDER_STATUS',
            'uuid' => $uuid,
            'attributes' => ['code' => 'RECEIVED_AT_DELIVERY_POINT'], // тоже completed
        ]);

        $resp->assertOk();

        $reloaded = Order::find($order->id);
        $this->assertSame(
            $past->format('Y-m-d H:i:s'),
            $reloaded->finished_at->format('Y-m-d H:i:s')
        );
    }
}
