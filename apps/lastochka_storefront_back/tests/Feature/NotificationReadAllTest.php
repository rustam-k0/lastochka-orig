<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NotificationReadAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_all_marks_every_unread_as_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Order,
            'title' => 'order #1',
            'is_read' => false,
        ]);
        UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Promo,
            'title' => 'promo #1',
            'is_read' => false,
        ]);
        UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Order,
            'title' => 'order already read',
            'is_read' => true,
        ]);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->assertSame(0, UserNotification::query()->where('is_read', false)->count());
    }

    public function test_read_all_with_type_filter_touches_only_that_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $orderNotif = UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Order,
            'title' => 'order',
            'is_read' => false,
        ]);
        $promoNotif = UserNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::Promo,
            'title' => 'promo',
            'is_read' => false,
        ]);

        $this->patchJson('/api/v1/notifications/read-all?type=promo')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertFalse($orderNotif->refresh()->is_read);
        $this->assertTrue($promoNotif->refresh()->is_read);
    }

    public function test_read_all_ignores_other_users_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $otherNotif = UserNotification::create([
            'user_id' => $other->id,
            'type' => NotificationType::Order,
            'title' => 'other-user order',
            'is_read' => false,
        ]);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 0);

        $this->assertFalse($otherNotif->refresh()->is_read);
    }

    public function test_read_all_requires_authentication(): void
    {
        $this->patchJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }
}
