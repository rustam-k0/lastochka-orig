<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Payment;

use App\Support\Payment\ReturnUrl;
use Tests\TestCase;

/**
 * Адрес возврата после оплаты приходит от клиента, поэтому проверка по белому
 * списку — граница безопасности: без неё чужой домен в `returnUrl` уводил бы
 * пользователя на фишинговую страницу сразу после подтверждения платежа.
 */
final class ReturnUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.ru',
            'services.yookassa.return_url' => 'https://store.example.ru/payment/success',
            'services.yookassa.allowed_return_hosts' => 'm.example.ru,*.partner.ru',
            'services.yookassa.allowed_return_schemes' => 'mendevapp',
        ]);
    }

    public function test_relative_path_is_glued_to_storefront_host(): void
    {
        $this->assertSame(
            'https://store.example.ru/payment/success?orderId=1',
            ReturnUrl::normalize('/payment/success?orderId=1'),
        );
    }

    public function test_absolute_url_on_default_host_is_allowed(): void
    {
        $url = 'https://store.example.ru/orders/125';

        $this->assertSame($url, ReturnUrl::normalize($url));
    }

    public function test_host_from_white_list_is_allowed(): void
    {
        $url = 'https://m.example.ru/orders/125';

        $this->assertSame($url, ReturnUrl::normalize($url));
    }

    public function test_wildcard_matches_domain_and_its_subdomains(): void
    {
        $this->assertNotNull(ReturnUrl::normalize('https://partner.ru/pay'));
        $this->assertNotNull(ReturnUrl::normalize('https://shop.partner.ru/pay'));
        $this->assertNotNull(ReturnUrl::normalize('https://a.b.partner.ru/pay'));
    }

    public function test_app_url_host_is_always_allowed(): void
    {
        $this->assertNotNull(ReturnUrl::normalize('https://api.example.ru/done'));
    }

    public function test_foreign_host_is_rejected(): void
    {
        $this->assertNull(ReturnUrl::normalize('https://evil.tld/payment/success'));
        $this->assertFalse(ReturnUrl::isAllowed('https://evil.tld/payment/success'));
    }

    public function test_host_that_only_ends_with_allowed_domain_is_rejected(): void
    {
        // «evilpartner.ru» не является поддоменом «partner.ru».
        $this->assertNull(ReturnUrl::normalize('https://evilpartner.ru/pay'));
    }

    public function test_protocol_relative_url_is_rejected(): void
    {
        $this->assertNull(ReturnUrl::normalize('//evil.tld/payment/success'));
    }

    public function test_javascript_and_data_schemes_are_rejected(): void
    {
        $this->assertNull(ReturnUrl::normalize('javascript:alert(1)'));
        $this->assertNull(ReturnUrl::normalize('data:text/html,<script>alert(1)</script>'));
        $this->assertNull(ReturnUrl::normalize('file:///etc/passwd'));
    }

    public function test_forbidden_scheme_stays_forbidden_even_if_white_listed(): void
    {
        config(['services.yookassa.allowed_return_schemes' => 'javascript']);

        $this->assertNull(ReturnUrl::normalize('javascript:alert(1)'));
        $this->assertNotContains('javascript', ReturnUrl::allowedSchemes());
    }

    public function test_scheme_outside_white_list_is_rejected(): void
    {
        // Схема http не разрешена: и дефолтный адрес, и APP_URL — https.
        $this->assertNull(ReturnUrl::normalize('http://store.example.ru/payment/success'));
        $this->assertNull(ReturnUrl::normalize('otherapp://orders/1'));
    }

    public function test_deeplink_scheme_from_white_list_is_allowed(): void
    {
        $this->assertSame('mendevapp://orders/125', ReturnUrl::normalize('mendevapp://orders/125'));
    }

    public function test_scheme_of_app_url_is_allowed_for_local_setup(): void
    {
        config([
            'app.url' => 'http://localhost:8034',
            'services.yookassa.return_url' => 'http://localhost:3000/payment/success',
        ]);

        $this->assertNotNull(ReturnUrl::normalize('http://localhost:3000/payment/success'));
    }

    public function test_credentials_in_url_are_rejected(): void
    {
        $this->assertNull(ReturnUrl::normalize('https://store.example.ru@evil.tld/pay'));
    }

    public function test_control_characters_are_rejected(): void
    {
        $this->assertNull(ReturnUrl::normalize("https://store.example.ru/pay\r\nSet-Cookie: a=1"));
    }

    public function test_too_long_url_is_rejected(): void
    {
        $url = 'https://store.example.ru/pay?x='.str_repeat('a', ReturnUrl::MAX_LENGTH);

        $this->assertNull(ReturnUrl::normalize($url));
    }

    public function test_empty_value_means_not_passed(): void
    {
        $this->assertTrue(ReturnUrl::isAllowed(null));
        $this->assertTrue(ReturnUrl::isAllowed(''));
        $this->assertTrue(ReturnUrl::isAllowed('   '));
        $this->assertNull(ReturnUrl::normalize(''));
    }

    public function test_resolve_falls_back_to_configured_default(): void
    {
        $this->assertSame('https://store.example.ru/payment/success', ReturnUrl::resolve(null));
        $this->assertSame('https://store.example.ru/payment/success', ReturnUrl::resolve('https://evil.tld/pay'));
    }

    public function test_relative_default_is_glued_to_app_url(): void
    {
        config(['services.yookassa.return_url' => '/']);

        $this->assertSame('https://api.example.ru/', ReturnUrl::resolve(null));

        config(['services.yookassa.return_url' => '/payment/done']);

        $this->assertSame('https://api.example.ru/payment/done', ReturnUrl::resolve(null));
    }
}
