<?php

namespace Tests\Feature\Bonus;

use App\Models\Client;
use App\Models\ClientBonusTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientBonusMirrorTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function migration_added_bonus_columns_to_clients(): void
    {
        $this->assertTrue(Schema::hasColumn('clients', 'bonus_balance'));
        $this->assertTrue(Schema::hasColumn('clients', 'bonus_reserved'));
    }

    /** @test */
    public function migration_created_client_bonus_transactions_table(): void
    {
        $this->assertTrue(Schema::hasTable('client_bonus_transactions'));

        foreach ([
            'client_id', 'operation_id', 'type', 'amount', 'balance_after', 'source',
            'description', 'admin_id', 'status', 'reject_reason', 'reject_message',
            'order_external_id', 'occurred_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('client_bonus_transactions', $column),
                "Нет колонки {$column}"
            );
        }
    }

    /** @test */
    public function bonus_mirror_fields_are_fillable_for_consumer(): void
    {
        $fillable = (new Client())->getFillable();

        $this->assertContains('bonus_balance', $fillable);
        $this->assertContains('bonus_reserved', $fillable);
    }

    /** @test */
    public function available_bonus_balance_subtracts_reserved(): void
    {
        $client = new Client(['bonus_balance' => 500, 'bonus_reserved' => 120]);

        $this->assertSame(380, $client->available_bonus_balance);
    }

    /** @test */
    public function available_bonus_balance_never_goes_negative(): void
    {
        $client = new Client(['bonus_balance' => 50, 'bonus_reserved' => 200]);

        $this->assertSame(0, $client->available_bonus_balance);
    }

    /** @test */
    public function client_has_bonus_transactions_relation(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusMirror',
            'is_authorized' => true,
        ]);

        ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'MIRROR-' . mt_rand(1000, 9999),
            'type' => 'earn',
            'amount' => 30,
            'source' => 'manual',
            'description' => 'Тестовая запись',
            'status' => ClientBonusTransaction::STATUS_PENDING,
        ]);

        $this->assertSame(1, $client->bonusTransactions()->count());
    }

    /** @test */
    public function bonus_transactions_relation_sorts_null_occurred_at_by_created_at(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusOrder',
            'is_authorized' => true,
        ]);

        // created_at задаём явно и с разбегом в минутах: если оставить на
        // автопроставление, записи одного теста могут получить одинаковую
        // секунду и порядок станет недетерминированным (тест будет мигать).
        $old = now()->subDays(5);
        $recent = now()->subHour();
        $justNow = now();

        $this->insertBonusTransaction($client->id, 'ORDER-OLD', [
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => $old,
            'created_at' => $old,
        ]);

        $this->insertBonusTransaction($client->id, 'ORDER-RECENT', [
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => $recent,
            'created_at' => $recent,
        ]);

        // Имитация только что отклонённой операции: bonus_operation.rejected
        // не несёт времени самой операции, поэтому occurred_at так и остаётся
        // NULL навсегда. Запись должна вести себя как самая свежая — по
        // моменту появления в журнале (created_at), а не утонуть в конец из-за
        // NULL при DESC-сортировке по occurred_at.
        $this->insertBonusTransaction($client->id, 'ORDER-REJECTED-NULL', [
            'status' => ClientBonusTransaction::STATUS_REJECTED,
            'occurred_at' => null,
            'created_at' => $justNow,
        ]);

        $orderedIds = $client->bonusTransactions()->pluck('operation_id')->toArray();

        $this->assertSame(
            ['ORDER-REJECTED-NULL', 'ORDER-RECENT', 'ORDER-OLD'],
            $orderedIds
        );
    }

    /** @test */
    public function client_bonus_transaction_latest_first_scope_sorts_null_occurred_at_by_created_at(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusScopeOrder',
            'is_authorized' => true,
        ]);

        $old = now()->subDays(5);
        $recent = now()->subHour();
        $justNow = now();

        $this->insertBonusTransaction($client->id, 'SCOPE-OLD', [
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => $old,
            'created_at' => $old,
        ]);

        $this->insertBonusTransaction($client->id, 'SCOPE-RECENT', [
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'occurred_at' => $recent,
            'created_at' => $recent,
        ]);

        $this->insertBonusTransaction($client->id, 'SCOPE-PENDING-NULL', [
            'status' => ClientBonusTransaction::STATUS_PENDING,
            'occurred_at' => null,
            'created_at' => $justNow,
        ]);

        $orderedIds = ClientBonusTransaction::where('client_id', $client->id)
            ->latestFirst()
            ->pluck('operation_id')
            ->toArray();

        $this->assertSame(
            ['SCOPE-PENDING-NULL', 'SCOPE-RECENT', 'SCOPE-OLD'],
            $orderedIds
        );
    }

    private function insertBonusTransaction(int $clientId, string $operationId, array $overrides): void
    {
        DB::table('client_bonus_transactions')->insert(array_merge([
            'client_id' => $clientId,
            'operation_id' => $operationId,
            'type' => 'earn',
            'amount' => 30,
            'balance_after' => null,
            'source' => 'manual',
            'description' => 'Тестовая запись сортировки',
            'admin_id' => null,
            'status' => ClientBonusTransaction::STATUS_APPLIED,
            'reject_reason' => null,
            'reject_message' => null,
            'order_external_id' => null,
            'occurred_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
