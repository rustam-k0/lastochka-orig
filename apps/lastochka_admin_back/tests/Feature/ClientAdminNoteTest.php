<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class ClientAdminNoteTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        // Убеждаемся, что Lighthouse schema кэш не мешает — чистим перед каждым тестом.
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function migration_added_admin_note_column_to_clients(): void
    {
        $this->assertTrue(Schema::hasColumn('clients', 'admin_note'));
    }

    /** @test */
    public function admin_note_is_fillable_on_client_model(): void
    {
        $client = new Client();
        $this->assertContains('admin_note', $client->getFillable());
    }

    /** @test */
    public function client_update_saves_admin_note(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestAdminNote',
            'is_authorized' => true,
        ]);

        $mutation = '
            mutation ClientUpdate($id: Int!, $admin_note: String) {
                ClientUpdate(id: $id, admin_note: $admin_note) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $client->id,
                'admin_note' => 'Клиент постоянный, скидка по договорённости',
            ]);

        $response->assertJson([
            'data' => [
                'ClientUpdate' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ],
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'admin_note' => 'Клиент постоянный, скидка по договорённости',
        ]);
    }

    /** @test */
    public function client_update_normalizes_empty_string_admin_note_to_null(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestAdminNoteEmpty',
            'admin_note' => 'Существующая заметка',
            'is_authorized' => true,
        ]);

        $mutation = '
            mutation ClientUpdate($id: Int!, $admin_note: String) {
                ClientUpdate(id: $id, admin_note: $admin_note) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $client->id,
                'admin_note' => '',
            ]);

        $response->assertJson([
            'data' => [
                'ClientUpdate' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
            ],
        ]);

        $client->refresh();
        $this->assertNull($client->admin_note);
    }

    /** @test */
    public function client_update_rejects_admin_note_longer_than_2000_chars(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestAdminNoteOversize',
            'is_authorized' => true,
        ]);

        $mutation = '
            mutation ClientUpdate($id: Int!, $admin_note: String) {
                ClientUpdate(id: $id, admin_note: $admin_note) {
                    code
                    message
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($mutation, [
                'id' => $client->id,
                'admin_note' => str_repeat('а', 2001),
            ]);

        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function admin_note_is_returned_in_admin_clients_item_query(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestReadback',
            'admin_note' => 'Заметка менеджера',
            'is_authorized' => true,
        ]);

        $query = '
            query ($id: Int!) {
                admin_clients_item(id: $id) {
                    id
                    admin_note
                }
            }
        ';

        $response = $this->actingAs($this->user)
            ->graphQL($query, ['id' => $client->id]);

        $data = $response->json();
        $this->assertArrayNotHasKey(
            'errors',
            $data,
            'GraphQL returned errors: '.json_encode($data['errors'] ?? null, JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame($client->id, $data['data']['admin_clients_item']['id']);
        $this->assertSame('Заметка менеджера', $data['data']['admin_clients_item']['admin_note']);
    }

    /** @test */
    public function admin_note_not_included_in_client_event_transformer_payload(): void
    {
        $client = Client::create([
            'phone' => '+7' . mt_rand(9000000000, 9999999999),
            'name' => 'TestOutbox',
            'admin_note' => 'НЕ ДОЛЖНО попасть в outbox',
            'is_authorized' => true,
        ]);

        $transformer = new \App\Transformers\ClientEventTransformer();
        $payload = $transformer->toEvent($client);

        $this->assertArrayNotHasKey('admin_note', $payload);
    }
}
