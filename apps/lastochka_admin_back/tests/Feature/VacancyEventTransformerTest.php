<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Transformers\VacancyEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VacancyEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_to_event_contains_contract_fields(): void
    {
        $vacancy = Vacancy::factory()->published()->create([
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'position' => 3,
        ]);

        $event = (new VacancyEventTransformer())->toEvent($vacancy);

        $this->assertSame((string) $vacancy->id, $event['id']);
        $this->assertSame('Повар горячего цеха', $event['title']);
        $this->assertSame('Москва', $event['city']);
        $this->assertSame(Vacancy::WORK_FORMAT_OFFICE, $event['work_format']);
        $this->assertSame(Vacancy::EMPLOYMENT_TYPE_FULL, $event['employment_type']);
        $this->assertSame('3/3', $event['work_schedule']);
        $this->assertSame('80 000 ₽', $event['salary']);
        $this->assertArrayHasKey('short_description', $event);
        $this->assertArrayHasKey('description', $event);
        $this->assertArrayHasKey('responsibilities', $event);
        $this->assertArrayHasKey('requirements', $event);
        $this->assertArrayHasKey('conditions', $event);
        $this->assertSame('Смотреть на hh.ru', $event['link_title']);
        $this->assertSame('https://hh.ru/vacancy/123456', $event['link_url']);
        $this->assertSame(3, $event['position']);
        $this->assertTrue($event['is_published']);
        $this->assertNotNull($event['published_at']);
        $this->assertNotEmpty($event['event_id']);
        $this->assertArrayHasKey('source', $event);
    }

    public function test_to_event_keeps_null_position_and_published_at(): void
    {
        $vacancy = Vacancy::factory()->create([
            'position' => null,
            'link_title' => null,
            'link_url' => null,
        ]);

        $event = (new VacancyEventTransformer())->toEvent($vacancy);

        $this->assertNull($event['position']);
        $this->assertNull($event['link_title']);
        $this->assertNull($event['link_url']);
        $this->assertNull($event['published_at']);
        $this->assertFalse($event['is_published']);
    }

    public function test_vacancies_entity_registered_in_config(): void
    {
        $config = config('rabbitmq-events.entities.vacancies');

        $this->assertNotNull($config, 'Запись vacancies отсутствует в config/rabbitmq-events.php');
        $this->assertTrue($config['enabled']);
        $this->assertSame(\App\Models\Vacancy::class, $config['model']);
        $this->assertSame(['created', 'updated', 'deleted'], $config['events']);
        $this->assertSame('vacancy', $config['routing_key_prefix']);
    }
}
