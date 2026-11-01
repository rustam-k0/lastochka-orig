<?php

declare(strict_types=1);

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var StorefrontSetting|null $row */
        $row = StorefrontSetting::query()
            ->where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->first();

        if ($row === null) {
            StorefrontSetting::create([
                'key' => ModuleConfigService::PROJECT_MODULES_KEY,
                'value' => json_encode(
                    [
                        ModuleConfigService::ADDRESS_OBJECTS_KEY => [
                            'type' => 'boolean',
                            'label' => 'Адрес-объект (контакты, лифт, пропуск, фото)',
                            'enabled' => false,
                        ],
                    ],
                    JSON_UNESCAPED_UNICODE
                ),
                'type' => 'json',
            ]);

            return;
        }

        $modules = json_decode((string) $row->value, true);

        if (! is_array($modules)) {
            $modules = [];
        }

        if (isset($modules[ModuleConfigService::ADDRESS_OBJECTS_KEY])) {
            return;
        }

        $modules[ModuleConfigService::ADDRESS_OBJECTS_KEY] = [
            'type' => 'boolean',
            'label' => 'Адрес-объект (контакты, лифт, пропуск, фото)',
            'enabled' => false,
        ];

        $row->update([
            'value' => json_encode($modules, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function down(): void
    {
        /** @var StorefrontSetting|null $row */
        $row = StorefrontSetting::query()
            ->where('key', ModuleConfigService::PROJECT_MODULES_KEY)
            ->first();

        if ($row === null) {
            return;
        }

        $modules = json_decode((string) $row->value, true);

        if (! is_array($modules) || ! isset($modules[ModuleConfigService::ADDRESS_OBJECTS_KEY])) {
            return;
        }

        unset($modules[ModuleConfigService::ADDRESS_OBJECTS_KEY]);

        $row->update([
            'value' => json_encode($modules, JSON_UNESCAPED_UNICODE),
        ]);
    }
};
