<?php

namespace Tests\Feature\Bonus;

use App\Models\Client;
use App\Models\ClientBonusTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientBonusHistoryQueryTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    private function makeClient(): Client
    {
        return Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusHistory',
            'is_authorized' => true,
            'bonus_balance' => 400,
            'bonus_reserved' => 150,
        ]);
    }

    /** @test */
    public function client_item_returns_bonus_balance_fields(): void
    {
        $client = $this->makeClient();

        $query = '
            query ($id: Int!) {
                admin_clients_item(id: $id) {
                    id
                    bonus_balance
                    bonus_reserved
                    available_bonus_balance
                }
            }
        ';

        $data = $this->actingAs($this->user)->graphQL($query, ['id' => $client->id])->json();

        $this->assertArrayNotHasKey('errors', $data, json_encode($data['errors'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertSame(400, $data['data']['admin_clients_item']['bonus_balance']);
        $this->assertSame(150, $data['data']['admin_clients_item']['bonus_reserved']);
        $this->assertSame(250, $data['data']['admin_clients_item']['available_bonus_balance']);
    }

    /** @test */
    public function history_query_returns_only_requested_client_rows(): void
    {
        $client = $this->makeClient();
        $other = $this->makeClient();

        ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'HIST-A-' . mt_rand(1000, 9999),
            'type' => 'earn',
            'amount' => 30,
            'balance_after' => 430,
            'source' => 'manual',
            'description' => 'Компенсация',
            'admin_id' => $this->user->id,
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => now(),
        ]);
        ClientBonusTransaction::create([
            'client_id' => $other->id,
            'operation_id' => 'HIST-B-' . mt_rand(1000, 9999),
            'type' => 'spend',
            'amount' => 10,
            'source' => 'online',
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => now(),
        ]);

        $query = '
            query ($client_id: Int!) {
                admin_client_bonus_transactions_paginate(client_id: $client_id, first: 25, page: 1) {
                    data {
                        id
                        type
                        amount
                        balance_after
                        source
                        description
                        status
                        admin { id }
                    }
                    paginatorInfo { total }
                }
            }
        ';

        $data = $this->actingAs($this->user)->graphQL($query, ['client_id' => $client->id])->json();

        $this->assertArrayNotHasKey('errors', $data, json_encode($data['errors'] ?? null, JSON_UNESCAPED_UNICODE));
        $rows = $data['data']['admin_client_bonus_transactions_paginate'];
        $this->assertSame(1, $rows['paginatorInfo']['total']);
        $this->assertSame('earn', $rows['data'][0]['type']);
        $this->assertSame('Компенсация', $rows['data'][0]['description']);
        $this->assertSame($this->user->id, (int) $rows['data'][0]['admin']['id']);
    }

    /** @test */
    public function history_query_returns_newest_first_including_rows_without_occurred_at(): void
    {
        $client = $this->makeClient();

        ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'ORD-OLD-' . mt_rand(1000, 9999),
            'type' => 'earn', 'amount' => 10, 'source' => 'online',
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ]);
        ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'ORD-NEW-' . mt_rand(1000, 9999),
            'type' => 'earn', 'amount' => 20, 'source' => 'online',
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        // Отклонённая операция: occurred_at не заполнится никогда, но она свежайшая
        ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'ORD-REJ-' . mt_rand(1000, 9999),
            'type' => 'spend', 'amount' => 30, 'source' => 'manual',
            'status' => ClientBonusTransaction::STATUS_REJECTED,
            'reject_reason' => 'insufficient_balance',
            'occurred_at' => null,
            'created_at' => now(),
        ]);

        $query = '
            query ($client_id: Int!) {
                admin_client_bonus_transactions_paginate(client_id: $client_id, first: 25, page: 1) {
                    data { amount status }
                }
            }
        ';

        $data = $this->actingAs($this->user)->graphQL($query, ['client_id' => $client->id])->json();
        $rows = $data['data']['admin_client_bonus_transactions_paginate']['data'];

        $this->assertSame('rejected', $rows[0]['status'], 'Отклонённая операция без occurred_at должна быть первой');
        $this->assertSame(20, $rows[1]['amount']);
        $this->assertSame(10, $rows[2]['amount']);
    }
}
