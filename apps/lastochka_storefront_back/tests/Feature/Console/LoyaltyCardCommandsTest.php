<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyCardCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_cards_only_for_users_with_phone_and_without_card(): void
    {
        $withPhone = User::factory()->create(['phone' => '+79990000001']);
        $withCard = User::factory()->create([
            'phone' => '+79990000002',
            'card_number' => '07000002',
            'card_created_at' => now(),
        ]);
        $noPhone = User::factory()->create(['phone' => '']);

        $this->artisan('loyalty:backfill-cards')->assertSuccessful();

        $this->assertNotNull($withPhone->refresh()->card_number);
        $this->assertSame('07000002', $withCard->refresh()->card_number);
        $this->assertNull($noPhone->refresh()->card_number);
    }

    public function test_republish_targets_only_stale_pending_cards(): void
    {
        $stale = User::factory()->create([
            'phone' => '+79990000001',
            'card_number' => '07000001',
            'card_created_at' => now()->subHours(2),
        ]);
        $fresh = User::factory()->create([
            'phone' => '+79990000002',
            'card_number' => '07000002',
            'card_created_at' => now()->subMinutes(5),
        ]);
        $confirmed = User::factory()->create([
            'phone' => '+79990000003',
            'card_number' => '07000003',
            'card_created_at' => now()->subHours(3),
            'card_confirmed_at' => now(),
        ]);

        $this->artisan('loyalty:republish-pending-cards')->assertSuccessful();

        $payloads = EventOutbox::where('routing_key', 'loyalty_card.created')->get()
            ->map(fn ($row) => $row->payload['card_number']);
        $this->assertContains('07000001', $payloads);
        $this->assertNotContains('07000002', $payloads);
        $this->assertNotContains('07000003', $payloads);
    }
}
