<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PagePositionTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    public function test_change_position_makes_order_dense(): void
    {
        $user = $this->makeAdminUser();

        // Своя изолированная выборка: в БД уже лежат системные страницы,
        // поэтому проверяем относительный порядок трёх созданных записей.
        $first = Page::factory()->create(['slug' => 'p1-' . uniqid(), 'position' => 1]);
        $second = Page::factory()->create(['slug' => 'p2-' . uniqid(), 'position' => 2]);
        $third = Page::factory()->create(['slug' => 'p3-' . uniqid(), 'position' => 3]);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $position: Int!) {
                PageChangePosition(id: $id, position: $position) {
                    id
                    position
                }
            }
        ', ['id' => $third->id, 'position' => 1]);

        $positions = Page::pluck('position', 'id');

        // Перемещённая страница стоит выше двух остальных
        $this->assertLessThan($positions[$first->id], $positions[$third->id]);
        $this->assertLessThan($positions[$second->id], $positions[$third->id]);

        // Нумерация плотная: 1..N без дыр и повторов
        $all = Page::orderBy('position')->pluck('position')->all();
        $this->assertSame(range(1, count($all)), $all, 'Позиции должны быть плотными 1..N');
    }

    public function test_change_position_fires_model_events_per_updated_page(): void
    {
        $user = $this->makeAdminUser();

        $first = Page::factory()->create(['slug' => 'p1-' . uniqid(), 'position' => 1]);
        $second = Page::factory()->create(['slug' => 'p2-' . uniqid(), 'position' => 2]);
        $third = Page::factory()->create(['slug' => 'p3-' . uniqid(), 'position' => 3]);

        // В таблице уже есть системные страницы миграции, чьи позиции могут
        // совпадать с расставленными выше — сколько записей реально сдвинется,
        // заранее неизвестно. Поэтому не хардкодим число, а считаем его из
        // фактической разницы position до/после и сверяем со счётчиком событий.
        $positionsBefore = Page::pluck('position', 'id');

        // Массовое обновление (Page::whereIn(...)->update(...)) даёт тот же
        // итоговый порядок, но НЕ поднимает Eloquent-события — outbox-observer
        // не сработает, и новые позиции не доедут в витрину. Единственный
        // способ отличить поштучное обновление от массового снаружи метода —
        // проверить, что событие updated модели реально сработало.
        //
        // Диспетчер событий модели (Model::$dispatcher) — общий статический
        // ресурс на весь процесс: на него же вешаются слушатели других
        // пакетов (например outbox-обозреватель RabbitMQEvents). Чтобы
        // счётчик не зависел от того, что ещё могло быть навешено на общий
        // диспетчер до этого теста, поднимаем СВОЙ изолированный диспетчер
        // только для длительности этого теста, слушаем событие только на
        // нём и гарантированно возвращаем оригинальный диспетчер обратно
        // в finally.
        $updatedCount = 0;
        $originalDispatcher = Page::getEventDispatcher();
        $isolatedDispatcher = new Dispatcher($this->app);
        $isolatedDispatcher->listen('eloquent.updated: ' . Page::class, function () use (&$updatedCount) {
            $updatedCount++;
        });
        Page::setEventDispatcher($isolatedDispatcher);

        try {
            $this->actingAs($user)->graphQL('
                mutation ($id: Int!, $position: Int!) {
                    PageChangePosition(id: $id, position: $position) {
                        id
                        position
                    }
                }
            ', ['id' => $third->id, 'position' => 1]);
        } finally {
            Page::setEventDispatcher($originalDispatcher);
        }

        $positionsAfter = Page::pluck('position', 'id');
        $actuallyChanged = $positionsAfter->filter(
            fn ($position, $id) => ($positionsBefore[$id] ?? null) !== $position
        )->count();

        $this->assertGreaterThan(0, $actuallyChanged, 'Фикстура теста должна реально менять позиции хотя бы одной страницы');
        $this->assertSame(
            $actuallyChanged,
            $updatedCount,
            'Перестановка должна обновлять модели по одной, поднимая событие updated на каждую реально изменённую страницу'
        );
    }
}
