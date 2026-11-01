<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCanReceiveEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_disabled_by_default(): void
    {
        // fresh(): дефолт живёт в колонке БД, factory()->create() строку не перечитывает.
        $user = User::factory()->create()->fresh();

        $this->assertFalse($user->email_enabled);
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_true_when_channel_type_and_address_are_ok(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertTrue($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_false_when_type_flag_disabled(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'promo_enabled' => false,
        ]);

        $this->assertFalse($user->canReceiveEmail(NotificationType::Promo));
    }

    public function test_returns_false_when_address_missing(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertFalse($user->hasDeliverableEmail());
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_returns_false_when_address_malformed(): void
    {
        $user = User::factory()->create([
            'email' => 'не-адрес',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $this->assertFalse($user->hasDeliverableEmail());
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }

    public function test_unset_attribute_is_treated_as_disabled(): void
    {
        // Модель без загруженного атрибута: email_enabled === null.
        // Проверяем fail-safe ветку `?? false` — в отличие от push (`?? true`).
        $user = new User(['email' => 'client@example.com']);

        $this->assertNull($user->email_enabled);
        $this->assertFalse($user->canReceiveEmail(NotificationType::Order));
    }
}
