<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Команда нужна там, где UNIQUE INDEX clients_phone_active_unique ещё не накатан:
 * миграция падает на дублях и просит сначала слить их. При накатанном индексе дубли
 * не создать, поэтому индекс снимаем на время теста. DDL в MySQL неявно коммитит
 * транзакцию, так что DatabaseTransactions не годится: индекс снимаем до открытия
 * транзакции и возвращаем после отката, данные теста в БД не остаются.
 */
class ClientsDedupeCommandTest extends TestCase
{
    private const PHONE_INDEX_MIGRATION = 'migrations/2026_04_21_120000_add_unique_index_to_clients_phone.php';

    private bool $phoneIndexExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phoneIndexExisted = (bool) DB::selectOne(
            "SELECT 1 AS e FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND INDEX_NAME = 'clients_phone_active_unique'"
        );

        (require database_path(self::PHONE_INDEX_MIGRATION))->down();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        if ($this->phoneIndexExisted) {
            (require database_path(self::PHONE_INDEX_MIGRATION))->up();
        }

        parent::tearDown();
    }

    /** @test */
    public function dry_run_does_not_modify_database(): void
    {
        $phone = '+7' . mt_rand(9000000000, 9999999999);

        $a = Client::create(['phone' => $phone, 'name' => 'A', 'is_authorized' => true]);
        $b = Client::create(['phone' => $phone, 'name' => 'B', 'is_authorized' => true]);

        $this->artisan('clients:dedupe', ['--phone' => $phone])
            ->assertExitCode(0);

        $this->assertDatabaseHas('clients', ['id' => $a->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('clients', ['id' => $b->id, 'deleted_at' => null]);
    }

    /** @test */
    public function apply_merges_duplicates_moving_orders_to_master(): void
    {
        $shop = Shop::firstOrFail();
        $phone = '+7' . mt_rand(9000000000, 9999999999);

        $dup = Client::create(['phone' => $phone, 'name' => 'Dup', 'is_authorized' => true]);
        $master = Client::create(['phone' => $phone, 'name' => 'Master', 'is_authorized' => true]);

        // master получает больше заказов → команда должна выбрать его
        $this->createOrder($master, $shop);
        $this->createOrder($master, $shop);
        $this->createOrder($master, $shop);
        $dupOrder = $this->createOrder($dup, $shop);

        $this->artisan('clients:dedupe', ['--phone' => $phone, '--apply' => true])
            ->assertExitCode(0);

        $dupRow = DB::table('clients')->where('id', $dup->id)->first();
        $masterRow = DB::table('clients')->where('id', $master->id)->first();

        $this->assertNotNull($dupRow->deleted_at, 'дубль должен быть soft-deleted');
        $this->assertNull($masterRow->deleted_at, 'master остаётся активным');

        $this->assertSame($master->id, Order::find($dupOrder->id)->client_id);
        $this->assertSame(4, Order::where('client_id', $master->id)->count());
    }

    /** @test */
    public function command_picks_master_with_most_orders(): void
    {
        $shop = Shop::firstOrFail();
        $phone = '+7' . mt_rand(9000000000, 9999999999);

        $older = Client::create(['phone' => $phone, 'name' => 'Older', 'is_authorized' => true]);
        $newer = Client::create(['phone' => $phone, 'name' => 'Newer', 'is_authorized' => true]);

        $this->createOrder($newer, $shop);
        $this->createOrder($newer, $shop);

        $this->artisan('clients:dedupe', ['--phone' => $phone, '--apply' => true])
            ->assertExitCode(0);

        $olderRow = DB::table('clients')->where('id', $older->id)->first();
        $newerRow = DB::table('clients')->where('id', $newer->id)->first();

        $this->assertNotNull($olderRow->deleted_at);
        $this->assertNull($newerRow->deleted_at, 'клиент с большим числом заказов должен быть master');
    }

    /** @test */
    public function command_is_noop_when_no_duplicates_exist(): void
    {
        $phone = '+7' . mt_rand(9000000000, 9999999999);
        Client::create(['phone' => $phone, 'name' => 'Solo', 'is_authorized' => true]);

        $this->artisan('clients:dedupe', ['--phone' => $phone, '--apply' => true])
            ->expectsOutputToContain('Дубли не найдены')
            ->assertExitCode(0);
    }

    private function createOrder(Client $client, Shop $shop): Order
    {
        return Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'client_id' => $client->id,
            'shop_id' => $shop->id,
            'status_id' => 5,
            'type_id' => 2,
            'amount' => 100,
            'payment_type_id' => 1,
        ]);
    }
}
