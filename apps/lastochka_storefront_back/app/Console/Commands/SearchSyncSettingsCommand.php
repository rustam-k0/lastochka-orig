<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Contracts\SearchClientInterface;
use Illuminate\Console\Command;

final class SearchSyncSettingsCommand extends Command
{
    protected $signature = 'search:sync-settings';

    protected $description = 'Применить настройки индекса Meilisearch из config/meilisearch.php (searchable/filterable/sortable/rankingRules). Без переиндексации документов.';

    public function handle(SearchClientInterface $client): int
    {
        if (! $client->isHealthy()) {
            $this->error('Meilisearch недоступен. Настройки не применены.');

            return self::FAILURE;
        }

        try {
            $client->updateIndexSettings();
        } catch (\Throwable $e) {
            $this->error('Не удалось применить настройки индекса: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Настройки индекса применены.');

        return self::SUCCESS;
    }
}
