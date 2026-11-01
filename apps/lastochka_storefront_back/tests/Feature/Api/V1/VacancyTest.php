<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Mail\VacancyResponseMail;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class VacancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_vacancies_list(): void
    {
        // Arrange
        Vacancy::factory()->create(['title' => 'Вторая вакансия', 'position' => 2]);
        Vacancy::factory()->create(['title' => 'Первая вакансия', 'position' => 1]);

        // Act
        $response = $this->getJson('/api/v1/vacancies');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Первая вакансия')
            ->assertJsonPath('data.1.title', 'Вторая вакансия')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'title', 'city', 'workFormat', 'workFormatLabel', 'employmentType', 'employmentTypeLabel', 'salary', 'shortDescription'],
                ],
            ]);
    }

    public function test_list_excludes_unpublished(): void
    {
        // Arrange
        Vacancy::factory()->create();
        Vacancy::factory()->unpublished()->create();

        // Act
        $response = $this->getJson('/api/v1/vacancies');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_null_position_goes_last(): void
    {
        // Arrange
        Vacancy::factory()->create(['title' => 'Без позиции', 'position' => null]);
        Vacancy::factory()->create(['title' => 'С позицией', 'position' => 5]);

        // Act
        $response = $this->getJson('/api/v1/vacancies');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.title', 'С позицией')
            ->assertJsonPath('data.1.title', 'Без позиции');
    }

    public function test_show_returns_full_card(): void
    {
        // Arrange
        $vacancy = Vacancy::factory()->create();

        // Act
        $response = $this->getJson('/api/v1/vacancies/'.$vacancy->id);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.title', 'Повар горячего цеха')
            ->assertJsonPath('data.workFormat', 'office')
            ->assertJsonPath('data.workFormatLabel', 'Офис')
            ->assertJsonPath('data.employmentType', 'full')
            ->assertJsonPath('data.employmentTypeLabel', 'Полная занятость')
            ->assertJsonStructure([
                'data' => [
                    'id', 'title', 'city', 'workFormat', 'employmentType', 'workSchedule',
                    'salary', 'shortDescription', 'description', 'responsibilities',
                    'requirements', 'conditions', 'linkTitle', 'linkUrl',
                ],
            ])
            ->assertJsonPath('data.linkTitle', 'Смотреть на hh.ru')
            ->assertJsonPath('data.linkUrl', 'https://hh.ru/vacancy/123456');
    }

    public function test_show_returns_null_link_when_not_filled(): void
    {
        // Arrange
        $vacancy = Vacancy::factory()->create(['link_title' => null, 'link_url' => null]);

        // Act
        $response = $this->getJson('/api/v1/vacancies/'.$vacancy->id);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.linkTitle', null)
            ->assertJsonPath('data.linkUrl', null);
    }

    public function test_show_unpublished_returns_404(): void
    {
        // Arrange
        $vacancy = Vacancy::factory()->unpublished()->create();

        // Act & Assert
        $this->getJson('/api/v1/vacancies/'.$vacancy->id)->assertNotFound();
    }

    public function test_respond_sends_mail_with_resume(): void
    {
        // Arrange
        Mail::fake();
        config(['vacancies.responses_email' => 'sales@razgulayka.ru']);
        $vacancy = Vacancy::factory()->create();

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'comment' => 'Работал поваром три года',
            'resume' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'personalDataConsent' => true,
            'source' => 'mobile',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('message', 'Отклик успешно отправлен');

        Mail::assertSent(VacancyResponseMail::class, function (VacancyResponseMail $mail) use ($vacancy) {
            return $mail->hasTo('sales@razgulayka.ru')
                && $mail->vacancy->is($vacancy)
                && $mail->data->name === 'Иван Иванов'
                && $mail->data->email === 'ivan@example.com'
                && $mail->data->source === 'mobile';
        });

        // Отклик нигде не сохраняется — в базе появиться нечему
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('vacancy_responses'));
    }

    public function test_respond_requires_resume_and_consent(): void
    {
        // Arrange
        Mail::fake();
        $vacancy = Vacancy::factory()->create();

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
        ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resume', 'personalDataConsent']);
        Mail::assertNothingSent();
    }

    public function test_respond_rejects_wrong_file_format(): void
    {
        // Arrange
        Mail::fake();
        $vacancy = Vacancy::factory()->create();

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'resume' => UploadedFile::fake()->create('resume.exe', 100, 'application/octet-stream'),
            'personalDataConsent' => true,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['resume']);
        Mail::assertNothingSent();
    }

    public function test_respond_rejects_oversized_file(): void
    {
        // Arrange
        Mail::fake();
        config(['vacancies.resume.max_size_kb' => 1024]);
        $vacancy = Vacancy::factory()->create();

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'resume' => UploadedFile::fake()->create('resume.pdf', 2048, 'application/pdf'),
            'personalDataConsent' => true,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['resume']);
        Mail::assertNothingSent();
    }

    public function test_respond_to_unpublished_vacancy_returns_404(): void
    {
        // Arrange
        Mail::fake();
        $vacancy = Vacancy::factory()->unpublished()->create();

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'resume' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'personalDataConsent' => true,
        ]);

        // Assert
        $response->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_respond_returns_error_when_mail_fails(): void
    {
        // Arrange
        $vacancy = Vacancy::factory()->create();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP недоступен'));

        // Act
        $response = $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'resume' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'personalDataConsent' => true,
        ]);

        // Assert: отклик не считается отправленным, пока письмо не ушло
        $response->assertStatus(502)
            ->assertJsonPath('message', 'Не удалось отправить отклик. Попробуйте ещё раз позже.');
    }
}
