<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\EntityDelete;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Удаление акции обязано публиковать announcement.deleted: без события
 * storefront-проекция живёт вечно, а вместе с ней и разосланные по акции
 * уведомления в мобилке. Query-builder delete события не триггерит —
 * только Eloquent delete.
 */
class AnnouncementDeletePublishesEventTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса до создания приложения observers регистрируются только
        // в первом тесте процесса и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();
    }

    public function test_announcement_delete_publishes_deleted_event(): void
    {
        $announcement = Announcement::create([
            'title' => 'Нет настроения готовить? ' . uniqid(),
            'short_description' => 'Короткое описание',
            'description' => 'Полное описание акции',
            'is_active' => true,
        ]);

        (new EntityDelete())(null, [
            'id' => $announcement->id,
            'table' => 'announcements',
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'announcements',
            'event_type' => 'deleted',
            'routing_key' => 'announcement.deleted',
            'entity_id' => (string) $announcement->id,
        ]);
    }

    public function test_announcement_delete_soft_deletes_row(): void
    {
        $announcement = Announcement::create([
            'title' => 'Удаляемая акция ' . uniqid(),
            'short_description' => 'Короткое описание',
            'description' => 'Полное описание акции',
            'is_active' => true,
        ]);

        (new EntityDelete())(null, [
            'id' => $announcement->id,
            'table' => 'announcements',
        ]);

        $this->assertNotNull($announcement->fresh()->deleted_at);
    }

    public function test_admin_can_delete_announcement_via_graphql(): void
    {
        $announcement = Announcement::create([
            'title' => 'Акция для удаления через API ' . uniqid(),
            'short_description' => 'Короткое описание',
            'description' => 'Полное описание акции',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser())
            ->graphQL('
                mutation ($id: Int!) {
                    announcementDelete(id: $id, table: "announcements") {
                        code
                        message
                    }
                }
            ', ['id' => $announcement->id]);

        $response->assertGraphQLErrorFree();

        $this->assertSoftDeleted('announcements', ['id' => $announcement->id]);
    }
}
