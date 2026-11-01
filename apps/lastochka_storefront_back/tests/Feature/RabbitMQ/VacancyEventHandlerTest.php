<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use App\Models\Vacancy;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VacancyEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('vacancies', config('rabbitmq-events.entities.vacancies'));
    }

    public function test_vacancy_created_event_creates_vacancy(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_1',
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'work_format' => 'office',
            'employment_type' => 'full',
            'work_schedule' => '3/3',
            'salary' => '80 000 ₽',
            'short_description' => 'Готовим блюда собственного производства',
            'description' => 'Полное описание вакансии',
            'responsibilities' => 'Приготовление блюд',
            'requirements' => 'Опыт от полугода',
            'conditions' => 'Медкнижка за счёт компании',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/123456',
            'position' => 2,
            'is_published' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('vacancies', [
            'source' => 'bagisto',
            'external_id' => 'vacancy_1',
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'work_format' => 'office',
            'employment_type' => 'full',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/123456',
            'position' => 2,
            'is_published' => true,
        ]);
    }

    public function test_vacancy_link_is_updated_and_can_be_cleared(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_7',
            'title' => 'Кассир',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/1',
            'event_timestamp' => now()->subMinutes(2)->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'vacancy_7',
            'title' => 'Кассир',
            'link_title' => 'Откликнуться на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/2',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($updated));

        $vacancy = Vacancy::where('external_id', 'vacancy_7')->firstOrFail();
        $this->assertSame('Откликнуться на hh.ru', $vacancy->link_title);
        $this->assertSame('https://hh.ru/vacancy/2', $vacancy->link_url);

        // Явный null в событии очищает ссылку
        $cleared = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'vacancy_7',
            'title' => 'Кассир',
            'link_title' => null,
            'link_url' => null,
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($cleared));

        $vacancy->refresh();
        $this->assertNull($vacancy->link_title);
        $this->assertNull($vacancy->link_url);
    }

    public function test_partial_update_keeps_vacancy_link(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_8',
            'title' => 'Пекарь',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/3',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        // Событие без ключей link_* не должно затирать локальные значения.
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'vacancy_8',
            'title' => 'Пекарь-кондитер',
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($updated));

        $vacancy = Vacancy::where('external_id', 'vacancy_8')->firstOrFail();
        $this->assertSame('Пекарь-кондитер', $vacancy->title);
        $this->assertSame('Смотреть на hh.ru', $vacancy->link_title);
        $this->assertSame('https://hh.ru/vacancy/3', $vacancy->link_url);
    }

    public function test_partial_update_does_not_wipe_description(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_2',
            'title' => 'Кассир',
            'description' => 'Полное описание',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        // Событие без ключа description не должно затирать локальное значение.
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'vacancy_2',
            'title' => 'Кассир-операционист',
            'event_timestamp' => now()->toIso8601String(),
        ]);
        unset($updated['description']);
        $this->assertEquals('ack', $this->consumer->processEvent($updated));

        $vacancy = Vacancy::where('external_id', 'vacancy_2')->firstOrFail();
        $this->assertSame('Кассир-операционист', $vacancy->title);
        $this->assertSame('Полное описание', $vacancy->description);
    }

    public function test_unknown_enum_values_become_null(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_3',
            'title' => 'Грузчик',
            'work_format' => 'unknown_format',
            'employment_type' => 'unknown_type',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $vacancy = Vacancy::where('external_id', 'vacancy_3')->firstOrFail();
        $this->assertNull($vacancy->work_format);
        $this->assertNull($vacancy->employment_type);
    }

    public function test_unpublish_event_hides_vacancy_from_storefront(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_4',
            'title' => 'Пекарь',
            'is_published' => true,
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        $unpublished = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'vacancy_4',
            'title' => 'Пекарь',
            'is_published' => false,
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($unpublished));

        $this->assertFalse(Vacancy::where('external_id', 'vacancy_4')->firstOrFail()->is_published);
        $this->getJson('/api/v1/vacancies')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleted_event_removes_vacancy_from_storefront(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_6',
            'title' => 'Продавец-кассир',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        $deleted = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'vacancy_6',
            'title' => 'Продавец-кассир',
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($deleted));

        $vacancy = Vacancy::withTrashed()->where('external_id', 'vacancy_6')->firstOrFail();
        $this->assertNotNull($vacancy->deleted_at);
        $this->getJson('/api/v1/vacancies')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/vacancies/'.$vacancy->id)->assertNotFound();
    }

    public function test_enum_cast_survives_round_trip(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'vacancy_5',
            'title' => 'Курьер',
            'work_format' => 'field',
            'employment_type' => 'part',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $vacancy = Vacancy::where('external_id', 'vacancy_5')->firstOrFail();
        $this->assertSame(VacancyWorkFormat::Field, $vacancy->work_format);
        $this->assertSame(VacancyEmploymentType::Part, $vacancy->employment_type);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'source' => 'bagisto',
            'id' => 'vacancy_1',
            'title' => 'Вакансия',
            'short_description' => 'Краткое описание',
            'description' => 'Полное описание',
            'is_published' => true,
        ], $overrides);
    }
}
