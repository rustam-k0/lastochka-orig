<?php

namespace App\GraphQL\Mutations\IntegrationConfig;

use App\Integrations\IntegrationRegistry;
use App\Models\IntegrationConfig;
use Illuminate\Support\Facades\DB;

class SaveIntegrationConfig
{
    public function __invoke($_, array $args): array
    {
        $type = $args['integration_type'];
        $driver = $args['driver'];
        $credentials = json_decode($args['credentials'], true) ?? [];
        $shopId = $args['shop_id'] ?? null;

        if ($type === '1c' && $shopId === null) {
            throw new \InvalidArgumentException('shop_id обязателен для интеграции 1С');
        }

        if ($shopId !== null) {
            $user = auth()->user();
            if (!$user->isGlobalScope()) {
                if (!$user->shops()->where('shop_id', $shopId)->exists()) {
                    throw new \GraphQL\Error\Error('Нет доступа к этому магазину');
                }
            }
        }

        // Валидация типа и драйвера по реестру
        $descriptor = IntegrationRegistry::get($type);
        if (!$descriptor) {
            throw new \InvalidArgumentException("Неизвестный тип интеграции: {$type}");
        }

        $driverDesc = $descriptor->getDriver($driver);
        if (!$driverDesc) {
            throw new \InvalidArgumentException("Неизвестный драйвер: {$driver}");
        }

        // Загрузить существующий конфиг для merge credentials per driver
        $existing = IntegrationConfig::where('integration_type', $type)
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            ->first();
        $allCredentials = $existing->credentials ?? [];

        // Подставить сохранённые credentials только для password-полей (пустое = не менять)
        // Для text-полей пустое значение = пользователь очистил поле
        $savedForDriver = $allCredentials[$driver] ?? [];
        $passwordFields = collect($driverDesc->credentialsSchema)
            ->filter(fn($f) => in_array($f->type, ['password', 'file']))
            ->pluck('field')
            ->toArray();

        foreach ($savedForDriver as $key => $value) {
            if (in_array($key, $passwordFields) && empty($credentials[$key])) {
                $credentials[$key] = $value;
            }
        }

        // Валидация required полей
        foreach ($driverDesc->credentialsSchema as $field) {
            if ($field->required && empty($credentials[$field->field])) {
                throw new \InvalidArgumentException("Поле '{$field->label}' обязательно для заполнения");
            }
        }

        // Сохранить credentials группированно по драйверу
        $allCredentials[$driver] = $credentials;

        $config = DB::transaction(function () use ($type, $driver, $allCredentials, $args, $shopId) {
            $attributes = [
                'driver' => $driver,
                'credentials' => $allCredentials,
                'is_active' => $args['is_active'],
            ];

            if (array_key_exists('sync_categories_enabled', $args) && $args['sync_categories_enabled'] !== null) {
                $attributes['sync_categories_enabled'] = (bool) $args['sync_categories_enabled'];
            }

            if (array_key_exists('sync_images_enabled', $args) && $args['sync_images_enabled'] !== null) {
                $attributes['sync_images_enabled'] = (bool) $args['sync_images_enabled'];
            }

            if (array_key_exists('deactivate_missing_enabled', $args) && $args['deactivate_missing_enabled'] !== null) {
                $attributes['deactivate_missing_enabled'] = (bool) $args['deactivate_missing_enabled'];
            }

            return IntegrationConfig::updateOrCreate(
                ['integration_type' => $type, 'shop_id' => $shopId],
                $attributes,
            );
        });

        return [
            'id' => $config->id,
            'integration_type' => $config->integration_type,
            'driver' => $config->driver,
            'credentials' => json_encode($config->credentials ?? []),
            'is_active' => $config->is_active,
            'sync_categories_enabled' => $config->sync_categories_enabled,
            'sync_images_enabled' => $config->sync_images_enabled,
            'deactivate_missing_enabled' => (bool) $config->deactivate_missing_enabled,
            'tested_at' => $config->tested_at,
            'test_error' => $config->test_error,
            'shop_id' => $config->shop_id,
            'shop' => $config->shop,
        ];
    }
}
