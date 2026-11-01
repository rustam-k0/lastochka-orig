<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntegrationConfig;
use App\Services\OneCConfigService;
use Illuminate\Console\Command;

class SyncOneCUserCredentialsCommand extends Command
{
    protected $signature = '1c:sync-user-credentials';

    protected $description = 'Синхронизировать users-таблицу с credentials из integration_configs (для Basic Auth mavsan)';

    public function handle(): int
    {
        $configs = IntegrationConfig::ofType('1c')->active()->get();

        if ($configs->isEmpty()) {
            $this->warn('Не найдено активных integration_configs с integration_type=1c');
            return self::SUCCESS;
        }

        $synced = 0;
        $skipped = 0;

        foreach ($configs as $config) {
            $credentials = $config->credentials;
            $login = is_array($credentials) ? ($credentials['login'] ?? null) : null;
            $password = is_array($credentials) ? ($credentials['password'] ?? null) : null;

            if (empty($login) || empty($password)) {
                $this->warn("Пропуск shop_code={$config->shop_code}: пустые login/password");
                $skipped++;
                continue;
            }

            OneCConfigService::syncUserFromConfig($config);
            $this->info("Синхронизирован: shop_code={$config->shop_code}, login={$login}");
            $synced++;
        }

        $this->info("Готово. Синхронизировано: {$synced}, пропущено: {$skipped}");

        return self::SUCCESS;
    }
}
