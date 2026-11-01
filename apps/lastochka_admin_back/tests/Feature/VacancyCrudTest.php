<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class VacancyCrudTest extends TestCase
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

    public function test_can_create_update_and_delete_vacancy(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation (
                $title: String!
                $city: String
                $work_format: VacancyWorkFormat
                $employment_type: VacancyEmploymentType
                $short_description: String!
                $description: String!
            ) {
                VacancyCreate(
                    title: $title
                    city: $city
                    work_format: $work_format
                    employment_type: $employment_type
                    short_description: $short_description
                    description: $description
                ) {
                    id
                    title
                    city
                    work_format
                    employment_type
                    is_published
                    position
                }
            }
        ', [
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'work_format' => 'OFFICE',
            'employment_type' => 'FULL',
            'short_description' => 'Готовим блюда собственного производства',
            'description' => 'Ищем повара для приготовления полуфабрикатов и готовых блюд.',
        ]);

        $create->assertJson([
            'data' => [
                'VacancyCreate' => [
                    'title' => 'Повар горячего цеха',
                    'city' => 'Москва',
                    'work_format' => 'OFFICE',
                    'employment_type' => 'FULL',
                    // Созданная вакансия не публикуется автоматически
                    'is_published' => false,
                ],
            ],
        ]);

        $vacancyId = $create->json('data.VacancyCreate.id');
        $vacancy = Vacancy::findOrFail($vacancyId);
        $this->assertNull($vacancy->published_at);

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String, $salary: String) {
                VacancyUpdate(id: $id, title: $title, salary: $salary) {
                    id
                    title
                    salary
                    description
                }
            }
        ', [
            'id' => $vacancyId,
            'title' => 'Повар горячего цеха (Москва)',
            'salary' => '90 000 ₽',
        ]);

        $update->assertJson([
            'data' => [
                'VacancyUpdate' => [
                    'title' => 'Повар горячего цеха (Москва)',
                    'salary' => '90 000 ₽',
                    // Частичное обновление не затирает остальные поля
                    'description' => 'Ищем повара для приготовления полуфабрикатов и готовых блюд.',
                ],
            ],
        ]);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                VacancyDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $vacancyId]);

        $delete->assertJson(['data' => ['VacancyDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('vacancies', ['id' => $vacancyId]);
    }

    public function test_vacancy_link_fields_are_saved_and_returned(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($link_title: String, $link_url: String) {
                VacancyCreate(
                    title: "Кассир"
                    short_description: "Работа на кассе"
                    description: "Обслуживание покупателей на кассе."
                    link_title: $link_title
                    link_url: $link_url
                ) {
                    id
                    link_title
                    link_url
                }
            }
        ', [
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/98765',
        ]);

        $create->assertJson([
            'data' => [
                'VacancyCreate' => [
                    'link_title' => 'Смотреть на hh.ru',
                    'link_url' => 'https://hh.ru/vacancy/98765',
                ],
            ],
        ]);

        $vacancyId = $create->json('data.VacancyCreate.id');

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $link_title: String, $link_url: String) {
                VacancyUpdate(id: $id, link_title: $link_title, link_url: $link_url) {
                    id
                    link_title
                    link_url
                }
            }
        ', [
            'id' => $vacancyId,
            'link_title' => 'Откликнуться на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/11111',
        ]);

        $update->assertJson([
            'data' => [
                'VacancyUpdate' => [
                    'link_title' => 'Откликнуться на hh.ru',
                    'link_url' => 'https://hh.ru/vacancy/11111',
                ],
            ],
        ]);

        // Ссылку можно очистить, передав null
        $clear = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                VacancyUpdate(id: $id, link_title: null, link_url: null) {
                    id
                    link_title
                    link_url
                }
            }
        ', ['id' => $vacancyId]);

        $clear->assertJson([
            'data' => [
                'VacancyUpdate' => [
                    'link_title' => null,
                    'link_url' => null,
                ],
            ],
        ]);
    }

    public function test_vacancy_link_url_must_be_valid_url(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation {
                VacancyCreate(
                    title: "Кассир"
                    short_description: "Работа на кассе"
                    description: "Обслуживание покупателей на кассе."
                    link_url: "hh.ru/vacancy"
                ) {
                    id
                }
            }
        ');

        $response->assertGraphQLErrorMessage('Validation failed for the field [VacancyCreate].');
    }

    public function test_vacancy_link_title_requires_url(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation {
                VacancyCreate(
                    title: "Кассир"
                    short_description: "Работа на кассе"
                    description: "Обслуживание покупателей на кассе."
                    link_title: "Смотреть на hh.ru"
                ) {
                    id
                }
            }
        ');

        $response->assertGraphQLErrorMessage('Validation failed for the field [VacancyCreate].');
    }

    public function test_partial_update_keeps_vacancy_link(): void
    {
        $user = $this->makeAdminUser();
        $vacancy = Vacancy::factory()->create([
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/55555',
        ]);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String) {
                VacancyUpdate(id: $id, title: $title) { id }
            }
        ', ['id' => $vacancy->id, 'title' => 'Кассир-универсал']);

        $fresh = $vacancy->fresh();
        $this->assertSame('Смотреть на hh.ru', $fresh->link_title);
        $this->assertSame('https://hh.ru/vacancy/55555', $fresh->link_url);
    }

    public function test_create_requires_title_and_descriptions(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation {
                VacancyCreate(title: "", short_description: "", description: "") {
                    id
                }
            }
        ');

        $response->assertGraphQLErrorMessage('Validation failed for the field [VacancyCreate].');
    }

    public function test_publish_and_unpublish_vacancy(): void
    {
        $user = $this->makeAdminUser();
        $vacancy = Vacancy::factory()->create();

        $publish = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                VacancyPublish(id: $id) { id is_published published_at }
            }
        ', ['id' => $vacancy->id]);

        $publish->assertJson(['data' => ['VacancyPublish' => ['is_published' => true]]]);
        $this->assertNotNull($publish->json('data.VacancyPublish.published_at'));

        $publishedAt = $vacancy->fresh()->published_at;

        $unpublish = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                VacancyUnpublish(id: $id) { id is_published published_at }
            }
        ', ['id' => $vacancy->id]);

        $unpublish->assertJson(['data' => ['VacancyUnpublish' => ['is_published' => false]]]);
        // Дата последней публикации сохраняется после снятия с публикации
        $this->assertEquals(
            $publishedAt->toDateTimeString(),
            $vacancy->fresh()->published_at->toDateTimeString()
        );
    }

    public function test_published_at_is_not_overwritten_on_plain_update(): void
    {
        $user = $this->makeAdminUser();
        $vacancy = Vacancy::factory()->published()->create([
            'published_at' => now()->subDays(3),
        ]);
        $publishedAt = $vacancy->published_at;

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String, $is_published: Boolean) {
                VacancyUpdate(id: $id, title: $title, is_published: $is_published) { id }
            }
        ', [
            'id' => $vacancy->id,
            'title' => 'Новое название',
            'is_published' => true,
        ]);

        $this->assertEquals(
            $publishedAt->toDateTimeString(),
            $vacancy->fresh()->published_at->toDateTimeString()
        );
    }

    public function test_delete_is_allowed_for_published_vacancy(): void
    {
        $user = $this->makeAdminUser();
        $vacancy = Vacancy::factory()->published()->create();

        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                VacancyDelete(id: $id) { code }
            }
        ', ['id' => $vacancy->id]);

        $response->assertJson(['data' => ['VacancyDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('vacancies', ['id' => $vacancy->id]);
    }

    public function test_change_position_reorders_list(): void
    {
        $user = $this->makeAdminUser();

        $first = Vacancy::factory()->create(['title' => 'Первая', 'position' => 1]);
        $second = Vacancy::factory()->create(['title' => 'Вторая', 'position' => 2]);
        $third = Vacancy::factory()->create(['title' => 'Третья', 'position' => 3]);

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $position: Int!) {
                VacancyChangePosition(id: $id, position: $position) { id position }
            }
        ', ['id' => $third->id, 'position' => 1]);

        $this->assertSame(1, $third->fresh()->position);
        $this->assertSame(2, $first->fresh()->position);
        $this->assertSame(3, $second->fresh()->position);
    }

    public function test_list_filters_and_search(): void
    {
        $user = $this->makeAdminUser();

        Vacancy::factory()->published()->create([
            'title' => 'Кассир',
            'city' => 'Казань',
            'work_format' => Vacancy::WORK_FORMAT_OFFICE,
            'employment_type' => Vacancy::EMPLOYMENT_TYPE_FULL,
        ]);
        Vacancy::factory()->create([
            'title' => 'Курьер',
            'city' => 'Москва',
            'work_format' => Vacancy::WORK_FORMAT_FIELD,
            'employment_type' => Vacancy::EMPLOYMENT_TYPE_PART,
        ]);

        $published = $this->actingAs($user)->graphQL('
            query { admin_vacancies(is_published: true) { title } }
        ');
        $this->assertCount(1, $published->json('data.admin_vacancies'));
        $this->assertSame('Кассир', $published->json('data.admin_vacancies.0.title'));

        // Поиск ищет и по названию, и по городу
        $byCity = $this->actingAs($user)->graphQL('
            query { admin_vacancies(search: "Москва") { title } }
        ');
        $this->assertCount(1, $byCity->json('data.admin_vacancies'));
        $this->assertSame('Курьер', $byCity->json('data.admin_vacancies.0.title'));

        $byFormat = $this->actingAs($user)->graphQL('
            query { admin_vacancies(work_format: FIELD) { title } }
        ');
        $this->assertCount(1, $byFormat->json('data.admin_vacancies'));

        $byEmployment = $this->actingAs($user)->graphQL('
            query { admin_vacancies(employment_type: FULL) { title } }
        ');
        $this->assertCount(1, $byEmployment->json('data.admin_vacancies'));

        $cities = $this->actingAs($user)->graphQL('query { admin_vacancy_cities }');
        $this->assertSame(['Казань', 'Москва'], $cities->json('data.admin_vacancy_cities'));
    }
}
