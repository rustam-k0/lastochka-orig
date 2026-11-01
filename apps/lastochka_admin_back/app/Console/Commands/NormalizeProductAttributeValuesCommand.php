<?php

namespace App\Console\Commands;

use App\Models\Type;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Разовый cleanup исторического дрейфа значений в `product_attributes`.
 *
 * Для каждого атрибута тип `attributes.type_id` определяет целевую колонку:
 *   1 STRING      → string_value
 *   2 TEXT        → text_value
 *   3 INT         → int_value
 *   4 FLOAT       → float_value
 *   5 SELECT_ONE  → attribute_value_id
 *
 * Старые строки хранят значение в «чужой» колонке (напр. type=TEXT,
 * а значение в string_value) → UI при сохранении продукта валится
 * на `ProductAttributeRule` с "Атрибуты заполнены неверно".
 *
 * Команда:
 *   1. Переносит значение из чужой колонки в целевую.
 *   2. Обнуляет остальные value-колонки.
 *   3. Дедупит пары (product_id, attribute_id), оставляя строку с заполненной
 *      целевой колонкой (при равенстве — минимальный id).
 *
 * По умолчанию dry-run; для записи — `--apply`. Перед apply рекомендуется
 * `mysqldump` таблиц `product_attributes`, `attributes` (см. CLAUDE.md).
 */
class NormalizeProductAttributeValuesCommand extends Command
{
    protected $signature = 'products:normalize-attribute-values
        {--apply : Применить изменения (по умолчанию dry-run)}
        {--product= : Ограничить обработку одним product_id}';

    protected $description = 'Переносит значения product_attributes в колонку соответствующую attributes.type_id и дедупит дубли';

    private const VALUE_COLUMNS = ['string_value', 'text_value', 'int_value', 'float_value', 'attribute_value_id'];

    public function handle(): int
    {
        $apply = (bool)$this->option('apply');
        $productFilter = $this->option('product');

        $typeColumnMap = [
            Type::ATTRIBUTE_STRING => 'string_value',
            Type::ATTRIBUTE_TEXT => 'text_value',
            Type::ATTRIBUTE_INT => 'int_value',
            Type::ATTRIBUTE_FLOAT => 'float_value',
            Type::ATTRIBUTE_SELECT_ONE => 'attribute_value_id',
        ];

        $query = DB::table('product_attributes as pa')
            ->join('attributes as a', 'a.id', '=', 'pa.attribute_id')
            ->select(
                'pa.id',
                'pa.product_id',
                'pa.attribute_id',
                'a.type_id',
                'pa.string_value',
                'pa.text_value',
                'pa.int_value',
                'pa.float_value',
                'pa.attribute_value_id'
            );

        if ($productFilter !== null) {
            $query->where('pa.product_id', (int)$productFilter);
        }

        $rows = $query->orderBy('pa.product_id')
            ->orderBy('pa.attribute_id')
            ->orderBy('pa.id')
            ->get();

        $this->info(sprintf('Строк в product_attributes: %d', $rows->count()));

        $normalizeStats = $this->normalizeRows($rows, $typeColumnMap, $apply);
        $dedupStats = $this->dedupRows($rows, $typeColumnMap, $apply);

        $this->info('--- Нормализация ---');
        $this->info(sprintf('  Перенесено значений: %d', $normalizeStats['moved']));
        $this->info(sprintf('  Очищено чужих колонок: %d', $normalizeStats['cleared']));

        $this->info('--- Дедупликация ---');
        $this->info(sprintf('  Групп с дублями: %d', $dedupStats['groups']));
        $this->info(sprintf('  Удалено строк: %d', $dedupStats['deleted']));

        if (!$apply) {
            $this->warn('Dry-run. Для применения: --apply (перед этим — mysqldump product_attributes).');
        }

        return self::SUCCESS;
    }

    private function normalizeRows(Collection $rows, array $typeColumnMap, bool $apply): array
    {
        $moved = 0;
        $cleared = 0;

        foreach ($rows as $row) {
            $targetColumn = $typeColumnMap[$row->type_id] ?? null;
            if ($targetColumn === null) {
                continue;
            }

            $targetValue = $row->{$targetColumn};

            if ($targetValue === null || $targetValue === '') {
                $replacement = $this->pickReplacement($row, $targetColumn);
                if ($replacement !== null) {
                    if ($apply) {
                        DB::table('product_attributes')
                            ->where('id', $row->id)
                            ->update([$targetColumn => $replacement]);
                    }
                    $row->{$targetColumn} = $replacement;
                    $moved++;
                }
            }

            $update = [];
            foreach (self::VALUE_COLUMNS as $column) {
                if ($column === $targetColumn) {
                    continue;
                }
                if ($row->{$column} !== null) {
                    $update[$column] = null;
                    $row->{$column} = null;
                    $cleared++;
                }
            }

            if ($apply && $update) {
                DB::table('product_attributes')->where('id', $row->id)->update($update);
            }
        }

        return ['moved' => $moved, 'cleared' => $cleared];
    }

    private function pickReplacement(object $row, string $targetColumn): mixed
    {
        foreach (self::VALUE_COLUMNS as $column) {
            if ($column === $targetColumn) {
                continue;
            }
            $value = $row->{$column};
            if ($value === null || $value === '') {
                continue;
            }

            if ($targetColumn === 'int_value') {
                return is_numeric($value) ? (int)$value : null;
            }
            if ($targetColumn === 'float_value') {
                return is_numeric($value) ? (float)$value : null;
            }
            if ($targetColumn === 'attribute_value_id') {
                return is_numeric($value) ? (int)$value : null;
            }

            return (string)$value;
        }

        return null;
    }

    private function dedupRows(Collection $rows, array $typeColumnMap, bool $apply): array
    {
        $groups = $rows->groupBy(fn ($r) => $r->product_id . ':' . $r->attribute_id)
            ->filter(fn ($g) => $g->count() > 1);

        $deleted = 0;

        foreach ($groups as $group) {
            $sorted = $group->sort(function ($a, $b) use ($typeColumnMap) {
                $aHasValue = $this->rowHasTargetValue($a, $typeColumnMap);
                $bHasValue = $this->rowHasTargetValue($b, $typeColumnMap);

                if ($aHasValue !== $bHasValue) {
                    return $aHasValue ? -1 : 1;
                }

                return $a->id <=> $b->id;
            })->values();

            $toDelete = $sorted->slice(1)->pluck('id')->all();
            if (!$toDelete) {
                continue;
            }

            if ($apply) {
                DB::table('product_attributes')->whereIn('id', $toDelete)->delete();
            }

            $deleted += count($toDelete);
        }

        return ['groups' => $groups->count(), 'deleted' => $deleted];
    }

    private function rowHasTargetValue(object $row, array $typeColumnMap): bool
    {
        $targetColumn = $typeColumnMap[$row->type_id] ?? null;
        if ($targetColumn === null) {
            return false;
        }
        $value = $row->{$targetColumn};
        return $value !== null && $value !== '';
    }
}
