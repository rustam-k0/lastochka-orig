<?php

namespace Tests\Feature\Bonus;

use App\Models\Client;
use App\Models\ClientBonusTransaction;
use App\Models\User;
use App\RabbitMQ\Handlers\ClientBonusOperationHandler;
use App\Transformers\ClientEventTransformer;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientBonusOperationConsumeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestBonusConsume',
            'is_authorized' => true,
        ], $attrs));
    }

    /**
     * Собирает EventConsumer сущности client_bonus_transactions с её боевым
     * конфигом — тот же путь, что и rabbitmq:consume в проде.
     */
    private function realConsumer(): EventConsumer
    {
        return new EventConsumer(
            'client_bonus_transactions',
            config('rabbitmq-events.entities.client_bonus_transactions')
        );
    }

    // =========================================================================
    // Прямые вызовы handle() ниже намеренно используют искусственную обёрнутую
    // форму ['event_type' => ..., 'payload' => [...]] — это НЕ то, что реально
    // летит по шине (см. BonusOperationEventTransformer::toEvent() в
    // storefront_back: плоский payload, event_type добавляется консьюмером из
    // routing key, ключа 'payload' в реальном сообщении нет вообще). Эти тесты
    // проверяют логику самого handler'а (что он устойчив к обеим формам через
    // `$eventData['payload'] ?? $eventData`), а не путь консьюмера целиком.
    // Путь целиком (EventConsumer::processEvent → validate() → handler,
    // плоский payload как в проде) покрыт отдельными тестами ниже —
    // fact_via_real_consumer_path_updates_transaction и
    // rejection_via_real_consumer_path_marks_transaction.
    // =========================================================================

    /** @test */
    public function fact_closes_pending_transaction(): void
    {
        $admin = $this->adminUser();
        $client = $this->makeClient(['loyalty_id' => 'LTY-CONSUME-' . mt_rand(100, 999)]);

        $tx = ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'OP-CONSUME-1',
            'type' => 'earn',
            'amount' => 100,
            'source' => 'manual',
            'description' => 'Компенсация',
            'admin_id' => $admin->id,
            'status' => ClientBonusTransaction::STATUS_PENDING,
        ]);

        $result = app(ClientBonusOperationHandler::class)->handle([
            'event_type' => 'created',
            'payload' => [
                'operation_id' => 'OP-CONSUME-1',
                'loyalty_id' => $client->loyalty_id,
                'client_phone' => $client->phone,
                'type' => 'earn',
                'amount' => 100,
                'balance_after' => 350,
                'description' => 'Компенсация',
                'operation_source' => 'manual',
                'order_external_id' => null,
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $this->assertSame('ack', $result);

        $tx->refresh();
        $this->assertSame(ClientBonusTransaction::STATUS_APPLIED, $tx->status);
        $this->assertSame(350, (int) $tx->balance_after);
        $this->assertNotNull($tx->occurred_at);
    }

    /** @test */
    public function fact_creates_row_for_operation_admin_never_saw(): void
    {
        $client = $this->makeClient();

        app(ClientBonusOperationHandler::class)->handle([
            'event_type' => 'created',
            'payload' => [
                'operation_id' => 'OP-ONLINE-1',
                'loyalty_id' => null,
                'client_phone' => $client->phone,
                'type' => 'spend',
                'amount' => 40,
                'balance_after' => 10,
                'description' => 'Списание за заказ #77',
                'operation_source' => 'online',
                'order_external_id' => 'EXT-77',
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $tx = ClientBonusTransaction::where('operation_id', 'OP-ONLINE-1')->firstOrFail();
        $this->assertSame($client->id, (int) $tx->client_id);
        $this->assertSame('online', $tx->source);
        $this->assertSame('EXT-77', $tx->order_external_id);
        $this->assertSame(ClientBonusTransaction::STATUS_APPLIED, $tx->status);
        $this->assertNull($tx->admin_id);
    }

    /** @test */
    public function fact_does_not_touch_client_balance_mirror(): void
    {
        $client = $this->makeClient(['bonus_balance' => 111]);

        app(ClientBonusOperationHandler::class)->handle([
            'event_type' => 'created',
            'payload' => [
                'operation_id' => 'OP-NOBAL-1',
                'loyalty_id' => null,
                'client_phone' => $client->phone,
                'type' => 'earn',
                'amount' => 90,
                'balance_after' => 201,
                'description' => 'Начисление',
                'operation_source' => 'manual',
                'occurred_at' => now()->toIso8601String(),
            ],
        ]);

        $this->assertSame(111, (int) $client->fresh()->bonus_balance);
    }

    /** @test */
    public function fact_is_idempotent_by_operation_id(): void
    {
        $client = $this->makeClient();
        $payload = [
            'operation_id' => 'OP-IDEMP-1',
            'loyalty_id' => null,
            'client_phone' => $client->phone,
            'type' => 'earn',
            'amount' => 15,
            'balance_after' => 15,
            'description' => 'Дубль',
            'operation_source' => 'manual',
            'occurred_at' => now()->toIso8601String(),
        ];

        app(ClientBonusOperationHandler::class)->handle(['event_type' => 'created', 'payload' => $payload]);
        app(ClientBonusOperationHandler::class)->handle(['event_type' => 'created', 'payload' => $payload]);

        $this->assertSame(1, ClientBonusTransaction::where('operation_id', 'OP-IDEMP-1')->count());
    }

    /** @test */
    public function rejection_marks_transaction_with_reason(): void
    {
        $client = $this->makeClient();

        $tx = ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'OP-REJECT-1',
            'type' => 'spend',
            'amount' => 900,
            'source' => 'manual',
            'description' => 'Списание',
            'status' => ClientBonusTransaction::STATUS_PENDING,
        ]);

        app(ClientBonusOperationHandler::class)->handle([
            'event_type' => 'rejected',
            'payload' => [
                'operation_id' => 'OP-REJECT-1',
                'reason' => 'insufficient_balance',
                'message' => 'Доступно к списанию: 120',
            ],
        ]);

        $tx->refresh();
        $this->assertSame(ClientBonusTransaction::STATUS_REJECTED, $tx->status);
        $this->assertSame('insufficient_balance', $tx->reject_reason);
        $this->assertSame('Доступно к списанию: 120', $tx->reject_message);
    }

    /** @test */
    public function fact_for_unknown_client_is_acked_without_row(): void
    {
        $result = app(ClientBonusOperationHandler::class)->handle([
            'event_type' => 'created',
            'payload' => [
                'operation_id' => 'OP-UNKNOWN-1',
                'loyalty_id' => 'LTY-NOBODY',
                'client_phone' => '+79990000000',
                'type' => 'earn',
                'amount' => 10,
                'balance_after' => 10,
                'operation_source' => 'manual',
            ],
        ]);

        $this->assertSame('ack', $result);
        $this->assertSame(0, ClientBonusTransaction::where('operation_id', 'OP-UNKNOWN-1')->count());
    }

    /** @test */
    public function client_transformer_accepts_bonus_mirror_fields(): void
    {
        $attributes = (new ClientEventTransformer())->fromEvent([
            'phone' => '+79001234567',
            'bonus_balance' => 640,
            'bonus_reserved' => 40,
        ]);

        $this->assertSame(640, $attributes['bonus_balance']);
        $this->assertSame(40, $attributes['bonus_reserved']);
    }

    /** @test */
    public function client_transformer_skips_bonus_fields_when_absent(): void
    {
        $attributes = (new ClientEventTransformer())->fromEvent([
            'phone' => '+79001234567',
            'name' => 'Партиальное событие',
        ]);

        $this->assertArrayNotHasKey('bonus_balance', $attributes);
        $this->assertArrayNotHasKey('bonus_reserved', $attributes);
    }

    /** @test */
    public function bonus_entity_is_registered_in_rabbitmq_config(): void
    {
        $entity = config('rabbitmq-events.entities.client_bonus_transactions');

        $this->assertIsArray($entity);
        $this->assertSame(ClientBonusOperationHandler::class, $entity['handler']);
        $this->assertContains('bonus_operation.created', $entity['routing_key_patterns']);
        $this->assertContains('bonus_operation.rejected', $entity['routing_key_patterns']);
        $this->assertNotEmpty(config('rabbitmq-events.inbound_queues.client_bonus_transactions'));
    }

    /**
     * Реальный путь консьюмера: EventConsumer::processEvent() сначала
     * прогоняет transformer->validate($eventData), и только потом (раз в
     * конфиге сущности есть 'handler') роутит на ClientBonusOperationHandler.
     * Payload плоский, без обёртки 'payload' — именно так выглядит
     * json_decode() тела сообщения, которое реально публикует storefront_back
     * (BonusOperationEventTransformer::toEvent()), плюс event_type,
     * подставленный ConsumeEvents::handleMessage() из последнего сегмента
     * routing key 'bonus_operation.created'.
     *
     * event_source = 'storefront' (чужой источник) обязателен: если он
     * совпадёт с config('rabbitmq-events.source') admin_back, EventConsumer
     * молча вернёт 'ack' до всякой валидации и до вызова handler'а — тест
     * был бы зелёным по неправильной причине.
     */
    /** @test */
    public function fact_via_real_consumer_path_updates_transaction(): void
    {
        $admin = $this->adminUser();
        $client = $this->makeClient(['loyalty_id' => 'LTY-REALPATH-' . mt_rand(100, 999)]);

        $tx = ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'OP-REALPATH-1',
            'type' => 'earn',
            'amount' => 100,
            'source' => 'manual',
            'description' => 'Компенсация',
            'admin_id' => $admin->id,
            'status' => ClientBonusTransaction::STATUS_PENDING,
        ]);

        $result = $this->realConsumer()->processEvent([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_source' => 'storefront',
            'shop_code' => 'default',
            'source' => 'storefront',
            'event_type' => 'created',
            'operation_id' => 'OP-REALPATH-1',
            'loyalty_id' => $client->loyalty_id,
            'client_phone' => $client->phone,
            'type' => 'earn',
            'amount' => 100,
            'balance_after' => 350,
            'description' => 'Компенсация',
            'operation_source' => 'manual',
            'order_external_id' => null,
            'occurred_at' => now()->toIso8601String(),
        ]);

        $this->assertSame('ack', $result);

        $tx->refresh();
        $this->assertSame(ClientBonusTransaction::STATUS_APPLIED, $tx->status);
        $this->assertSame(350, (int) $tx->balance_after);
        $this->assertNotNull($tx->occurred_at);
    }

    /**
     * Главный тест фикс-раунда: воспроизводит ровно тот payload отказа, что
     * реально публикует storefront (BonusOperationEventTransformer::
     * publishRejection() в storefront_back) — event_id, event_timestamp,
     * event_source, source, operation_id, reason, message, БЕЗ поля 'id'.
     *
     * Без customEventRequiredFields = ['rejected' => ['operation_id']] в
     * ClientBonusOperationEventTransformer этот тест падает: 'rejected' не
     * входит в стандартные типы ValidatesEventData (created/updated/deleted),
     * поэтому validate() требует ['event_id', 'id', 'source'], поля 'id' в
     * payload нет — validate() возвращает false, EventConsumer::processEvent()
     * бросает InvalidEventException, до handler'а событие не доходит.
     * Проверено вручную (см. task-7-report.md, раздел «Фикс-раунд 1»).
     */
    /** @test */
    public function rejection_via_real_consumer_path_marks_transaction(): void
    {
        $client = $this->makeClient();

        $tx = ClientBonusTransaction::create([
            'client_id' => $client->id,
            'operation_id' => 'OP-REALPATH-REJECT-1',
            'type' => 'spend',
            'amount' => 900,
            'source' => 'manual',
            'description' => 'Списание',
            'status' => ClientBonusTransaction::STATUS_PENDING,
        ]);

        $result = $this->realConsumer()->processEvent([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->toIso8601String(),
            'event_source' => 'storefront',
            'source' => 'storefront',
            'event_type' => 'rejected',
            'operation_id' => 'OP-REALPATH-REJECT-1',
            'reason' => 'insufficient_balance',
            'message' => 'Доступно к списанию: 120',
        ]);

        $this->assertSame('ack', $result);

        $tx->refresh();
        $this->assertSame(ClientBonusTransaction::STATUS_REJECTED, $tx->status);
        $this->assertSame('insufficient_balance', $tx->reject_reason);
        $this->assertSame('Доступно к списанию: 120', $tx->reject_message);
    }
}
