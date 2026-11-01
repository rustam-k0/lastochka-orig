<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\Gender;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Phone Login ====================

    public function test_phone_login_sends_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/phone-login', [
            'phone' => '+79991234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'expiresIn',
                'code', // В тестовом окружении возвращается код
            ]);

        $this->assertDatabaseHas('otp_codes', [
            'phone' => '+79991234567',
        ]);
    }

    public function test_phone_login_validates_phone_format(): void
    {
        $response = $this->postJson('/api/v1/auth/phone-login', [
            'phone' => '89991234567',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_phone_login_rate_limited(): void
    {
        $phone = '+79991234567';

        // Отправляем 5 запросов
        for ($i = 0; $i < 5; $i++) {
            OtpCode::factory()->create([
                'phone' => $phone,
                'created_at' => now(),
            ]);
        }

        $response = $this->postJson('/api/v1/auth/phone-login', [
            'phone' => $phone,
        ]);

        $response->assertStatus(429);
    }

    // ==================== Длина кода (MID-307) ====================
    //
    // Длина SMS-кода задаётся AUTH_SMS_CODE_LENGTH (config services.auth.sms_code_length),
    // клиенты (веб-витрина, мобилка) рисуют ячейки ввода по полю codeLength ответа.

    public function test_phone_login_sms_code_length_defaults_to_six(): void
    {
        $response = $this->postJson('/api/v1/auth/phone-login', ['phone' => '+79991234520']);

        $response->assertOk()->assertJsonPath('codeLength', 6);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('code'));
    }

    public function test_phone_login_uses_configured_sms_code_length(): void
    {
        config(['services.auth.sms_code_length' => 4]);
        $phone = '+79991234521';

        $response = $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);

        $response->assertOk()->assertJsonPath('codeLength', 4);
        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $this->postJson('/api/v1/auth/phone-confirm', ['phone' => $phone, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_phone_login_call_code_length_does_not_depend_on_sms_setting(): void
    {
        config(['services.auth.sms_code_length' => 6]);

        $response = $this->postJson('/api/v1/auth/phone-login', [
            'phone' => '+79991234522',
            'method' => 'call',
        ]);

        $response->assertOk()->assertJsonPath('codeLength', 4);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $response->json('code'));
    }

    public function test_phone_login_reports_length_of_static_test_phone_code(): void
    {
        // Фиксированный код тестового номера (ревью сторов) длиннее настройки:
        // клиент должен получить длину реального кода, иначе его не ввести.
        config([
            'services.auth.sms_code_length' => 4,
            'services.auth.test_phones' => ['+79000000000' => '847291'],
        ]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => '+79000000000'])
            ->assertOk()
            ->assertJsonPath('codeLength', 6);
    }

    public function test_change_phone_uses_configured_sms_code_length(): void
    {
        config(['services.auth.sms_code_length' => 4]);
        $user = User::factory()->create(['phone' => '+79991230003']);
        Sanctum::actingAs($user);
        $newPhone = '+79991234523';

        $response = $this->postJson('/api/v1/auth/change-phone', ['newPhone' => $newPhone]);

        $response->assertOk()->assertJsonPath('codeLength', 4);
        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);

        $this->postJson('/api/v1/auth/change-phone/confirm', ['newPhone' => $newPhone, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.phone', $newPhone);
    }

    public function test_phone_confirm_accepts_codes_from_four_to_six_digits(): void
    {
        foreach (['1234', '12345', '123456'] as $index => $code) {
            $phone = '+7999123453'.$index;
            OtpCode::factory()->withCode($code)->create(['phone' => $phone]);

            $this->postJson('/api/v1/auth/phone-confirm', ['phone' => $phone, 'code' => $code])
                ->assertOk();
        }
    }

    public function test_phone_confirm_rejects_code_of_unsupported_length(): void
    {
        foreach (['123', '1234567'] as $code) {
            $this->postJson('/api/v1/auth/phone-confirm', ['phone' => '+79991234540', 'code' => $code])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'Код подтверждения должен содержать от 4 до 6 цифр');
        }
    }

    // ==================== Test Phone (Apple) ====================

    public function test_phone_login_uses_static_code_for_test_phone(): void
    {
        $testPhone = '+79000000000';
        $testCode = '847291';

        config(['services.auth.test_phones' => [$testPhone => $testCode]]);

        $response = $this->postJson('/api/v1/auth/phone-login', [
            'phone' => $testPhone,
        ]);

        $response->assertOk();

        // Подтверждаем статическим кодом
        $confirmResponse = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $testPhone,
            'code' => $testCode,
        ]);

        $confirmResponse->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_phone_login_test_phone_rejects_wrong_code(): void
    {
        $testPhone = '+79000000000';
        $testCode = '847291';

        config(['services.auth.test_phones' => [$testPhone => $testCode]]);

        $this->postJson('/api/v1/auth/phone-login', [
            'phone' => $testPhone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $testPhone,
            'code' => '000000',
        ]);

        $response->assertUnprocessable();
    }

    // ==================== Phone Confirm ====================

    public function test_phone_confirm_creates_user(): void
    {
        $phone = '+79991234567';
        $code = '123456';

        OtpCode::factory()->withCode($code)->create([
            'phone' => $phone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id',
                    'phone',
                    'firstName',
                    'lastName',
                    'email',
                    'birthDate',
                    'gender',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => $phone,
        ]);
    }

    public function test_phone_confirm_returns_existing_user(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'first_name' => 'Иван',
        ]);

        $code = '123456';
        OtpCode::factory()->withCode($code)->create([
            'phone' => $user->phone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.firstName', 'Иван');
    }

    public function test_phone_confirm_invalid_code(): void
    {
        $phone = '+79991234567';

        OtpCode::factory()->withCode('123456')->create([
            'phone' => $phone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '000000',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Неверный код подтверждения.');
    }

    public function test_phone_confirm_expired_code(): void
    {
        $phone = '+79991234567';
        $code = '123456';

        OtpCode::factory()->withCode($code)->expired()->create([
            'phone' => $phone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Код подтверждения устарел или уже использован. Запросите новый код.');
    }

    public function test_phone_confirm_no_active_code_for_phone(): void
    {
        // Кода для номера вообще не запрашивали — тот же текст ошибки, что и для истёкшего
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => '+79991234567',
            'code' => '123456',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Код подтверждения устарел или уже использован. Запросите новый код.');
    }

    // ==================== Несколько активных кодов (ALD-122) ====================

    public function test_phone_confirm_accepts_oldest_of_three_active_codes(): void
    {
        $phone = '+79991234510';

        $codes = $this->requestOtpCodes($phone, 3);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[0], // самый первый (самый старый) запрошенный код
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_phone_confirm_accepts_middle_of_three_active_codes(): void
    {
        $phone = '+79991234511';

        $codes = $this->requestOtpCodes($phone, 3);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[1],
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_phone_confirm_accepts_newest_of_three_active_codes(): void
    {
        $phone = '+79991234512';

        $codes = $this->requestOtpCodes($phone, 3);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[2], // самый свежий запрошенный код
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_phone_confirm_successful_login_invalidates_sibling_codes(): void
    {
        $phone = '+79991234513';

        $codes = $this->requestOtpCodes($phone, 3);

        // Входим по самому свежему коду
        $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[2],
        ])->assertOk();

        // Оставшиеся два ещё не истёкших кода больше не должны принимать вход (replay)
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[0],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Код подтверждения устарел или уже использован. Запросите новый код.');
    }

    public function test_phone_login_keeps_only_last_three_active_codes(): void
    {
        $phone = '+79991234514';

        // Четыре запроса подряд — окно активных кодов ограничено тремя последними
        $codes = $this->requestOtpCodes($phone, 4);

        $this->assertCount(
            3,
            OtpCode::query()->forPhone($phone)->active()->get(),
        );

        // Самый первый (за пределами окна) код уже инвалидирован. Остальные три ещё
        // активны, поэтому ошибка — «неверный код», а не «устарел/использован»
        // (сервис не знает, что именно этот конкретный код был вытеснен из окна).
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[0],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Неверный код подтверждения.');

        // Второй по счёту код остаётся в окне последних трёх и принимается
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codes[1],
        ]);

        $response->assertOk();
    }

    public function test_phone_confirm_blocks_after_three_wrong_attempts(): void
    {
        $phone = '+79991234515';
        $code = '123456';

        OtpCode::factory()->withCode($code)->create([
            'phone' => $phone,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/auth/phone-confirm', [
                'phone' => $phone,
                'code' => '000000',
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'Неверный код подтверждения.');
        }

        // Счётчик попыток должен реально сохраниться в БД (не откатываться транзакцией)
        $this->assertDatabaseHas('otp_codes', [
            'phone' => $phone,
            'attempts' => 3,
        ]);

        // Дальше даже верный код отклоняется — лимит попыток исчерпан
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Превышено число попыток ввода кода. Запросите новый код.');
    }

    /**
     * Фиксирует сознательное поведение при multi-code сценарии (ALD-122 code review,
     * round 1): лимит MAX_ATTEMPTS=3 действует НА КОД, а не на номер. Неверный ввод
     * сжигает попытку у ВСЕХ активных кандидатов номера, но повторный запрос кода
     * заводит для него attempts=0 — поэтому у номера фактически появляется новый
     * бюджет попыток на новом коде, даже если старый уже исчерпал лимит.
     *
     * Сценарий (числа attempts проверяются напрямую в БД, не только код ответа):
     * 1) код A, 2 неверных ввода -> A.attempts = 2
     * 2) запрос кода B (окно 2 из 3 не обрезает A) -> A.attempts = 2, B.attempts = 0
     * 3) ещё 1 неверный ввод -> A.attempts = 3 (исчерпан, выбывает из кандидатов),
     *    B.attempts = 1 (получил попытку "за компанию", хотя ни разу не проверялся)
     * 4) вход по реальному коду B успешен, несмотря на то что A уже заблокирован
     *    и что у номера в сумме было 3 неверных ввода
     */
    public function test_phone_confirm_new_code_gets_fresh_attempt_budget_after_resend(): void
    {
        $phone = '+79991234516';

        // Шаг 1: код A, 2 неверных ввода
        $this->requestOtpCodes($phone, 1);
        $codeAId = OtpCode::query()->forPhone($phone)->value('id');

        $this->postJson('/api/v1/auth/phone-confirm', ['phone' => $phone, 'code' => '000000'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/phone-confirm', ['phone' => $phone, 'code' => '000000'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('otp_codes', ['id' => $codeAId, 'attempts' => 2, 'verified_at' => null]);

        // Шаг 2: запрос кода B — окно MAX_ACTIVE_CODES=3 не режет A (активных всего 2)
        $codeB = $this->requestOtpCodes($phone, 1)[0];
        $codeBId = OtpCode::query()->forPhone($phone)->where('id', '!=', $codeAId)->value('id');

        $this->assertDatabaseHas('otp_codes', ['id' => $codeAId, 'attempts' => 2, 'verified_at' => null]);
        $this->assertDatabaseHas('otp_codes', ['id' => $codeBId, 'attempts' => 0, 'verified_at' => null]);

        // Шаг 3: ещё один неверный ввод — попытку засчитывают ОБОИМ активным кандидатам
        $this->postJson('/api/v1/auth/phone-confirm', ['phone' => $phone, 'code' => '000000'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('otp_codes', ['id' => $codeAId, 'attempts' => 3]);
        $this->assertDatabaseHas('otp_codes', ['id' => $codeBId, 'attempts' => 1]);

        // Шаг 4: код A исчерпан и дальше отклонялся бы как OtpAttemptsExceeded, но
        // вход по свежему коду B (attempts=1 из 3) проходит успешно
        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $codeB,
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);
    }

    /**
     * Запрашивает N кодов подряд через реальный /auth/phone-login и возвращает
     * их plaintext-значения в порядке запроса (доступны в ответе т.к. в тестовом
     * окружении драйвер SMS = otp).
     *
     * @return list<string>
     */
    private function requestOtpCodes(string $phone, int $count): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $response = $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);
            $response->assertOk();
            $codes[] = $response->json('code');
        }

        return $codes;
    }

    /**
     * Запросить $count OTP-кодов на смену номера через авторизованную ручку
     * POST /api/v1/auth/change-phone — вызывающий тест обязан заранее
     * авторизоваться через Sanctum::actingAs().
     *
     * @return array<int, string>
     */
    private function requestChangePhoneOtpCodes(string $newPhone, int $count): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $response = $this->postJson('/api/v1/auth/change-phone', ['newPhone' => $newPhone]);
            $response->assertOk();
            $codes[] = $response->json('code');
        }

        return $codes;
    }

    public function test_phone_confirm_max_attempts(): void
    {
        $phone = '+79991234567';
        $code = '123456';

        OtpCode::factory()->withCode($code)->maxAttempts()->create([
            'phone' => $phone,
        ]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => $code,
        ]);

        $response->assertUnprocessable();
    }

    // ==================== Change Phone Confirm ====================
    //
    // POST /api/v1/auth/change-phone/confirm — контроллер не имеет собственной
    // 422-ветки, целиком полагается на рендер типизированных исключений
    // OtpService::verify() (InvalidOtpException и наследники). Дублируем сюда
    // ключевые сценарии phone-confirm применительно к смене номера: неверный
    // код и инвалидацию соседних активных кодов после успешной смены.

    public function test_change_phone_confirm_invalid_code_returns_422(): void
    {
        $user = User::factory()->create(['phone' => '+79991230000']);
        Sanctum::actingAs($user);

        $newPhone = '+79991234567';
        OtpCode::factory()->withCode('123456')->create([
            'phone' => $newPhone,
        ]);

        $response = $this->postJson('/api/v1/auth/change-phone/confirm', [
            'newPhone' => $newPhone,
            'code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Неверный код подтверждения.');

        // Предусловие: номер пользователя не должен был поменяться при отказе.
        $this->assertSame('+79991230000', $user->fresh()->phone);
    }

    public function test_change_phone_confirm_success_invalidates_sibling_active_codes(): void
    {
        $user = User::factory()->create(['phone' => '+79991230001']);
        Sanctum::actingAs($user);

        $newPhone = '+79991234568';
        $codes = $this->requestChangePhoneOtpCodes($newPhone, 3);

        // Подтверждаем смену номера самым свежим из трёх активных кодов.
        $response = $this->postJson('/api/v1/auth/change-phone/confirm', [
            'newPhone' => $newPhone,
            'code' => $codes[2],
        ]);

        $response->assertOk()->assertJsonPath('user.phone', $newPhone);
        $this->assertSame($newPhone, $user->fresh()->phone);

        // Соседние ещё не истёкшие коды того же номера должны быть инвалидированы
        // (OtpService::consume() гасит все активные коды номера, не только использованный).
        $this->assertSame(
            0,
            OtpCode::query()->forPhone($newPhone)->active()->count(),
        );

        // Попытка повторно подтвердить одним из погашенных соседних кодов — отказ.
        $anotherUser = User::factory()->create(['phone' => '+79991230002']);
        Sanctum::actingAs($anotherUser);

        $replay = $this->postJson('/api/v1/auth/change-phone/confirm', [
            'newPhone' => $newPhone,
            'code' => $codes[0],
        ]);

        $replay->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Код подтверждения устарел или уже использован. Запросите новый код.');
    }

    // ==================== Get Profile ====================

    public function test_get_profile(): void
    {
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'email' => 'ivan@example.com',
            'birth_date' => '1990-01-15',
            'gender' => Gender::Male,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/auth/profile');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.phone', '+79991234567')
            ->assertJsonPath('data.firstName', 'Иван')
            ->assertJsonPath('data.lastName', 'Иванов')
            ->assertJsonPath('data.email', 'ivan@example.com')
            ->assertJsonPath('data.gender', 'male');
    }

    public function test_get_profile_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/auth/profile');

        $response->assertUnauthorized();
    }

    // ==================== Update Profile ====================

    public function test_update_profile(): void
    {
        $user = User::factory()->empty()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'firstName' => 'Пётр',
            'lastName' => 'Петров',
            'email' => 'petr@example.com',
            'birthDate' => '1985-05-20',
            'gender' => 'male',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.firstName', 'Пётр')
            ->assertJsonPath('data.lastName', 'Петров')
            ->assertJsonPath('data.email', 'petr@example.com')
            ->assertJsonPath('data.gender', 'male');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Пётр',
            'last_name' => 'Петров',
            'email' => 'petr@example.com',
            'gender' => 'male',
        ]);
    }

    public function test_update_profile_validates_email_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'email' => 'taken@example.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_update_profile_allows_own_email(): void
    {
        $user = User::factory()->create(['email' => 'my@example.com']);

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'email' => 'my@example.com',
            'firstName' => 'Updated',
        ]);

        $response->assertOk();
    }

    public function test_update_profile_validates_birth_date_not_future(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'birthDate' => now()->addDay()->format('Y-m-d'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['birthDate']);
    }

    // ==================== Logout ====================

    public function test_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();

        // Токен должен быть удалён
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }
}
