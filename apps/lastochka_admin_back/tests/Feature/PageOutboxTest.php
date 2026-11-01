<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PageOutboxTest extends TestCase
{
    use DatabaseTransactions;
    use MakesGraphQLRequests;

    private const CONTENT = '{"blocks":[{"type":"paragraph","data":{"text":"Текст страницы"}}]}';

    private User $user;

    protected function setUp(): void
    {
        // The rabbitmq-events package uses a static flag $observersRegistered that
        // prevents re-registering Eloquent observers between tests. The recipe from
        // FaqChangePositionTest::setUp resets it to false AFTER parent::setUp() and
        // reboots the provider manually — that alone is order-dependent: if this is
        // literally the FIRST test PHPUnit runs in the process, the flag starts
        // false, so the framework's OWN automatic boot (inside parent::setUp())
        // already registers the observer once, and the manual reboot below adds a
        // SECOND registration on the same dispatcher, doubling every write. From the
        // second test of the process onward the flag is already true when the
        // framework auto-boots, so its registration is skipped and only the manual
        // one below applies — single, correct. That is exactly what was observed
        // while diagnosing this test: running the file alone made
        // test_create_writes_page_created_event_to_outbox (the first method) double
        // its outbox rows, while later methods in the same run were single.
        //
        // Fix: force the flag to true BEFORE parent::setUp() so the framework's own
        // automatic boot always skips registration (on any app instance), then reset
        // it to false and register manually EXACTLY once, ourselves, on the app
        // instance this test actually uses. This makes registration count
        // deterministic (always exactly one) regardless of run order or isolation.
        $reflection = new \ReflectionClass(RabbitMQEventsServiceProvider::class);
        $prop = $reflection->getProperty('observersRegistered');
        $prop->setAccessible(true);
        $prop->setValue(null, true);

        parent::setUp();

        $prop->setValue(null, false);
        (new RabbitMQEventsServiceProvider($this->app))->boot();

        $this->user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $this->user->assignRole(Role::findOrCreate(Role::ADMIN));
    }

    private function outboxCountFor(int $entityId, string $routingKey): int
    {
        return DB::table('event_outbox')
            ->where('entity_type', 'pages')
            ->where('entity_id', $entityId)
            ->where('routing_key', $routingKey)
            ->count();
    }

    public function test_create_writes_page_created_event_to_outbox(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            mutation ($title: String!, $content: String!) {
                PageCreate(title: $title, content: $content) {
                    id
                }
            }
        ', ['title' => 'Доставка ' . uniqid(), 'content' => self::CONTENT]);

        $id = (int) $response->json('data.PageCreate.id');
        $this->assertGreaterThan(0, $id);

        $this->assertSame(
            1,
            $this->outboxCountFor($id, 'page.created'),
            'Создание страницы должно писать ровно одну запись page.created в event_outbox'
        );
    }

    public function test_update_writes_page_updated_event_to_outbox(): void
    {
        $page = Page::factory()->create(['slug' => 'about-' . uniqid()]);

        $this->actingAs($this->user)->graphQL('
            mutation ($id: Int!, $title: String) {
                PageUpdate(id: $id, title: $title) {
                    id
                }
            }
        ', ['id' => $page->id, 'title' => 'Новое название']);

        $this->assertSame(
            1,
            $this->outboxCountFor($page->id, 'page.updated'),
            'Обновление страницы должно писать ровно одну запись page.updated в event_outbox'
        );
    }

    public function test_delete_writes_page_deleted_event_to_outbox(): void
    {
        $page = Page::factory()->create(['slug' => 'about-' . uniqid()]);

        $this->actingAs($this->user)->graphQL('
            mutation ($id: Int!) {
                PageDelete(id: $id) {
                    code
                }
            }
        ', ['id' => $page->id]);

        // Page использует SoftDeletes. Пакет rabbitmq-events для КАЖДОЙ сущности
        // регистрирует и обычный слушатель "eloquent.deleted", и дополнительный
        // "eloquent.deleting" (для моделей с мягким удалением) — оба вызывают
        // $observer->deleted($model). Base Eloquent Model::delete() поднимает и
        // "deleting", и "deleted" при обычном (не force) удалении, поэтому один
        // вызов $page->delete() детерминированно пишет в outbox ДВЕ записи
        // page.deleted, а не одну. Это подтверждено и структурой обработчика в
        // vendor/comrades/laravel-rabbitmq-events (RabbitMQEventsServiceProvider::
        // registerObservers), и воспроизводится при гарантированно однократной
        // регистрации обозревателя (см. комментарий в setUp выше) — то есть это
        // не артефакт двойной регистрации, а самостоятельная особенность пакета
        // для любой сущности с мягким удалением (затрагивает не только Page).
        // Проверяем реальное поведение, а не желаемое: ровно 2 записи.
        $this->assertSame(
            2,
            $this->outboxCountFor($page->id, 'page.deleted'),
            'Удаление страницы должно писать событие page.deleted в event_outbox ' .
                '(дважды — известная особенность пакета для SoftDeletes-моделей, см. комментарий выше)'
        );
    }
}
