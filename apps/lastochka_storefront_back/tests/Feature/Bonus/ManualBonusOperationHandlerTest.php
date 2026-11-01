<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusTransaction;
use App\Models\User;
use App\RabbitMQ\Handlers\BonusOperationHandler;
use App\RabbitMQ\Handlers\ManualBonusOperationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualBonusOperationHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_handler_applies_manual_command(): void
    {
        $user = User::factory()->create(['phone' => '+79007778899', 'bonus_balance' => 0]);

        $result = app(ManualBonusOperationHandler::class)->handle([
            'payload' => [
                'event_id' => 'evt-1',
                'operation_id' => 'ADM-OP-H1',
                'phone' => '+79007778899',
                'loyalty_id' => null,
                'type' => 'earn',
                'amount' => 70,
                'comment' => 'Ручное начисление оператором',
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(70, (int) $user->fresh()->bonus_balance);
        $this->assertSame('manual', BonusTransaction::where('operation_id', 'ADM-OP-H1')->value('source'));
    }

    public function test_handler_accepts_flat_payload_without_wrapper(): void
    {
        $user = User::factory()->create(['phone' => '+79007778899', 'bonus_balance' => 0]);

        app(ManualBonusOperationHandler::class)->handle([
            'event_id' => 'evt-2',
            'operation_id' => 'ADM-OP-H2',
            'phone' => '+79007778899',
            'loyalty_id' => null,
            'type' => 'earn',
            'amount' => 5,
            'comment' => 'Плоский payload',
        ]);

        $this->assertSame(5, (int) $user->fresh()->bonus_balance);
    }

    public function test_fact_handler_skips_own_echo(): void
    {
        $user = User::factory()->create(['loyalty_id' => 'LTY-ECHO', 'bonus_balance' => 100]);

        $result = app(BonusOperationHandler::class)->handle([
            'payload' => [
                'event_source' => config('rabbitmq-events.source'),
                'operation_id' => 'ECHO-1',
                'loyalty_id' => 'LTY-ECHO',
                'type' => 'earn',
                'amount' => 999,
                'operation_source' => 'manual',
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(0, BonusTransaction::where('operation_id', 'ECHO-1')->count());
    }

    public function test_fact_handler_still_applies_external_source(): void
    {
        $user = User::factory()->create(['loyalty_id' => 'LTY-EXT', 'bonus_balance' => 100]);

        app(BonusOperationHandler::class)->handle([
            'payload' => [
                'event_source' => '1c_parser',
                'operation_id' => 'EXT-1',
                'loyalty_id' => 'LTY-EXT',
                'type' => 'earn',
                'amount' => 20,
                'operation_source' => 'offline',
            ],
        ]);

        $this->assertSame(120, (int) $user->fresh()->bonus_balance);
    }

    public function test_manual_requests_entity_is_registered_in_config(): void
    {
        $entity = config('rabbitmq-events.entities.bonus_operation_requests');

        $this->assertIsArray($entity);
        $this->assertSame(ManualBonusOperationHandler::class, $entity['handler']);
        $this->assertContains('bonus_operation.requested', $entity['routing_key_patterns']);
        $this->assertNotEmpty(config('rabbitmq-events.inbound_queues.bonus_operation_requests'));
    }
}
