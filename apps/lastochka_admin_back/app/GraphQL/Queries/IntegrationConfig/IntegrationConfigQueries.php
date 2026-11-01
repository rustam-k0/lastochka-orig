<?php

namespace App\GraphQL\Queries\IntegrationConfig;

use App\Integrations\IntegrationRegistry;
use App\Models\IntegrationConfig;

class IntegrationConfigQueries
{
    public function configs($_, array $args): array
    {
        return IntegrationConfig::forCurrentUser()
            ->when(isset($args['shop_id']), fn($q) => $q->where('shop_id', $args['shop_id']))
            ->get()
            ->map(fn(IntegrationConfig $c) => $this->maskCredentials($c))
            ->toArray();
    }

    public function config($_, array $args): ?array
    {
        $config = IntegrationConfig::forCurrentUser()
            ->where('integration_type', $args['type'])
            ->when(isset($args['shop_id']), fn($q) => $q->where('shop_id', $args['shop_id']))
            ->first();

        return $config ? $this->maskCredentials($config) : null;
    }

    public function registry($_, array $args): array
    {
        return collect(IntegrationRegistry::all())
            ->map(fn($desc) => $desc->toArray())
            ->values()
            ->toArray();
    }

    /**
     * Маскировка password-полей в credentials
     */
    private function maskCredentials(IntegrationConfig $config): array
    {
        $descriptor = IntegrationRegistry::get($config->integration_type);
        $allCredentials = $config->credentials ?? [];

        // Маскировать password-поля для каждого драйвера
        $maskedAll = [];
        if ($descriptor) {
            foreach ($descriptor->drivers() as $driverDesc) {
                $driverCreds = $allCredentials[$driverDesc->name] ?? [];
                foreach ($driverDesc->credentialsSchema as $field) {
                    if (in_array($field->type, ['password', 'file']) && isset($driverCreds[$field->field])) {
                        $val = $driverCreds[$field->field];
                        $driverCreds[$field->field] = mb_strlen($val) > 6
                            ? mb_substr($val, 0, 3) . '***' . mb_substr($val, -3)
                            : '***';
                    }
                }
                if (!empty($driverCreds)) {
                    $maskedAll[$driverDesc->name] = $driverCreds;
                }
            }
        }

        return [
            'id' => $config->id,
            'integration_type' => $config->integration_type,
            'driver' => $config->driver,
            'credentials' => json_encode($maskedAll),
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
