<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Feedback;

use App\Mail\FeedbackMail;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'message' => 'Не пришёл чек по заказу №123',
            'personalDataConsent' => true,
        ], $overrides);
    }

    public function test_feedback_queues_mail_to_configured_address(): void
    {
        Mail::fake();
        config(['feedback.email' => 'support@example.com']);

        $response = $this->postJson('/api/v1/feedback', $this->validPayload());

        $response->assertOk()
            ->assertJsonPath('message', 'Обращение успешно отправлено');

        // FeedbackMail реализует ShouldQueue — письмо должно уйти в очередь
        Mail::assertQueued(FeedbackMail::class, function (FeedbackMail $mail) {
            return $mail->hasTo('support@example.com')
                && $mail->data->name === 'Иван Иванов'
                && $mail->data->message === 'Не пришёл чек по заказу №123';
        });
    }

    public function test_feedback_works_without_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/feedback', $this->validPayload(['email' => null]));

        $response->assertOk();
        Mail::assertQueued(FeedbackMail::class);
    }

    public function test_feedback_works_without_phone(): void
    {
        Mail::fake();

        $payload = $this->validPayload();
        unset($payload['phone']);

        $response = $this->postJson('/api/v1/feedback', $payload);

        $response->assertOk();
        Mail::assertQueued(FeedbackMail::class, function (FeedbackMail $mail) {
            return $mail->data->phone === null;
        });
    }

    public function test_feedback_works_with_null_phone(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/feedback', $this->validPayload(['phone' => null]));

        $response->assertOk();
        Mail::assertQueued(FeedbackMail::class);
    }

    public function test_feedback_requires_fields(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/feedback', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'message', 'personalDataConsent'])
            ->assertJsonMissingValidationErrors(['phone']);

        Mail::assertNothingQueued();
    }

    public function test_feedback_requires_consent(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/feedback', $this->validPayload(['personalDataConsent' => false]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['personalDataConsent']);

        Mail::assertNothingQueued();
    }

    public function test_feedback_records_consent_for_guest(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/feedback', $this->validPayload())->assertOk();

        $consent = UserConsent::query()
            ->where('document_type', 'personal_data')
            ->where('context', 'feedback')
            ->first();

        $this->assertNotNull($consent);
        $this->assertNull($consent->user_id);
        $this->assertSame('Иван Иванов', $consent->meta['name']);
    }

    public function test_feedback_records_consent_with_user_id_for_authorized(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/feedback', $this->validPayload())->assertOk();

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'personal_data',
            'context' => 'feedback',
            'accepted' => true,
        ]);
    }

    public function test_feedback_is_not_stored_anywhere(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/feedback', $this->validPayload())->assertOk();

        // Обращение не сохраняется — таблицы для него не существует
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('feedback'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('feedbacks'));
    }
}
