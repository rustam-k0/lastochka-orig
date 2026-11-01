<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PageQueriesTest extends TestCase
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

    public function test_can_fetch_page_item(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create(['slug' => 'about-' . uniqid(), 'title' => 'О компании']);

        $response = $this->actingAs($user)->graphQL('
            query ($id: Int!) {
                admin_pages_item(id: $id) {
                    id
                    slug
                    title
                    content
                    is_published
                    is_system
                }
            }
        ', ['id' => $page->id]);

        $response->assertJson([
            'data' => [
                'admin_pages_item' => [
                    'slug' => $page->slug,
                    'title' => 'О компании',
                    'is_published' => false,
                    'is_system' => false,
                ],
            ],
        ]);
    }

    public function test_paginate_filters_by_search_and_status(): void
    {
        $user = $this->makeAdminUser();
        $marker = 'Доставка ' . uniqid();
        Page::factory()->published()->create(['slug' => 'delivery-' . uniqid(), 'title' => $marker]);
        Page::factory()->create(['slug' => 'other-' . uniqid(), 'title' => 'Другая страница']);
        Page::factory()->create(['slug' => 'delivery-draft-' . uniqid(), 'title' => $marker]);

        $response = $this->actingAs($user)->graphQL('
            query ($search: String, $is_published: Boolean) {
                admin_pages_paginate(search: $search, is_published: $is_published, first: 25) {
                    data {
                        title
                        is_published
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ', ['search' => $marker, 'is_published' => true]);

        $response->assertJson([
            'data' => [
                'admin_pages_paginate' => [
                    'data' => [
                        ['title' => $marker, 'is_published' => true],
                    ],
                    'paginatorInfo' => ['total' => 1],
                ],
            ],
        ]);
    }

    public function test_paginate_with_empty_ordering_puts_pages_without_position_last(): void
    {
        // Ровно то, что реально шлёт admin_front с исправлением находки 3:
        // ordering: [] — включает серверную сортировку по умолчанию,
        // а не жёсткий ASC по position (который уводил NULL в начало списка).
        $user = $this->makeAdminUser();
        $marker = uniqid();
        $withoutPosition = Page::factory()->create(['slug' => "no-pos-{$marker}", 'title' => 'Без позиции', 'position' => null]);
        $withPosition = Page::factory()->create(['slug' => "with-pos-{$marker}", 'title' => 'С позицией', 'position' => 1]);

        $response = $this->actingAs($user)->graphQL('
            query ($search: String, $ordering: [DefaultOrdering]) {
                admin_pages_paginate(search: $search, ordering: $ordering, first: 25) {
                    data {
                        id
                        title
                        position
                    }
                }
            }
        ', ['search' => $marker, 'ordering' => []]);

        $ids = array_column($response->json('data.admin_pages_paginate.data'), 'id');

        $this->assertSame(
            [$withPosition->id, $withoutPosition->id],
            $ids,
            'Страница без позиции должна идти после страницы с позицией, как на витрине'
        );
    }

    public function test_paginate_with_empty_ordering_breaks_position_ties_by_ascending_id(): void
    {
        // Тай-брейк при равных (или одинаково пустых) position должен совпадать
        // с витриной (StorefrontApi\Page::scopeSorter) и с пересчётом позиций
        // в App\Services\PageService::changePosition — оба идут по возрастанию id.
        // До фикса тай-брейк был orderByDesc('created_at'), то есть в обратном
        // порядке относительно id (новые записи — первыми).
        $user = $this->makeAdminUser();
        $marker = uniqid();

        $samePositionFirst = Page::factory()->create(['slug' => "tie-a-{$marker}", 'title' => 'Тай-брейк A', 'position' => 5]);
        $samePositionSecond = Page::factory()->create(['slug' => "tie-b-{$marker}", 'title' => 'Тай-брейк B', 'position' => 5]);
        $noPositionFirst = Page::factory()->create(['slug' => "tie-c-{$marker}", 'title' => 'Тай-брейк C', 'position' => null]);
        $noPositionSecond = Page::factory()->create(['slug' => "tie-d-{$marker}", 'title' => 'Тай-брейк D', 'position' => null]);

        $response = $this->actingAs($user)->graphQL('
            query ($search: String, $ordering: [DefaultOrdering]) {
                admin_pages_paginate(search: $search, ordering: $ordering, first: 25) {
                    data {
                        id
                        title
                        position
                    }
                }
            }
        ', ['search' => $marker, 'ordering' => []]);

        $ids = array_column($response->json('data.admin_pages_paginate.data'), 'id');

        $this->assertSame(
            [$samePositionFirst->id, $samePositionSecond->id, $noPositionFirst->id, $noPositionSecond->id],
            $ids,
            'При равных (или одинаково пустых) position тай-брейк должен идти по возрастанию id, как на витрине'
        );
    }
}
