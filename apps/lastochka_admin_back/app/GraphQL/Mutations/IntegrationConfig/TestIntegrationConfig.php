<?php

namespace App\GraphQL\Mutations\IntegrationConfig;

use App\Integrations\IntegrationRegistry;
use App\Models\IntegrationConfig;

class TestIntegrationConfig
{
    public function __invoke($_, array $args): array
    {
        $type = $args['integration_type'];
        $driver = $args['driver'];
        $credentials = json_decode($args['credentials'], true) ?? [];
        $testParams = isset($args['test_params'])
            ? json_decode($args['test_params'], true)
            : null;
        $shopId = $args['shop_id'] ?? null;

        if ($shopId !== null) {
            $user = auth()->user();
            if (!$user->isGlobalScope()) {
                if (!$user->shops()->where('shop_id', $shopId)->exists()) {
                    throw new \GraphQL\Error\Error('Нет доступа к этому магазину');
                }
            }
        }

        $descriptor = IntegrationRegistry::get($type);
        if (!$descriptor) {
            return ['success' => false, 'message' => "Неизвестный тип интеграции: {$type}"];
        }

        // Подставить сохранённые credentials только для password-полей
        $driverDesc = $descriptor->getDriver($driver);
        $passwordFields = $driverDesc
            ? collect($driverDesc->credentialsSchema)->filter(fn($f) => in_array($f->type, ['password', 'file']))->pluck('field')->toArray()
            : [];

        $config = IntegrationConfig::where('integration_type', $type)
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            ->first();
        if ($config && $config->credentials) {
            $savedForDriver = $config->credentials[$driver] ?? [];
            foreach ($savedForDriver as $key => $value) {
                if (in_array($key, $passwordFields) && empty($credentials[$key])) {
                    $credentials[$key] = $value;
                }
            }
        }

        try {
            $tester = $descriptor->getTester($driver);
            $result = $tester->test($credentials, $testParams);

            // Обновить tested_at / test_error если конфиг уже существует
            $config = IntegrationConfig::where('integration_type', $type)
                ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
                ->first();
            if ($config) {
                $config->update([
                    'tested_at' => $result->success ? now() : $config->tested_at,
                    'test_error' => $result->success ? null : $result->message,
                ]);
            }

            return ['success' => $result->success, 'message' => $result->message];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }
}
