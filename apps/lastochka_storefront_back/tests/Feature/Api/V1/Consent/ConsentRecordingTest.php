<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Consent;

use App\Models\User;
use App\Models\UserConsent;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ConsentRecordingTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Авторизация ====================

    public function test_phone_confirm_records_three_document_consents(): void
    {
        $phone = '+79000000000';
        config(['services.auth.test_phones' => [$phone => '847291']]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);
        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '847291',
        ], ['X-Forwarded-For' => '203.0.113.7'])->assertOk();

        $user = User::where('phone', $phone)->first();

        foreach (['privacy_policy', 'personal_data', 'public_offer'] as $document) {
            $this->assertDatabaseHas('user_consents', [
                'user_id' => $user->id,
                'document_type' => $document,
                'context' => 'auth',
                'accepted' => true,
                'ip' => '203.0.113.7',
            ]);
        }

        // Без adultConfirmed согласие 18+ не пишется
        $this->assertDatabaseMissing('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'adult',
        ]);
    }

    public function test_phone_confirm_with_adult_flag_records_adult_consent(): void
    {
        $phone = '+79000000000';
        config(['services.auth.test_phones' => [$phone => '847291']]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);
        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '847291',
            'adultConfirmed' => true,
        ])->assertOk();

        $user = User::where('phone', $phone)->first();

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'adult',
            'context' => 'auth',
            'accepted' => true,
        ]);
    }

    public function test_phone_confirm_records_adult_refusal(): void
    {
        $phone = '+79000000000';
        config(['services.auth.test_phones' => [$phone => '847291']]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);
        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '847291',
            'adultConfirmed' => false,
        ])->assertOk();

        $user = User::where('phone', $phone)->first();

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'adult',
            'context' => 'auth',
            'accepted' => false,
        ]);
    }

    // ==================== Ручка согласия 18+ ====================

    public function test_adult_confirm_endpoint_records_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/adult-confirm', ['confirmed' => true])->assertOk();
        $this->actingAs($user)->postJson('/api/v1/auth/adult-confirm', ['confirmed' => false])->assertOk();

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'adult',
            'context' => 'adult_confirm',
            'accepted' => true,
        ]);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'adult',
            'context' => 'adult_confirm',
            'accepted' => false,
        ]);
        // Append-only: два вызова = две записи
        $this->assertSame(2, UserConsent::where('user_id', $user->id)->count());
    }

    // ==================== Создание заказа ====================

    public function test_order_creation_records_personal_data_and_offer_consents(): void
    {
        $user = \App\Models\User::factory()->create();
        $store = \App\Models\Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = \App\Models\Category::factory()->forStore($store)->create();
        $product = \App\Models\Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $address = \App\Models\Address::factory()->active()->for($user)->create();
        $cart = \App\Models\Cart::factory()->for($user)->create();
        \App\Models\CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/orders', [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]);

        $response->assertCreated();
        $orderId = $response->json('orderId');

        foreach (['personal_data', 'public_offer'] as $document) {
            $consent = UserConsent::query()
                ->where('user_id', $user->id)
                ->where('document_type', $document)
                ->where('context', 'order')
                ->first();

            $this->assertNotNull($consent, "Нет согласия {$document} при заказе");
            $this->assertSame($orderId, $consent->meta['order_id']);
        }
    }

    // ==================== Отклик на вакансию ====================

    public function test_vacancy_response_records_personal_data_consent_for_guest(): void
    {
        Mail::fake();
        config(['vacancies.responses_email' => 'hr@example.com']);
        $vacancy = Vacancy::factory()->create();

        $this->postJson('/api/v1/vacancies/'.$vacancy->id.'/responses', [
            'name' => 'Иван Иванов',
            'phone' => '+7 999 123-45-67',
            'email' => 'ivan@example.com',
            'resume' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
            'personalDataConsent' => true,
        ])->assertOk();

        $consent = UserConsent::query()
            ->where('document_type', 'personal_data')
            ->where('context', 'vacancy')
            ->first();

        $this->assertNotNull($consent);
        $this->assertNull($consent->user_id);
        $this->assertTrue($consent->accepted);
        $this->assertSame('Иван Иванов', $consent->meta['name']);
        $this->assertSame($vacancy->id, $consent->meta['vacancy_id']);
    }
}
