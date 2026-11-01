<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Models\StorefrontSetting;
use App\Services\PhoneServices\SmsAero;
use App\Services\Sms\SmsTextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Операторы блокируют SMS с кодом, если в тексте нет названия сервиса.
 * Текст обязан быть «Код NNNN для входа в <Сервис>. Никому не сообщайте.»
 */
class SmsTextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_text_contains_service_name_from_setting(): void
    {
        StorefrontSetting::create([
            'key' => SmsTextBuilder::SERVICE_NAME_KEY,
            'value' => 'Портал Турбо',
            'type' => 'string',
        ]);

        $this->assertSame(
            'Код 82342 для входа в Портал Турбо. Никому не сообщайте.',
            SmsTextBuilder::confirmationCode('82342')
        );
    }

    public function test_falls_back_to_admin_title_when_service_name_missing(): void
    {
        StorefrontSetting::create([
            'key' => SmsTextBuilder::FALLBACK_KEY,
            'value' => 'Portal',
            'type' => 'string',
        ]);

        $this->assertSame('Portal', SmsTextBuilder::serviceName());
        $this->assertStringContainsString('Portal', SmsTextBuilder::confirmationCode('1234'));
    }

    public function test_falls_back_to_config_when_no_settings(): void
    {
        config(['services.sms.service_name' => 'Портал Турбо']);

        $this->assertSame(
            'Код 111111 для входа в Портал Турбо. Никому не сообщайте.',
            SmsTextBuilder::confirmationCode('111111')
        );
    }

    public function test_text_without_service_name_still_has_code(): void
    {
        config(['services.sms.service_name' => null, 'app.name' => '']);

        $this->assertSame('Код 5555. Никому не сообщайте.', SmsTextBuilder::confirmationCode('5555'));
    }

    public function test_smsaero_sends_branded_text_with_configured_sign(): void
    {
        StorefrontSetting::create([
            'key' => SmsTextBuilder::SERVICE_NAME_KEY,
            'value' => 'Портал Турбо',
            'type' => 'string',
        ]);

        Http::fake([
            'gate.smsaero.ru/*' => Http::response(['success' => true], 200),
        ]);

        (new SmsAero([
            'login' => 'user@example.com',
            'api_key' => 'secret-key',
            'sign' => 'PortalTurbo',
        ]))->sendPhoneConfirmation('79001234567', '82342');

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return str_starts_with($request->url(), 'https://gate.smsaero.ru/v2/sms/send')
                && $query['number'] === '79001234567'
                && $query['sign'] === 'PortalTurbo'
                && $query['text'] === 'Код 82342 для входа в Портал Турбо. Никому не сообщайте.'
                && $request->hasHeader('Authorization');
        });
    }
}
