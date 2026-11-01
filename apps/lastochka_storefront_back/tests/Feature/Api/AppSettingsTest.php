<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AppSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_banner_not_set(): void
    {
        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertExactJson(['data' => [
                'launchBanner' => null,
                'additionalInfo' => [],
                'contacts' => [
                    'phones' => [],
                    'emails' => [],
                    'socialNetworks' => [],
                    'appLinks' => ['appStore' => null, 'googlePlay' => null, 'ruStore' => null],
                ],
                'checkoutOptions' => [
                    ['code' => 'chill_before_delivery', 'label' => 'Охладить напитки!'],
                ],
                'checkoutWithoutSlots' => false,
                'priceTiers' => false,
            ]]);
    }

    public function test_returns_full_url_when_banner_set(): void
    {
        StorefrontSetting::create([
            'key' => 'app_launch_banner',
            'value' => 'admin-settings/banner.png',
            'type' => 'file',
        ]);

        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $path = $response->json('data.launchBanner.path');
        $this->assertStringStartsWith('http', $path);
        $this->assertStringEndsWith('/admin-settings/banner.png', $path);
    }

    public function test_endpoint_is_public(): void
    {
        // Без auth-заголовков — не 401/403
        $this->getJson('/api/v1/app-settings')->assertOk();
    }

    public function test_returns_additional_info_items_in_order(): void
    {
        StorefrontSetting::create([
            'key' => 'product_info_return_policy',
            'value' => 'Возврат в течение 14 дней.',
            'type' => 'string',
        ]);
        StorefrontSetting::create([
            'key' => 'product_info_defective_product',
            'value' => 'Свяжитесь с поддержкой в чате.',
            'type' => 'string',
        ]);
        StorefrontSetting::create([
            'key' => 'product_info_delivery_payment',
            'value' => 'Доставка курьером, оплата картой.',
            'type' => 'string',
        ]);

        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $this->assertSame([
            [
                'key' => 'deliveryAndPayment',
                'title' => 'Доставка и оплата',
                'text' => 'Доставка курьером, оплата картой.',
            ],
            [
                'key' => 'returnPolicy',
                'title' => 'О возврате',
                'text' => 'Возврат в течение 14 дней.',
            ],
            [
                'key' => 'defectiveProduct',
                'title' => 'Если привезли не тот товар или товар с браком',
                'text' => 'Свяжитесь с поддержкой в чате.',
            ],
        ], $response->json('data.additionalInfo'));
    }

    public function test_hides_empty_additional_info_items(): void
    {
        StorefrontSetting::create([
            'key' => 'product_info_delivery_payment',
            'value' => 'Доставка курьером.',
            'type' => 'string',
        ]);
        // Пробельное значение = пустое: пункт скрыт
        StorefrontSetting::create([
            'key' => 'product_info_return_policy',
            'value' => '   ',
            'type' => 'string',
        ]);
        // product_info_defective_product вообще не создаём

        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $this->assertSame([
            [
                'key' => 'deliveryAndPayment',
                'title' => 'Доставка и оплата',
                'text' => 'Доставка курьером.',
            ],
        ], $response->json('data.additionalInfo'));
    }

    // ==================== Contacts (MENDEV-15) ====================

    public function test_contacts_empty_defaults_when_settings_absent(): void
    {
        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $this->assertSame([
            'phones' => [],
            'emails' => [],
            'socialNetworks' => [],
            'appLinks' => [
                'appStore' => null,
                'googlePlay' => null,
                'ruStore' => null,
            ],
        ], $response->json('data.contacts'));
    }

    public function test_contacts_returns_phones_emails_socials_and_app_links(): void
    {
        StorefrontSetting::create([
            'key' => 'contact_phones',
            'value' => json_encode(['+7 800 100-20-30', '+7 495 111-22-33']),
            'type' => 'json',
        ]);
        StorefrontSetting::create([
            'key' => 'contact_emails',
            'value' => json_encode(['sales@razgulayka.ru']),
            'type' => 'json',
        ]);
        StorefrontSetting::create([
            'key' => 'social_networks',
            'value' => json_encode([
                ['name' => 'ВКонтакте', 'url' => 'https://vk.com/razgulayka', 'logo' => 'https://cdn.example.com/vk.png'],
                ['name' => 'Telegram', 'url' => 'https://t.me/razgulayka'],
                ['name' => 'Битая запись без url'],
            ]),
            'type' => 'json',
        ]);
        StorefrontSetting::create([
            'key' => 'app_links',
            'value' => json_encode([
                'app_store' => 'https://apps.apple.com/app/id123',
                'googlePlay' => 'https://play.google.com/store/apps/details?id=ru.razgulayka',
            ]),
            'type' => 'json',
        ]);

        $response = $this->getJson('/api/v1/app-settings')->assertOk();

        $contacts = $response->json('data.contacts');

        $this->assertSame(['+7 800 100-20-30', '+7 495 111-22-33'], $contacts['phones']);
        $this->assertSame(['sales@razgulayka.ru'], $contacts['emails']);

        // Запись без url отброшена, logo опционален
        $this->assertCount(2, $contacts['socialNetworks']);
        $this->assertSame('ВКонтакте', $contacts['socialNetworks'][0]['name']);
        $this->assertSame('https://vk.com/razgulayka', $contacts['socialNetworks'][0]['url']);
        $this->assertSame('https://cdn.example.com/vk.png', $contacts['socialNetworks'][0]['logo']);
        $this->assertNull($contacts['socialNetworks'][1]['logo']);

        // Ключи принимаются и в snake_case, и в camelCase
        $this->assertSame('https://apps.apple.com/app/id123', $contacts['appLinks']['appStore']);
        $this->assertSame('https://play.google.com/store/apps/details?id=ru.razgulayka', $contacts['appLinks']['googlePlay']);
        $this->assertNull($contacts['appLinks']['ruStore']);
    }
}
