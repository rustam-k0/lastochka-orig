<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Console\Command;

/**
 * Экспортирует текущий состав shops_categories для каждого магазина
 * в формате {shop_code: [category_external_id, ...]}.
 *
 * Используется в паре с storefront `category-store:reconcile` для разовой
 * зачистки устаревших category_store pivot-записей (ALADDIN-9822).
 *
 * Пример:
 *   php artisan shop-categories:export --file=/tmp/valid-shop-categories.json
 */
class ExportShopCategoriesExternalIdsCommand extends Command
{
    protected $signature = 'shop-categories:export {--file= : Путь к выходному JSON-файлу. Если не указан — вывод в stdout}';

    protected $description = 'Экспортирует актуальные пары shop_code → [category_external_ids] из shops_categories';

    public function handle(): int
    {
        $payload = [];

        Shop::query()
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->orderBy('id')
            ->each(function (Shop $shop) use (&$payload) {
                $localIds = $shop->categories()->pluck('categories.id')->all();

                if (empty($localIds)) {
                    $payload[$shop->code] = [];
                    return;
                }

                $externalIds = EventSyncMetadata::query()
                    ->where('entity_type', 'categories')
                    ->whereIn('entity_id', $localIds)
                    ->pluck('external_id')
                    ->unique()
                    ->values()
                    ->all();

                $payload[$shop->code] = $externalIds;
            });

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $file = $this->option('file');

        if ($file) {
            file_put_contents($file, $json);
            $this->info("Exported " . count($payload) . " shop(s) to {$file}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
