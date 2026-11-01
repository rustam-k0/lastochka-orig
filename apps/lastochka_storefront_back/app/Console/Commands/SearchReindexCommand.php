<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;

final class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex
                            {--chunk=1000 : Размер чанка для обработки}
                            {--fresh : Пересоздать индекс с нуля}';

    protected $description = 'Переиндексировать все товары в Meilisearch';

    public function handle(SearchService $searchService): int
    {
        $chunkSize = (int) $this->option('chunk');
        $fresh = (bool) $this->option('fresh');

        if (! $searchService->isHealthy()) {
            $this->error('Meilisearch недоступен. Проверьте подключение.');

            return self::FAILURE;
        }

        $total = Product::query()->active()->count();

        if ($total === 0) {
            $this->warn('Нет активных товаров для индексации.');

            return self::SUCCESS;
        }

        $this->info("Начинаю индексацию {$total} товаров...");

        if ($fresh) {
            $this->info('Режим --fresh: индекс будет пересоздан.');
        }

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $indexed = 0;

        foreach ($searchService->reindexAll($chunkSize, $fresh) as $progress) {
            $indexed = $progress['indexed'];
            $progressBar->setProgress($indexed);
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Индексация завершена. Проиндексировано: {$indexed} товаров.");

        return self::SUCCESS;
    }
}
