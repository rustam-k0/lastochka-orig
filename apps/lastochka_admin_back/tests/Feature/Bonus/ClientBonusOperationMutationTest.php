<?php

namespace Tests\Feature\Bonus;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientBonusTransaction;
use App\Models\User;
use App\Services\ModuleConfigService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientBonusOperationMutationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected const MUTATION = '
        mutation ($clientId: ID!, $type: String!, $amount: Int!, $comment: String!) {
            clientBonusOperationCreate(clientId: $clientId, type: $type, amount: $amount, comment: $comment) {
                code
                message
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();
        Cache::flush();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    private function makeClient(int $balance = 500, int $reserved = 0): Client
    {
        return Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusMutation',
            'is_authorized' => true,
            'bonus_balance' => $balance,
            'bonus_reserved' => $reserved,
        ]);
    }

    /** @test */
    public function bonuses_module_is_enabled_by_default_when_key_absent(): void
    {
        AdminSetting::setValue('project_modules', json_encode(['b2b' => ['enabled' => false]]), 'json');
        Cache::flush();

        $this->assertTrue(ModuleConfigService::isBonusesEnabled());
    }

    /** @test */
    public function earn_creates_pending_transaction_and_outbox_command(): void
    {
        $client = $this->makeClient();

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'earn',
            'amount' => 250,
            'comment' => 'Компенсация за просроченный товар',
        ]);

        $response->assertJson(['data' => ['clientBonusOperationCreate' => ['code' => 200, 'message' => 'OK']]]);

        $tx = ClientBonusTransaction::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('earn', $tx->type);
        $this->assertSame(250, (int) $tx->amount);
        $this->assertSame('manual', $tx->source);
        $this->assertSame(ClientBonusTransaction::STATUS_PENDING, $tx->status);
        $this->assertSame($this->user->id, (int) $tx->admin_id);
        $this->assertSame('Компенсация за просроченный товар', $tx->description);
        $this->assertNotEmpty($tx->operation_id);

        $row = EventOutbox::where('routing_key', 'bonus_operation.requested')->latest('id')->firstOrFail();
        $this->assertSame('client_bonus_transactions', $row->entity_type);
        $this->assertSame($tx->operation_id, $row->payload['operation_id']);
        $this->assertSame('earn', $row->payload['type']);
        $this->assertSame(250, $row->payload['amount']);
        $this->assertSame('Компенсация за просроченный товар', $row->payload['comment']);
        $this->assertSame($client->phone, $row->payload['phone']);
    }

    /** @test */
    public function spend_over_available_balance_is_rejected(): void
    {
        $client = $this->makeClient(300, 250);

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'spend',
            'amount' => 100,
            'comment' => 'Списание по договорённости',
        ]);

        $response->assertJsonStructure(['errors']);
        $this->assertSame(0, ClientBonusTransaction::where('client_id', $client->id)->count());
    }

    /** @test */
    public function spend_within_available_balance_passes(): void
    {
        $client = $this->makeClient(300, 250);

        $response = $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'spend',
            'amount' => 50,
            'comment' => 'Списание по договорённости',
        ]);

        $response->assertJson(['data' => ['clientBonusOperationCreate' => ['code' => 200]]]);
    }

    /** @test */
    public function zero_amount_is_rejected(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'earn',
            'amount' => 0,
            'comment' => 'Ноль баллов',
        ])->assertJsonStructure(['errors']);
    }

    /** @test */
    public function empty_comment_is_rejected(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'earn',
            'amount' => 10,
            'comment' => '   ',
        ])->assertJsonStructure(['errors']);
    }

    /** @test */
    public function comment_longer_than_500_chars_is_rejected(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'earn',
            'amount' => 10,
            'comment' => str_repeat('я', 501),
        ])->assertJsonStructure(['errors']);
    }

    /** @test */
    public function unknown_type_is_rejected(): void
    {
        $client = $this->makeClient();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'refund',
            'amount' => 10,
            'comment' => 'Возврат руками',
        ])->assertJsonStructure(['errors']);
    }

    /** @test */
    public function operation_is_blocked_when_bonuses_module_disabled(): void
    {
        $client = $this->makeClient();

        AdminSetting::setValue('project_modules', json_encode(['bonuses' => ['enabled' => false]]), 'json');
        Cache::flush();

        $this->actingAs($this->user)->graphQL(self::MUTATION, [
            'clientId' => $client->id,
            'type' => 'earn',
            'amount' => 10,
            'comment' => 'Модуль выключен',
        ])->assertJsonStructure(['errors']);

        $this->assertSame(0, ClientBonusTransaction::where('client_id', $client->id)->count());
    }
}
