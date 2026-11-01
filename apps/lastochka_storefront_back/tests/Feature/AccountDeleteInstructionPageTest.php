<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccountDeleteInstructionPageTest extends TestCase
{
    public function test_page_shows_app_name_identifier_and_developer(): void
    {
        config([
            'mobile_app.name' => 'Ласточка Джами',
            'mobile_app.identifier' => 'com.app.lastochka.djami',
            'mobile_app.developer' => 'Comrades Devs LLC',
        ]);

        $response = $this->get('/app/instruction/delete');

        $response->assertStatus(200);
        $response->assertSee('Ласточка Джами', false);
        $response->assertSee('com.app.lastochka.djami', false);
        $response->assertSee('Comrades Devs LLC', false);
        $response->assertSee('Удаление аккаунта в приложении «Ласточка Джами»', false);
    }

    public function test_page_hides_app_meta_when_not_configured(): void
    {
        config([
            'mobile_app.name' => '',
            'mobile_app.identifier' => '',
            'mobile_app.developer' => '',
        ]);

        $response = $this->get('/app/instruction/delete');

        $response->assertStatus(200);
        $response->assertDontSee('<div class="app-meta">', false);
        $response->assertDontSee('Разработчик', false);
        $response->assertSee('Инструкция по удалению аккаунта в мобильном приложении', false);
    }

    public function test_page_uses_configured_logo_and_accent_color(): void
    {
        config([
            'mobile_app.logo_url' => 'https://cdn.example.com/electrohub/app-logo.png',
            'mobile_app.accent_color' => '#FC874E',
        ]);

        $response = $this->get('/app/instruction/delete');

        $response->assertStatus(200);
        $response->assertSee('src="https://cdn.example.com/electrohub/app-logo.png"', false);
        $response->assertSee('href="https://cdn.example.com/electrohub/app-logo.png"', false);
        $response->assertDontSee('images/app-logo.png', false);
        $response->assertSee('--accent: #FC874E;', false);
        $response->assertDontSee('--accent: #E5241F;', false);
    }

    public function test_page_falls_back_to_default_logo_and_accent_color(): void
    {
        config([
            'mobile_app.logo_url' => '',
            'mobile_app.accent_color' => '',
        ]);

        $response = $this->get('/app/instruction/delete');

        $response->assertStatus(200);
        $response->assertSee('images/app-logo.png', false);
        $response->assertSee('--accent: #E5241F;', false);
    }

    public function test_page_ignores_invalid_accent_color(): void
    {
        config(['mobile_app.accent_color' => 'red; background: url(https://evil.example/x)']);

        $response = $this->get('/app/instruction/delete');

        $response->assertStatus(200);
        $response->assertSee('--accent: #E5241F;', false);
        $response->assertDontSee('evil.example', false);
    }
}
