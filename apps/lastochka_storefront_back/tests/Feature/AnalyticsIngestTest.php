<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Приём B2C-событий аналитики: POST /api/v1/analytics/events
 * (LASTOCHKI-JAMI-11659, контракт client_id/session_id #11642).
 */
final class AnalyticsIngestTest extends TestCase
{
    use RefreshDatabase;

    private function validEvent(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'product.viewed',
            'client_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'product_id' => 15,
            'source_screen' => 'category',
        ], $overrides);
    }

    public function test_anonymous_batch_is_accepted_and_pushed_to_outbox(): void
    {
        $clientId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/analytics/events', [
            'events' => [
                $this->validEvent(['client_id' => $clientId]),
                $this->validEvent(['client_id' => $clientId, 'event_type' => 'cart.item_added', 'source' => 'search']),
            ],
        ]);

        $response->assertStatus(202)->assertJson(['accepted' => 2, 'rejected' => []]);

        $rows = \DB::table('event_outbox')->where('entity_type', 'b2c_events')->get();
        $this->assertCount(2, $rows);

        $viewed = $rows->firstWhere('routing_key', 'b2c.product.viewed');
        $this->assertNotNull($viewed);

        $payload = json_decode($viewed->payload, true);
        $this->assertSame($clientId, $payload['client_id']);
        $this->assertSame($clientId, $payload['device_client_id']);
        $this->assertSame('product.viewed', $payload['event_type']);
        $this->assertSame(15, $payload['product_id']);
        $this->assertNotEmpty($payload['event_id']);
        $this->assertNotEmpty($payload['session_id']);

        // Аноним — склейки нет
        $this->assertSame(0, \DB::table('event_outbox')->where('routing_key', 'b2c.identity.linked')->count());
    }

    public function test_authorized_batch_sets_user_client_id_and_links_identity(): void
    {
        $user = User::factory()->create();
        $deviceId = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/v1/analytics/events', [
            'events' => [
                $this->validEvent(['client_id' => $deviceId]),
            ],
        ]);

        $response->assertStatus(202)->assertJson(['accepted' => 1]);

        $row = \DB::table('event_outbox')->where('routing_key', 'b2c.product.viewed')->first();
        $payload = json_decode($row->payload, true);

        $this->assertSame((string) $user->id, $payload['client_id']);
        $this->assertSame($deviceId, $payload['device_client_id']);

        $link = \DB::table('event_outbox')->where('routing_key', 'b2c.identity.linked')->first();
        $this->assertNotNull($link, 'identity.linked не опубликован');

        $linkPayload = json_decode($link->payload, true);
        $this->assertSame($deviceId, $linkPayload['anon_client_id']);
        $this->assertSame((string) $user->id, $linkPayload['user_id']);
    }

    public function test_invalid_event_is_rejected_without_failing_batch(): void
    {
        $response = $this->postJson('/api/v1/analytics/events', [
            'events' => [
                $this->validEvent(),
                $this->validEvent(['event_type' => 'hack.attempt']),
                $this->validEvent(['client_id' => 'not-a-uuid']),
                $this->validEvent(['occurred_at' => now()->subDays(30)->toIso8601String()]),
            ],
        ]);

        $response->assertStatus(202)->assertJsonPath('accepted', 1);

        $rejected = $response->json('rejected');
        $this->assertCount(3, $rejected);
        $this->assertSame([1, 2, 3], array_column($rejected, 'index'));
        $this->assertSame('unknown event_type', $rejected[0]['reason']);
        $this->assertSame('client_id must be a UUID', $rejected[1]['reason']);
        $this->assertSame('occurred_at is too old', $rejected[2]['reason']);
    }

    public function test_oversized_batch_is_rejected_entirely(): void
    {
        $events = array_fill(0, 101, $this->validEvent());

        $this->postJson('/api/v1/analytics/events', ['events' => $events])
            ->assertStatus(422);
    }

    public function test_empty_batch_is_rejected(): void
    {
        $this->postJson('/api/v1/analytics/events', ['events' => []])
            ->assertStatus(422);
    }
}
