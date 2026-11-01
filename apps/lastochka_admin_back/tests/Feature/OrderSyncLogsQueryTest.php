<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class OrderSyncLogsQueryTest extends TestCase
{
    use MakesGraphQLRequests;
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // В проде таблицу order_1c_sync_logs держит сервис 1c_parser в собственной БД
        // (mendev_1c), ОТДЕЛЬНОЙ от БД admin_back; читаем её через подключение `one_c`.
        // Чтобы тест реально стерёг эту привязку (а не маскировал её дефолтным connection'ом,
        // как раньше), заводим отдельную тестовую БД для `one_c` — в основной тестовой БД
        // таблицы order_1c_sync_logs нет, поэтому без $connection='one_c' модель падала бы.
        $oneCDatabase = config('database.connections.' . config('database.default') . '.database') . '_1c';
        DB::unprepared("CREATE DATABASE IF NOT EXISTS `{$oneCDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $oneCConfig = config('database.connections.' . config('database.default'));
        $oneCConfig['database'] = $oneCDatabase;
        config()->set('database.connections.one_c', $oneCConfig);
        DB::purge('one_c');

        $schema = Schema::connection('one_c');
        if (! $schema->hasTable('order_1c_sync_logs')) {
            $schema->create('order_1c_sync_logs', function ($table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('shop_order_id')->nullable();
                $table->string('status', 20);
                $table->unsignedInteger('attempt_number')->default(1);
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }

        // Подключение `one_c` — отдельный PDO вне транзакции DatabaseTransactions,
        // поэтому чистим руками до и после каждого теста.
        DB::connection('one_c')->table('order_1c_sync_logs')->delete();

        $this->user = $this->adminUser();
    }

    protected function tearDown(): void
    {
        DB::connection('one_c')->table('order_1c_sync_logs')->delete();

        parent::tearDown();
    }

    /** @test */
    public function it_returns_sync_logs_for_order_number_newest_first(): void
    {
        DB::connection('one_c')->table('order_1c_sync_logs')->insert([
            ['order_id' => 1, 'shop_order_id' => 9001, 'status' => 'sent', 'attempt_number' => 1, 'message' => null, 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)],
            ['order_id' => 1, 'shop_order_id' => 9001, 'status' => 'error', 'attempt_number' => 2, 'message' => 'Connection timeout', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)],
            ['order_id' => 2, 'shop_order_id' => 9999, 'status' => 'success', 'attempt_number' => 1, 'message' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
            query ($number: String!) {
                orderSyncLogs(order_number: $number) {
                    status
                    attempt_number
                    message
                }
            }
        ', ['number' => '9001'])
            ->assertJson([
                'data' => [
                    'orderSyncLogs' => [
                        ['status' => 'error', 'attempt_number' => 2, 'message' => 'Connection timeout'],
                        ['status' => 'sent', 'attempt_number' => 1, 'message' => null],
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_strips_suborder_suffix(): void
    {
        DB::connection('one_c')->table('order_1c_sync_logs')->insert([
            'order_id' => 5, 'shop_order_id' => 12345, 'status' => 'sent', 'attempt_number' => 1, 'message' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
            query ($number: String!) {
                orderSyncLogs(order_number: $number) { status }
            }
        ', ['number' => '12345-2'])
            ->assertJsonCount(1, 'data.orderSyncLogs');
    }

    /** @test */
    public function it_returns_empty_without_error_when_one_c_database_is_not_configured(): void
    {
        // Форк/окружение без настроенной 1С-БД (DB_1C_DATABASE пуст): журнал недоступен,
        // но карточка заказа НЕ должна падать 500 — ожидаем пустой список.
        config()->set('database.connections.one_c.database', '');

        $this->actingAs($this->user)->graphQL(/** @lang GraphQL */ '
            query ($number: String!) {
                orderSyncLogs(order_number: $number) { status }
            }
        ', ['number' => '9001'])
            ->assertJsonCount(0, 'data.orderSyncLogs')
            ->assertJsonMissing(['errors' => []]);
    }
}
