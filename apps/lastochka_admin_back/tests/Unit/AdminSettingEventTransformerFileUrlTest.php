<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Transformers\AdminSettingEventTransformer;
use Tests\TestCase;

/**
 * Файловые настройки (логотип, баннер приложения) хранят value как
 * относительный путь admin-сервера (/files/admin-settings/...). Снаружи он
 * не резолвится, поэтому событие обязано нести абсолютный file_url,
 * по которому storefront скачает файл себе.
 */
class AdminSettingEventTransformerFileUrlTest extends TestCase
{
    public function test_file_setting_event_contains_absolute_file_url(): void
    {
        config(['app.url' => 'https://admin.example.com']);

        $setting = new AdminSetting([
            'key' => 'app_launch_banner',
            'value' => '/files/admin-settings/banner.png',
            'type' => 'file',
        ]);

        $event = (new AdminSettingEventTransformer())->toEvent($setting);

        $this->assertSame(
            'https://admin.example.com/files/admin-settings/banner.png',
            $event['file_url']
        );
    }

    public function test_absolute_value_passed_as_is(): void
    {
        $setting = new AdminSetting([
            'key' => 'app_launch_banner',
            'value' => 'https://cdn.example.com/banner.png',
            'type' => 'file',
        ]);

        $event = (new AdminSettingEventTransformer())->toEvent($setting);

        $this->assertSame('https://cdn.example.com/banner.png', $event['file_url']);
    }

    public function test_string_setting_event_has_no_file_url(): void
    {
        $setting = new AdminSetting([
            'key' => 'admin_title',
            'value' => 'Ласточка',
            'type' => 'string',
        ]);

        $event = (new AdminSettingEventTransformer())->toEvent($setting);

        $this->assertArrayNotHasKey('file_url', $event);
    }
}
