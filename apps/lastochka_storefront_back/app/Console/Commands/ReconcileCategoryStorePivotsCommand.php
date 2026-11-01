<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Store;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Console\Command;

/**
 * Приводит pivot category_store в соответствие со снапшотом из admin_back
 * (ALADDIN-9822). На входе — JSON {shop_code: [category_external_ids]},
 * сгенерированный командой admin_back `shop-categories:export`.
 *
 * Для каждого Store удаляем pivot-записи, у которых external_id категории
 * НЕ входит в "правильный" список для этого shop_code. Pivot-записи для
 * shop_code, не упомянутых во входном файле, не трогаем.
 *
 * Пример:
 *   php artisan category-store:reconcile --file=/tmp/valid-shop-categories.json
 *   php artisan category-store:reconcile --file=/tmp/valid.json --dry-run
 */
final class ReconcileCategoryStorePivotsCommand extends Command
{
    protected $signature = 'category-store:reconcile
                            {--file= : JSON {shop_code: [external_ids]} от admin_back shop-categories:export}
                            {--dry-run : Показать что будет отвязано, не выполняя detach}';

    protected $description = 'Зачищает устаревшие category_store pivot-записи по снапшоту из admin_back';

    public function handle(): int
    {
        $file = $this->option('file');
        if (! $file || ! is_file($file)) {
            $this->error('Укажите существующий --file=<path.json>');

            return self::FAILURE;
        }

        $raw = file_get_contents($file);
        $snapshot = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($snapshot)) {
            $this->error('Файл должен содержать JSON-объект {shop_code: [external_ids]}');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalDetached = 0;

        foreach ($snapshot as $shopCode => $validExternalIds) {
            if (! is_string($shopCode) || ! is_array($validExternalIds)) {
                $this->warn("Пропускаю некорректный блок: {$shopCode}");
                continue;
            }

            $store = Store::where('code', $shopCode)->first();
            if (! $store) {
                $this->line("[skip] shop_code={$shopCode}: Store не найден");
                continue;
            }

            $currentCategoryIds = $store->categories()->pluck('categories.id')->all();

            if (empty($currentCategoryIds)) {
                $this->line("[ok]   shop_code={$shopCode}: pivot пуст, нечего чистить");
                continue;
            }

            $externalIdByCategoryId = EventSyncMetadata::query()
                ->where('entity_type', 'categories')
                ->whereIn('entity_id', $currentCategoryIds)
                ->pluck('external_id', 'entity_id')
                ->all();

            $validSet = array_flip(array_map('strval', $validExternalIds));
            $toDetach = [];

            foreach ($currentCategoryIds as $categoryId) {
                $externalId = $externalIdByCategoryId[$categoryId] ?? null;

                if ($externalId === null) {
                    // Нет metadata — такую связь оставим в покое,
                    // она могла быть создана не через синк (сид, тест и т.п.).
                    continue;
                }

                if (! isset($validSet[(string) $externalId])) {
                    $toDetach[] = $categoryId;
                }
            }

            if (empty($toDetach)) {
                $this->line("[ok]   shop_code={$shopCode}: расхождений нет (" . count($currentCategoryIds) . ' связей)');
                continue;
            }

            $this->warn(sprintf(
                '[%s] shop_code=%s: отвязываю %d категори(и/й) из %d привязанных',
                $dryRun ? 'dry' : 'fix',
                $shopCode,
                count($toDetach),
                count($currentCategoryIds)
            ));

            foreach (Category::whereIn('id', $toDetach)->get() as $category) {
                $this->line("  - id={$category->id} «{$category->name}»");
            }

            if (! $dryRun) {
                $store->categories()->detach($toDetach);
            }

            $totalDetached += count($toDetach);
        }

        $this->info(sprintf(
            '%s: всего %s %d pivot-запис(и/ей)',
            $dryRun ? 'Dry-run' : 'Готово',
            $dryRun ? 'будет отвязано' : 'отвязано',
            $totalDetached
        ));

        return self::SUCCESS;
    }
}
