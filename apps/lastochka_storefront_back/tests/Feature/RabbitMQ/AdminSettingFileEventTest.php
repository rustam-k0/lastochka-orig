<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\StorefrontSetting;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Файловые настройки (app_launch_banner): value события — относительный путь
 * admin-сервера, снаружи не резолвится. Storefront скачивает файл себе по
 * file_url (как картинки баннеров) и хранит локальный путь; при deleted
 * удаляет и проекцию, и локальный файл.
 */
final class AdminSettingFileEventTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer(
            'admin_settings',
            config('rabbitmq-events.entities.admin_settings')
        );

        Storage::fake('public');
    }

    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => '10',
            'source' => 'admin',
            'key' => 'app_launch_banner',
            'value' => '/files/admin-settings/banner.png',
            'type' => 'file',
            'file_url' => 'https://admin.example.com/files/admin-settings/banner.png',
        ], $overrides);
    }

    public function test_created_file_event_downloads_image_to_local_storage(): void
    {
        Http::fake([
            'https://admin.example.com/*' => Http::response(
                'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $result = $this->consumer->processEvent($this->buildEventData());

        $this->assertSame('ack', $result);

        $setting = StorefrontSetting::where('key', 'app_launch_banner')->first();
        $this->assertNotNull($setting);
        $this->assertNotNull($setting->value);
        $this->assertStringStartsWith('admin-settings/', $setting->value);
        Storage::disk('public')->assertExists($setting->value);
    }

    public function test_updated_file_event_replaces_image(): void
    {
        Http::fake([
            'https://admin.example.com/*' => Http::response(
                'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $this->consumer->processEvent($this->buildEventData());
        $firstPath = StorefrontSetting::where('key', 'app_launch_banner')->first()->value;

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'value' => '/files/admin-settings/banner-v2.png',
            'file_url' => 'https://admin.example.com/files/admin-settings/banner-v2.png',
        ]));

        $this->assertSame('ack', $result);

        $setting = StorefrontSetting::where('key', 'app_launch_banner')->first();
        $this->assertNotNull($setting->value);
        $this->assertNotSame($firstPath, $setting->value);
        Storage::disk('public')->assertExists($setting->value);
    }

    public function test_download_failure_keeps_setting_without_image(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $result = $this->consumer->processEvent($this->buildEventData());

        $this->assertSame('ack', $result);

        $setting = StorefrontSetting::where('key', 'app_launch_banner')->first();
        $this->assertNotNull($setting);
        $this->assertNull($setting->value);
    }

    public function test_non_file_setting_unchanged(): void
    {
        $result = $this->consumer->processEvent($this->buildEventData([
            'key' => 'marketplace_mode',
            'value' => '1',
            'type' => 'string',
            'file_url' => null,
        ]));

        $this->assertSame('ack', $result);

        $setting = StorefrontSetting::where('key', 'marketplace_mode')->first();
        $this->assertSame('1', $setting->value);
    }

    public function test_deleted_event_removes_setting_and_local_file(): void
    {
        Http::fake([
            'https://admin.example.com/*' => Http::response(
                'fake-image-content',
                200,
                ['Content-Type' => 'image/png']
            ),
        ]);

        $this->consumer->processEvent($this->buildEventData());
        $localPath = StorefrontSetting::where('key', 'app_launch_banner')->first()->value;

        $result = $this->consumer->processEvent($this->buildEventData([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'deleted',
        ]));

        $this->assertSame('ack', $result);
        $this->assertDatabaseMissing('storefront_settings', ['key' => 'app_launch_banner']);
        Storage::disk('public')->assertMissing($localPath);
    }
}
