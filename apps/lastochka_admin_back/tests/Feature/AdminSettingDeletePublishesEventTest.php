<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\AdminSetting\SaveAdminSetting;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Удаление файловой настройки (сброс баннера/логотипа) обязано публиковать
 * admin_setting.deleted: иначе storefront-проекция остаётся навсегда и
 * мобилка показывает удалённый баннер. Query-builder delete события
 * не триггерит — только Eloquent delete.
 */
class AdminSettingDeletePublishesEventTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // static-флаг пакета переживает пересоздание app между тестами —
        // без сброса до создания приложения observers регистрируются только
        // в первом тесте процесса и события не пишутся в outbox.
        $prop = new \ReflectionProperty(
            \Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider::class,
            'observersRegistered'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        parent::setUp();
    }

    public function test_file_setting_delete_publishes_deleted_event(): void
    {
        $setting = AdminSetting::setValue(
            'app_launch_banner',
            '/files/admin-settings/banner.png',
            'file'
        );

        (new SaveAdminSetting())(null, [
            'key' => 'app_launch_banner',
            'value' => null,
            'type' => 'file',
        ]);

        $this->assertDatabaseMissing('admin_settings', ['key' => 'app_launch_banner']);

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'admin_settings',
            'event_type' => 'deleted',
            'routing_key' => 'admin_setting.deleted',
            'entity_id' => (string) $setting->id,
        ]);
    }
}
