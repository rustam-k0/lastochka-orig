<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IntegrationConfig;
use App\Services\OneCConfigService;

class IntegrationConfigObserver
{
    public function saved(IntegrationConfig $config): void
    {
        OneCConfigService::syncUserFromConfig($config);
    }
}
