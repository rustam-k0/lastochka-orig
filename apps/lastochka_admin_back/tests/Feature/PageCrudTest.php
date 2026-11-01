<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PageCrudTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private const CONTENT = '{"blocks":[{"type":"paragraph","data":{"text":"Текст страницы"}}]}';

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

    private function createPage(User $user, array $variables)
    {
        return $this->actingAs($user)->graphQL('
            mutation (
                $slug: String
                $title: String!
                $content: String!
                $meta_title: String
                $meta_description: String
                $is_published: Boolean
            ) {
                PageCreate(
                    slug: $slug
                    title: $title
                    content: $content
                    meta_title: $meta_title
                    meta_description: $meta_description
                    is_published: $is_published
                ) {
                    id
                    slug
                    title
                    content
                    meta_title
                    is_published
                    is_system
                    position
                }
            }
        ', $variables);
    }

    public function test_creates_page_with_explicit_slug(): void
    {
        $user = $this->makeAdminUser();
        $slug = 'about-' . uniqid();

        $response = $this->createPage($user, [
            'slug' => $slug,
            'title' => 'О компании',
            'content' => self::CONTENT,
            'meta_title' => 'О компании — Русский Разгуляйка',
            'is_published' => false,
        ]);

        $response->assertJson([
            'data' => [
                'PageCreate' => [
                    'slug' => $slug,
                    'title' => 'О компании',
                    'content' => self::CONTENT,
                    'meta_title' => 'О компании — Русский Разгуляйка',
                    'is_published' => false,
                    // Через API системную страницу создать нельзя
                    'is_system' => false,
                ],
            ],
        ]);

        $page = Page::findOrFail($response->json('data.PageCreate.id'));
        $this->assertNull($page->published_at);
        // Позиция без явного указания встаёт в конец списка
        $this->assertNotNull($page->position);
    }

    public function test_generates_slug_from_title_when_not_given(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'title' => 'Доставка и оплата',
            'content' => self::CONTENT,
        ]);

        $response->assertJsonPath('data.PageCreate.slug', 'dostavka-i-oplata');
    }

    public function test_generated_slug_gets_suffix_on_collision(): void
    {
        $user = $this->makeAdminUser();
        Page::factory()->create(['slug' => 'dostavka-i-oplata', 'title' => 'Доставка и оплата']);

        $response = $this->createPage($user, [
            'title' => 'Доставка и оплата',
            'content' => self::CONTENT,
        ]);

        $response->assertJsonPath('data.PageCreate.slug', 'dostavka-i-oplata-2');
    }

    public function test_rejects_invalid_slug(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'slug' => 'Политика Конфиденциальности',
            'title' => 'Политика',
            'content' => self::CONTENT,
        ]);

        $response->assertGraphQLValidationError('slug', 'Код страницы может содержать только латиницу в нижнем регистре, цифры и дефис');
    }

    public function test_rejects_duplicate_slug(): void
    {
        $user = $this->makeAdminUser();
        $slug = 'duplicate-' . uniqid();
        Page::factory()->create(['slug' => $slug]);

        $response = $this->createPage($user, [
            'slug' => $slug,
            'title' => 'Дубль',
            'content' => self::CONTENT,
        ]);

        $response->assertGraphQLValidationError('slug', 'Страница с таким кодом уже существует');
    }

    public function test_rejects_content_that_is_not_editorjs_document(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'title' => 'Битый контент',
            'content' => 'просто текст',
        ]);

        $response->assertGraphQLValidationError('content', 'Содержимое страницы обязательно: добавьте хотя бы один блок текста');
    }

    public function test_rejects_empty_blocks(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'title' => 'Пустая страница',
            'content' => '{"blocks":[]}',
        ]);

        $response->assertGraphQLValidationError('content', 'Содержимое страницы обязательно: добавьте хотя бы один блок текста');
    }

    public function test_created_published_page_gets_published_at(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'title' => 'Сразу опубликована',
            'content' => self::CONTENT,
            'is_published' => true,
        ]);

        $page = Page::findOrFail($response->json('data.PageCreate.id'));
        $this->assertNotNull($page->published_at);
    }

    public function test_generated_slug_does_not_end_with_dash_after_truncation(): void
    {
        $user = $this->makeAdminUser();

        // 20 повторов слова "тест" транслитерируются в "test-test-...-test":
        // ровно на 80-м символе (граница обрезки в generateSlug) стоит дефис
        // ("test-" повторяется блоками по 5 символов, 16 * 5 = 80). Это
        // детерминированно воспроизводит обрыв обрезки прямо на дефисе,
        // не полагаясь на конкретный словарь транслитерации.
        $title = trim(str_repeat('тест ', 20));

        $response = $this->createPage($user, [
            'title' => $title,
            'content' => self::CONTENT,
        ]);

        $slug = $response->json('data.PageCreate.slug');
        $this->assertLessThanOrEqual(100, mb_strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
    }

    public function test_slug_uniqueness_considers_soft_deleted_pages(): void
    {
        $user = $this->makeAdminUser();
        // Код совпадает с тем, что сгенерировала бы транслитерация этого же
        // названия — иначе проверка автогенерации ничего не найдёт.
        $slug = 'arhivnaya-stranitsa';
        $deletedPage = Page::factory()->create(['slug' => $slug, 'title' => 'Архивная страница']);
        $deletedPage->delete();

        // Явный код мягко удалённой страницы нельзя переиспользовать напрямую
        $duplicate = $this->createPage($user, [
            'slug' => $slug,
            'title' => 'Новая страница',
            'content' => self::CONTENT,
        ]);
        $duplicate->assertGraphQLValidationError('slug', 'Страница с таким кодом уже существует');

        // Автогенерация из того же названия не занимает код удалённой записи,
        // а добавляет суффикс
        $generated = $this->createPage($user, [
            'title' => 'Архивная страница',
            'content' => self::CONTENT,
        ]);
        $generated->assertJsonPath('data.PageCreate.slug', 'arhivnaya-stranitsa-2');
    }

    public function test_empty_string_slug_triggers_autogeneration(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPage($user, [
            'slug' => '',
            'title' => 'Доставка и оплата',
            'content' => self::CONTENT,
        ]);

        $response->assertJsonPath('data.PageCreate.slug', 'dostavka-i-oplata');
    }

    public function test_partial_update_does_not_wipe_other_fields(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create([
            'slug' => 'about-' . uniqid(),
            'title' => 'О компании',
            'content' => self::CONTENT,
            'meta_title' => 'Исходный SEO-заголовок',
        ]);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String) {
                PageUpdate(id: $id, title: $title) {
                    id
                    title
                    content
                    meta_title
                }
            }
        ', ['id' => $page->id, 'title' => 'О нашей компании']);

        $response->assertJson([
            'data' => [
                'PageUpdate' => [
                    'title' => 'О нашей компании',
                    'content' => self::CONTENT,
                    'meta_title' => 'Исходный SEO-заголовок',
                ],
            ],
        ]);
    }

    public function test_update_sets_published_at_on_first_publication(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create(['slug' => 'terms-' . uniqid()]);
        $this->assertNull($page->published_at);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $is_published: Boolean) {
                PageUpdate(id: $id, is_published: $is_published) {
                    id
                    is_published
                }
            }
        ', ['id' => $page->id, 'is_published' => true]);

        $fresh = $page->fresh();
        $this->assertTrue($fresh->is_published);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_update_with_unchanged_slug_succeeds(): void
    {
        $user = $this->makeAdminUser();
        $slug = 'unchanged-' . uniqid();
        $page = Page::factory()->create(['slug' => $slug, 'title' => 'Старое название']);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $slug: String, $title: String) {
                PageUpdate(id: $id, slug: $slug, title: $title) {
                    id
                    slug
                    title
                }
            }
        ', ['id' => $page->id, 'slug' => $slug, 'title' => 'Новое название']);

        $response->assertJson([
            'data' => [
                'PageUpdate' => [
                    'slug' => $slug,
                    'title' => 'Новое название',
                ],
            ],
        ]);
    }

    public function test_update_rejects_slug_of_another_page(): void
    {
        $user = $this->makeAdminUser();
        $otherSlug = 'taken-' . uniqid();
        Page::factory()->create(['slug' => $otherSlug]);
        $page = Page::factory()->create(['slug' => 'own-' . uniqid()]);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $slug: String) {
                PageUpdate(id: $id, slug: $slug) {
                    id
                    slug
                }
            }
        ', ['id' => $page->id, 'slug' => $otherSlug]);

        $response->assertGraphQLValidationError('slug', 'Страница с таким кодом уже существует');
    }

    public function test_republishing_does_not_change_published_at(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create(['slug' => 'republish-' . uniqid()]);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $is_published: Boolean) {
                PageUpdate(id: $id, is_published: $is_published) {
                    id
                }
            }
        ', ['id' => $page->id, 'is_published' => true]);
        $this->assertNotNull($page->fresh()->published_at);

        // Отодвигаем дату первой публикации в прошлое: `published_at` в БД
        // хранится с точностью до секунды, поэтому без разноса во времени
        // повторная публикация могла бы случайно попасть в ту же секунду
        // и замаскировать баг перезаписи даты.
        $firstPublishedAt = now()->subDays(5)->startOfSecond();
        $page->forceFill(['published_at' => $firstPublishedAt])->save();

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $is_published: Boolean) {
                PageUpdate(id: $id, is_published: $is_published) {
                    id
                }
            }
        ', ['id' => $page->id, 'is_published' => false]);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $is_published: Boolean) {
                PageUpdate(id: $id, is_published: $is_published) {
                    id
                }
            }
        ', ['id' => $page->id, 'is_published' => true]);

        $this->assertTrue($firstPublishedAt->equalTo($page->fresh()->published_at));
    }

    public function test_cannot_change_slug_of_system_page(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->system()->create(['slug' => 'system-' . uniqid()]);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $slug: String) {
                PageUpdate(id: $id, slug: $slug) {
                    id
                    slug
                }
            }
        ', ['id' => $page->id, 'slug' => 'new-slug-' . uniqid()]);

        $response->assertGraphQLValidationError('slug', 'Код системной страницы изменять нельзя');
    }

    public function test_can_update_title_of_system_page(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->system()->create(['slug' => 'system-' . uniqid(), 'title' => 'Оферта']);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String) {
                PageUpdate(id: $id, title: $title) {
                    id
                    title
                }
            }
        ', ['id' => $page->id, 'title' => 'Публичная оферта']);

        $response->assertJsonPath('data.PageUpdate.title', 'Публичная оферта');
    }

    public function test_deletes_page_and_frees_slug(): void
    {
        $user = $this->makeAdminUser();
        $slug = 'about-' . uniqid();
        $page = Page::factory()->create(['slug' => $slug]);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                PageDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $page->id]);

        $response->assertJson([
            'data' => [
                'PageDelete' => ['code' => 200, 'message' => 'OK'],
            ],
        ]);

        $this->assertNotNull(Page::withTrashed()->find($page->id)->deleted_at);

        // Код освобождён: страницу с тем же кодом можно создать заново.
        $again = $this->createPage($user, [
            'slug' => $slug,
            'title' => 'О компании заново',
            'content' => self::CONTENT,
        ]);
        $again->assertJsonPath('data.PageCreate.slug', $slug);
    }

    public function test_cannot_delete_system_page(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->system()->create(['slug' => 'system-' . uniqid()]);

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                PageDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $page->id]);

        $response->assertGraphQLErrorMessage('Системную страницу удалить нельзя. Снимите её с публикации, если она не нужна.');
        $this->assertNull(Page::find($page->id)->deleted_at);
    }
}
