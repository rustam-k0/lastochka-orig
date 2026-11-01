<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Промо-уведомление живёт ровно столько, сколько его акция активна в админке.
 * Выключили галку «Активно» или удалили акцию — карточка уходит из приложения,
 * вернули — возвращается. Уведомления без привязки к акции не трогаем.
 */
final class NotificationAnnouncementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_index_hides_promo_notification_of_inactive_announcement(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $this->makePromoNotification($announcement->external_id, 'Нет настроения готовить?');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_index_hides_promo_notification_of_deleted_announcement(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8']);
        $this->makePromoNotification($announcement->external_id, 'Удалённая акция');

        $announcement->delete();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_index_keeps_promo_notification_of_active_announcement(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8']);
        $this->makePromoNotification($announcement->external_id, 'Живая акция');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Живая акция');
    }

    public function test_index_keeps_promo_notification_without_announcement(): void
    {
        $this->makePromoNotification(null, 'Рассылка без привязки к акции');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_index_keeps_promo_notification_when_announcement_is_unknown(): void
    {
        $this->makePromoNotification('777', 'Акция ещё не доехала по шине');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_index_keeps_order_notifications(): void
    {
        UserNotification::create([
            'user_id' => $this->user->id,
            'type' => NotificationType::Order,
            'title' => 'Заказ собран',
            'is_read' => false,
        ]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_reactivating_announcement_brings_notification_back(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $this->makePromoNotification($announcement->external_id, 'Вернувшаяся акция');

        $this->getJson('/api/v1/notifications')->assertJsonPath('total', 0);

        $announcement->update(['is_active' => true]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_unread_count_excludes_hidden_notifications(): void
    {
        $inactive = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $active = Announcement::factory()->create(['external_id' => '9']);

        $this->makePromoNotification($inactive->external_id, 'Скрытая');
        $this->makePromoNotification($active->external_id, 'Видимая');

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_show_returns_404_for_hidden_notification(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $notification = $this->makePromoNotification($announcement->external_id, 'Скрытая');

        $this->getJson("/api/v1/notifications/{$notification->id}")
            ->assertNotFound();
    }

    public function test_show_by_announcement_returns_404_for_hidden_notification(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $this->makePromoNotification($announcement->external_id, 'Скрытая');

        $this->getJson('/api/v1/notifications/by-announcement/8')
            ->assertNotFound();
    }

    public function test_mark_as_read_returns_404_for_hidden_notification(): void
    {
        $announcement = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $notification = $this->makePromoNotification($announcement->external_id, 'Скрытая');

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertFalse($notification->refresh()->is_read);
    }

    public function test_read_all_skips_hidden_notifications(): void
    {
        $inactive = Announcement::factory()->create(['external_id' => '8', 'is_active' => false]);
        $active = Announcement::factory()->create(['external_id' => '9']);

        $hidden = $this->makePromoNotification($inactive->external_id, 'Скрытая');
        $visible = $this->makePromoNotification($active->external_id, 'Видимая');

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertFalse($hidden->refresh()->is_read);
        $this->assertTrue($visible->refresh()->is_read);
    }

    private function makePromoNotification(?string $announcementId, string $title): UserNotification
    {
        return UserNotification::create([
            'user_id' => $this->user->id,
            'type' => NotificationType::Promo,
            'title' => $title,
            'announcement_id' => $announcementId,
            'is_read' => false,
        ]);
    }
}
