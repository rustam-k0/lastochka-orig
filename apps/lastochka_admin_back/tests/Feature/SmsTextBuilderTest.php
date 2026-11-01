<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\Sms\SmsTextBuilder;
use App\Services\SmsAero;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Операторы блокируют SMS с кодом или паролем, если в тексте нет названия
 * сервиса. Название берём из настройки `sms_service_name`, чтобы на каждом
 * проекте подставлялся свой бренд.
 */
class SmsTextBuilderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_password_text_contains_service_name_from_setting(): void
    {
        AdminSetting::setValue(SmsTextBuilder::SERVICE_NAME_KEY, 'Портал Турбо');

        $this->assertSame(
            'Пароль 4Kd82a для входа в Портал Турбо. Никому не сообщайте.',
            SmsTextBuilder::password('4Kd82a')
        );
    }

    public function test_code_text_contains_service_name_from_setting(): void
    {
        AdminSetting::setValue(SmsTextBuilder::SERVICE_NAME_KEY, 'Портал Турбо');

        $this->assertSame(
            'Код 82342 для входа в Портал Турбо. Никому не сообщайте.',
            SmsTextBuilder::confirmationCode('82342')
        );
    }

    public function test_falls_back_to_admin_title(): void
    {
        AdminSetting::where('key', SmsTextBuilder::SERVICE_NAME_KEY)->delete();
        AdminSetting::setValue(SmsTextBuilder::FALLBACK_KEY, 'Portal');

        $this->assertSame('Portal', SmsTextBuilder::serviceName());
    }

    public function test_falls_back_to_config_when_no_settings(): void
    {
        AdminSetting::where('key', SmsTextBuilder::SERVICE_NAME_KEY)->delete();
        AdminSetting::where('key', SmsTextBuilder::FALLBACK_KEY)->delete();
        config(['services.sms.service_name' => 'Портал Турбо']);

        $this->assertSame('Портал Турбо', SmsTextBuilder::serviceName());
    }

    public function test_smsaero_sends_text_with_configured_credentials(): void
    {
        Http::fake([
            'gate.smsaero.ru/*' => Http::response(['success' => true], 200),
        ]);

        (new SmsAero([
            'login' => 'user@example.com',
            'api_key' => 'secret-key',
            'sign' => 'PortalTurbo',
        ]))->send('Пароль 4Kd82a для входа в Портал Турбо. Никому не сообщайте.', '79001234567');

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return str_starts_with($request->url(), 'https://gate.smsaero.ru/v2/sms/send')
                && $query['number'] === '79001234567'
                && $query['sign'] === 'PortalTurbo'
                && $query['text'] === 'Пароль 4Kd82a для входа в Портал Турбо. Никому не сообщайте.'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_smsaero_does_not_send_without_credentials(): void
    {
        Http::fake();

        (new SmsAero([
            'login' => '',
            'api_key' => '',
        ]))->send('Тест', '79001234567');

        Http::assertNothingSent();
    }
}
