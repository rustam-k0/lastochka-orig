<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCard;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientDeleteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;

    protected function setUp(): void
    {
        // Сбрасываем флаг observersRegistered чтобы RabbitMQ observer
        // перерегистрировался с новым event dispatcher после refreshApplication().
        // Иначе outbox-события не пишутся в рамках теста.
        $ref = new \ReflectionClass(RabbitMQEventsServiceProvider::class);
        $prop = $ref->getProperty('observersRegistered');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();

        $this->user = $this->adminUser();
        $this->shop = Shop::firstOrFail();

        // Чистим lighthouse schema cache между тестами
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    private function createClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestDelete',
            'is_authorized' => true,
        ], $overrides));
    }

    private function createOrder(Client $client, int $statusId): Order
    {
        return Order::create([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'client_id' => $client->id,
            'shop_id' => $this->shop->id,
            'type_id' => Type::DELIVERY_COURIER ?? 2,
            'status_id' => $statusId,
            'amount' => 100,
            'origin_amount' => 100,
            'payment_type_id' => 1,
        ]);
    }

    /** @test */
    public function it_soft_deletes_client_and_cards_when_no_active_orders(): void
    {
        $client = $this->createClient();
        $card = ClientCard::create([
            'client_id' => $client->id,
            'binding_id' => 'binding-'.uniqid(),
            'masked_pan' => '****1111',
            'expiry_date' => '12/30',
            'selected' => false,
        ]);
        $finishedOrder = $this->createOrder($client, Status::ORDER_FINISHED);
        $cancelledOrder = $this->createOrder($client, Status::ORDER_CANCELLED);

        $mutation = '
            mutation ClientDelete($id: Int!) {
                ClientDelete(id: $id) {
                    success
                    orders_count
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, ['id' => $client->id]);

        $data = $response->json();
        $this->assertArrayNotHasKey('errors', $data, 'ClientDelete failed: '.json_encode($data['errors'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($data['data']['ClientDelete']['success']);
        $this->assertSame(2, $data['data']['ClientDelete']['orders_count']);

        // Клиент soft-deleted
        $this->assertNull(Client::find($client->id));
        $this->assertNotNull(Client::withTrashed()->withoutGlobalScopes()->find($client->id)->deleted_at);

        // Карта soft-deleted
        $this->assertNull(ClientCard::find($card->id));
        $this->assertNotNull(ClientCard::withTrashed()->find($card->id)->deleted_at);

        // Заказы на месте (не удалены)
        $this->assertNotNull(Order::find($finishedOrder->id));
        $this->assertNotNull(Order::find($cancelledOrder->id));
    }

    /** @test */
    public function it_rejects_deletion_when_client_has_active_orders(): void
    {
        $client = $this->createClient();
        $activeOrder = $this->createOrder($client, Status::ORDER_PREPARING);

        $mutation = '
            mutation ClientDelete($id: Int!) {
                ClientDelete(id: $id) {
                    success
                    orders_count
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, ['id' => $client->id]);

        $data = $response->json();
        $this->assertArrayHasKey('errors', $data);
        $errorMessage = $data['errors'][0]['message'];
        $this->assertStringContainsString('активными заказами', $errorMessage);
        $this->assertStringContainsString((string) $activeOrder->id, $errorMessage);

        // Клиент НЕ удалён
        $this->assertNotNull(Client::find($client->id));
        $this->assertNull(Client::find($client->id)->deleted_at);
    }

    /** @test */
    public function it_rejects_each_active_order_status(): void
    {
        foreach ([Status::ORDER_CREATED, Status::ORDER_PREPARING, Status::ORDER_ASSEMBLED, Status::ORDER_IN_DELIVERY] as $statusId) {
            $client = $this->createClient();
            $this->createOrder($client, $statusId);

            $response = $this->actingAs($this->user)
                ->graphQL('
                    mutation ClientDelete($id: Int!) {
                        ClientDelete(id: $id) { success orders_count }
                    }
                ', ['id' => $client->id]);

            $data = $response->json();
            $this->assertArrayHasKey('errors', $data, "Should reject deletion with status $statusId");
        }
    }

    /** @test */
    public function it_returns_not_found_for_unknown_client(): void
    {
        $response = $this->actingAs($this->user)
            ->graphQL('
                mutation ClientDelete($id: Int!) {
                    ClientDelete(id: $id) { success orders_count }
                }
            ', ['id' => 999999999]);

        $data = $response->json();
        $this->assertArrayHasKey('errors', $data);
    }

    /** @test */
    public function it_rejects_deleting_already_deleted_client(): void
    {
        $client = $this->createClient();
        $client->delete();

        $response = $this->actingAs($this->user)
            ->graphQL('
                mutation ClientDelete($id: Int!) {
                    ClientDelete(id: $id) { success orders_count }
                }
            ', ['id' => $client->id]);

        $data = $response->json();
        // Модель с SoftDeletes после delete() возвращает null из findOrFail (по умолчанию без trashed)
        // -> ModelNotFoundException -> GraphQL error
        $this->assertArrayHasKey('errors', $data);
    }

    /** @test */
    public function client_event_transformer_includes_id_and_deleted_at_for_deleted_client(): void
    {
        $client = $this->createClient();
        $client->delete();
        $client->refresh();

        $transformer = new \App\Transformers\ClientEventTransformer();
        $payload = $transformer->toEvent($client);

        $this->assertSame((string) $client->id, $payload['id']);
        $this->assertNotNull($payload['deleted_at']);
        $this->assertNotNull($payload['event_timestamp']);
    }

    /** @test */
    public function client_delete_creates_outbox_record(): void
    {
        $client = $this->createClient();
        $outboxBefore = EventOutbox::max('id') ?? 0;

        $this->actingAs($this->user)
            ->graphQL('
                mutation ClientDelete($id: Int!) {
                    ClientDelete(id: $id) { success orders_count }
                }
            ', ['id' => $client->id]);

        $deletedEvent = EventOutbox::where('entity_type', 'customers')
            ->where('entity_id', $client->id)
            ->where('event_type', 'deleted')
            ->where('id', '>', $outboxBefore)
            ->first();

        $this->assertNotNull($deletedEvent, 'Outbox event for customer.deleted expected but not created');
        $this->assertSame('customer.deleted', $deletedEvent->routing_key);
    }
}
