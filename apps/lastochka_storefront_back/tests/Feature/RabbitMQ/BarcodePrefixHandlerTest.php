<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\StorefrontSetting;
use App\RabbitMQ\Handlers\BarcodePrefixHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodePrefixHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_card_prefix_setting(): void
    {
        (new BarcodePrefixHandler())->handle([
            'payload' => ['type' => 'card', 'value' => '09'],
        ]);

        $this->assertSame('09', StorefrontSetting::getValue('loyalty_card_prefix'));
    }

    public function test_overwrites_existing_prefix(): void
    {
        StorefrontSetting::create(['key' => 'loyalty_card_prefix', 'value' => '07', 'type' => 'string']);

        (new BarcodePrefixHandler())->handle([
            'payload' => ['type' => 'card', 'value' => '08'],
        ]);

        $this->assertSame('08', StorefrontSetting::getValue('loyalty_card_prefix'));
    }

    public function test_ignores_non_card_types_and_empty_values(): void
    {
        (new BarcodePrefixHandler())->handle(['payload' => ['type' => 'weight', 'value' => '22']]);
        (new BarcodePrefixHandler())->handle(['payload' => ['type' => 'card', 'value' => '']]);

        $this->assertNull(StorefrontSetting::getValue('loyalty_card_prefix'));
    }

    public function test_ignores_non_string_value(): void
    {
        (new BarcodePrefixHandler())->handle(['payload' => ['type' => 'card', 'value' => 42]]);
        (new BarcodePrefixHandler())->handle(['payload' => ['type' => 'card', 'value' => ['07']]]);

        $this->assertNull(StorefrontSetting::getValue('loyalty_card_prefix'));
    }

    public function test_ignores_value_longer_than_four_digits(): void
    {
        (new BarcodePrefixHandler())->handle([
            'payload' => ['type' => 'card', 'value' => '123456789012345'],
        ]);

        $this->assertNull(StorefrontSetting::getValue('loyalty_card_prefix'));
    }

    public function test_ignores_non_digit_value(): void
    {
        (new BarcodePrefixHandler())->handle([
            'payload' => ['type' => 'card', 'value' => 'AB'],
        ]);

        $this->assertNull(StorefrontSetting::getValue('loyalty_card_prefix'));
    }
}
