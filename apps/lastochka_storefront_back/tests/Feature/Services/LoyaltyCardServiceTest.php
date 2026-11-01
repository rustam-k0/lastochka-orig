<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Loyalty\LoyaltyCardService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyCardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LoyaltyCardService
    {
        return app(LoyaltyCardService::class);
    }

    public function test_ensure_card_generates_number_and_publishes_event(): void
    {
        $user = User::factory()->create(['phone' => '+79990001122']);

        $created = $this->service()->ensureCard($user);

        $this->assertTrue($created);
        $user->refresh();
        $this->assertMatchesRegularExpression('/^07\d{6}$/', $user->card_number);
        $this->assertNotNull($user->card_created_at);
        $this->assertNull($user->card_confirmed_at);
        $this->assertNotNull($user->loyalty_id);

        $outbox = EventOutbox::where('routing_key', 'loyalty_card.created')->first();
        $this->assertNotNull($outbox);
        $this->assertSame($user->card_number, $outbox->payload['card_number']);
        $this->assertSame($user->loyalty_id, $outbox->payload['loyalty_id']);
        $this->assertSame('+79990001122', $outbox->payload['phone']);
    }

    public function test_ensure_card_is_idempotent(): void
    {
        $user = User::factory()->create(['phone' => '+79990001122']);
        $this->service()->ensureCard($user);
        $number = $user->refresh()->card_number;

        $created = $this->service()->ensureCard($user->refresh());

        $this->assertFalse($created);
        $this->assertSame($number, $user->refresh()->card_number);
        $this->assertSame(1, EventOutbox::where('routing_key', 'loyalty_card.created')->count());
    }

    public function test_ensure_card_skips_user_without_phone(): void
    {
        $user = User::factory()->create(['phone' => '']);

        $created = $this->service()->ensureCard($user);

        $this->assertFalse($created);
        $this->assertNull($user->refresh()->card_number);
        $this->assertSame(0, EventOutbox::where('routing_key', 'loyalty_card.created')->count());
    }

    public function test_lost_race_syncs_caller_model(): void
    {
        $user = User::factory()->create(['phone' => '+79990001122']);
        $stale = User::find($user->id); // копия без card_number

        // «Другой процесс» уже создал карту
        $user->forceFill(['card_number' => '07111111', 'card_created_at' => now()])->save();

        $created = $this->service()->ensureCard($stale);

        $this->assertFalse($created);
        $this->assertSame('07111111', $stale->card_number); // без refresh()
        $this->assertSame(0, EventOutbox::where('routing_key', 'loyalty_card.created')->count());
    }

    public function test_generate_uses_prefix_from_settings(): void
    {
        StorefrontSetting::create(['key' => 'loyalty_card_prefix', 'value' => '09', 'type' => 'string']);

        $number = $this->service()->generateCardNumber();

        $this->assertMatchesRegularExpression('/^09\d{6}$/', $number);
    }

    public function test_generate_retries_on_collision(): void
    {
        // Занимаем номер — сервис обязан выдать другой
        User::factory()->create(['phone' => '+79990009999', 'card_number' => '07000001']);

        $service = new class extends LoyaltyCardService {
            public array $sequence = ['000001', '424242'];

            protected function randomDigits(): string
            {
                return array_shift($this->sequence);
            }
        };

        $number = $service->generateCardNumber();

        $this->assertSame('07424242', $number);
    }
}
