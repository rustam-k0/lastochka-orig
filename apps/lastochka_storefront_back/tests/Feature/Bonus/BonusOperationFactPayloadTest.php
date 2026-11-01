<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\User;
use App\RabbitMQ\Transformers\CustomerEventTransformer;
use App\Services\Bonus\BonusService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusOperationFactPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_fact_payload_contains_phone_balance_after_and_description(): void
    {
        User::factory()->create(['phone' => '+79001112233', 'loyalty_id' => 'LTY-F1', 'bonus_balance' => 40]);

        app(BonusService::class)->applyManualOperation([
            'operation_id' => 'ADM-OP-FACT',
            'type' => 'earn',
            'amount' => 60,
            'comment' => 'Бонус за отзыв',
            'phone' => '+79001112233',
            'loyalty_id' => 'LTY-F1',
        ]);

        $row = EventOutbox::where('routing_key', 'bonus_operation.created')->latest('id')->firstOrFail();

        $this->assertSame('ADM-OP-FACT', $row->payload['operation_id']);
        $this->assertSame('+79001112233', $row->payload['client_phone']);
        $this->assertSame(100, $row->payload['balance_after']);
        $this->assertSame('Бонус за отзыв', $row->payload['description']);
        $this->assertSame('manual', $row->payload['operation_source']);
        $this->assertSame('LTY-F1', $row->payload['loyalty_id']);
    }

    public function test_fact_is_published_even_without_loyalty_id(): void
    {
        User::factory()->create(['phone' => '+79002223344', 'loyalty_id' => null, 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation([
            'operation_id' => 'ADM-OP-NOLTY',
            'type' => 'earn',
            'amount' => 10,
            'comment' => 'Приветственные баллы',
            'phone' => '+79002223344',
            'loyalty_id' => null,
        ]);

        $row = EventOutbox::where('routing_key', 'bonus_operation.created')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('ADM-OP-NOLTY', $row->payload['operation_id']);
        $this->assertNull($row->payload['loyalty_id']);
        $this->assertSame('+79002223344', $row->payload['client_phone']);
    }

    public function test_external_offline_operation_publishes_fact(): void
    {
        User::factory()->create(['loyalty_id' => 'LTY-OFF', 'phone' => '+79003334455', 'bonus_balance' => 0]);

        app(BonusService::class)->applyExternalOperation([
            'operation_id' => '1C-OP-100',
            'loyalty_id' => 'LTY-OFF',
            'type' => 'earn',
            'amount' => 25,
            'operation_source' => 'offline',
        ]);

        $row = EventOutbox::where('routing_key', 'bonus_operation.created')->latest('id')->firstOrFail();

        $this->assertSame('1C-OP-100', $row->payload['operation_id']);
        $this->assertSame('offline', $row->payload['operation_source']);
        $this->assertSame(25, $row->payload['balance_after']);
    }

    public function test_customer_payload_contains_bonus_reserved(): void
    {
        $user = User::factory()->create([
            'phone' => '+79004445566',
            'bonus_balance' => 500,
            'bonus_reserved' => 120,
        ]);

        $payload = (new CustomerEventTransformer())->toEvent($user);

        $this->assertSame(500, $payload['bonus_balance']);
        $this->assertSame(120, $payload['bonus_reserved']);
    }
}
