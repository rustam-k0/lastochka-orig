<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Settings\ModuleConfigService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Секции экрана «Фильтр» с учётом текущего выбора (взаимозависимые фильтры).
 *
 * Состав секций и значений считается по выдаче БЕЗ выбора пользователя —
 * экран не прыгает, недоступные значения остаются в списке. Выбор влияет
 * только на счётчики: у значения группы `count` — сколько товаров останется,
 * если его добавить к выбору в остальных группах; собственный выбор группы
 * не учитывается ({@see CatalogFilterQuery::applyCriteria()}). Границы цены
 * считаются так же — по всем группам, кроме самой цены.
 *
 * Формат секций:
 * - `{code:"price", name, type:"range", min, max}` — всегда первой;
 * - `{code, name, type:"checkbox", values:[{id, code, name, count, available}]}` — select-атрибуты;
 * - `{code, name, type:"flag", count, available}` — динамические чекбоксы товара.
 */
final class CatalogFilterFacets
{
    public function __construct(
        private readonly CatalogFilterQuery $filterQuery,
        private readonly ModuleConfigService $moduleConfig,
    ) {}

    /**
     * @param  Closure(): Builder<Product>  $scope  свежий запрос товаров выдачи без выбора пользователя;
     *                                              в сетевом режиме с присоединённым оффером $networkStoreId
     * @return list<array<string, mixed>>
     */
    public function sections(Closure $scope, CatalogFilterCriteria $criteria, ?int $networkStoreId): array
    {
        $sections = [$this->priceSection($scope, $criteria, $networkStoreId)];

        $attributeSections = $this->attributeSections($scope, $criteria, $networkStoreId);
        array_push($sections, ...$attributeSections);

        $attributeCodes = array_column($attributeSections, 'code');

        foreach ($this->moduleConfig->productFlagFilters() as $flag) {
            // Слаг, совпавший с кодом атрибутной секции, не дублируем — листинг
            // отдаёт коллизию флаг-ветке (см. CatalogFilterCriteria::fromRequest()).
            if (in_array($flag['field'], $attributeCodes, true)) {
                continue;
            }

            $field = "products.custom_checkboxes->{$flag['field']}";

            // Секция выводится, только если в выдаче есть хотя бы один товар с флагом.
            if (! $scope()->where($field, true)->exists()) {
                continue;
            }

            $count = $this->filterQuery
                ->applyCriteria($scope(), $criteria, $networkStoreId, $flag['field'])
                ->where($field, true)
                ->count();

            $sections[] = [
                'code' => $flag['field'],
                'name' => $flag['label'],
                'type' => 'flag',
                'count' => $count,
                'available' => $count > 0,
            ];
        }

        return $sections;
    }

    /**
     * Секции пустой выдачи — для деградации, когда выдачу посчитать не удалось.
     *
     * @return list<array<string, mixed>>
     */
    public static function empty(): array
    {
        return [self::price(0, 0)];
    }

    /**
     * @param  Closure(): Builder<Product>  $scope
     * @return array<string, mixed>
     */
    private function priceSection(Closure $scope, CatalogFilterCriteria $criteria, ?int $networkStoreId): array
    {
        $column = CatalogFilterQuery::priceColumn($networkStoreId);

        $query = $this->filterQuery
            ->applyCriteria($scope(), $criteria, $networkStoreId, CatalogFilterCriteria::PRICE)
            ->where($column, '>', 0);

        // Цена оффера дробная: границы округляем наружу, иначе товар за 99.50
        // выпадает из диапазона «от 100», а за 900.40 — из «до 900».
        return self::price(
            (int) floor((float) (clone $query)->min($column)),
            (int) ceil((float) (clone $query)->max($column)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(int $min, int $max): array
    {
        return [
            'code' => 'price',
            'name' => 'Цена',
            'type' => 'range',
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Select-атрибуты (`attribute_value_id IS NOT NULL`): text/int/float
     * атрибуты справочника значений не имеют и в фильтры не попадают.
     *
     * @param  Closure(): Builder<Product>  $scope
     * @return list<array<string, mixed>>
     */
    private function attributeSections(Closure $scope, CatalogFilterCriteria $criteria, ?int $networkStoreId): array
    {
        $rows = ProductAttribute::query()
            ->whereIn('product_id', self::productIds($scope()))
            ->whereNotNull('attribute_value_id')
            ->get(['code', 'name', 'value', 'attribute_value_id', 'attribute_value_code', 'position']);

        if ($rows->isEmpty()) {
            return [];
        }

        $counts = $this->valueCounts($scope, $criteria, $networkStoreId, $rows->pluck('code')->unique()->all());

        $grouped = $rows->groupBy('code');
        $codeOrder = $grouped
            ->map(fn (Collection $items) => $items->min('position') ?? 0)
            ->sort()
            ->keys();

        $sections = [];

        foreach ($codeOrder as $code) {
            $items = $grouped->get($code);

            $values = $items
                ->unique(fn (ProductAttribute $item) => $item->attribute_value_id)
                ->values()
                ->map(function (ProductAttribute $item) use ($counts, $code) {
                    $count = $counts[$code][(int) $item->attribute_value_id] ?? 0;

                    return [
                        'id' => (int) $item->attribute_value_id,
                        // Часть значений синхронизирована из 1С без attribute_value_code
                        // (см. CatalogFilterQuery::applyCriteria() — там симметричный
                        // фолбэк на id, иначе такие значения нельзя выбрать вообще).
                        'code' => $item->attribute_value_code ?? (string) $item->attribute_value_id,
                        'name' => $item->value !== '' ? $item->value : (string) $item->attribute_value_code,
                        'count' => $count,
                        'available' => $count > 0,
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            $sections[] = [
                'code' => $code,
                'name' => (string) $items->first()->name,
                'type' => 'checkbox',
                'values' => $values,
            ];
        }

        return $sections;
    }

    /**
     * Счётчики товаров по значениям: [code => [attribute_value_id => count]].
     *
     * Коды без собственного выбора считаются одним запросом по выдаче со всем
     * выбором пользователя; каждый код с выбором — отдельным запросом по
     * выдаче без своего выбора.
     *
     * @param  Closure(): Builder<Product>  $scope
     * @param  list<string>  $codes  коды атрибутных секций
     * @return array<string, array<int, int>>
     */
    private function valueCounts(Closure $scope, CatalogFilterCriteria $criteria, ?int $networkStoreId, array $codes): array
    {
        $selectedCodes = array_values(array_intersect($codes, array_keys($criteria->attributes)));
        $counts = [];

        $batches = [[array_values(array_diff($codes, $selectedCodes)), null]];
        foreach ($selectedCodes as $code) {
            $batches[] = [[$code], $code];
        }

        foreach ($batches as [$batchCodes, $except]) {
            if ($batchCodes === []) {
                continue;
            }

            $rows = ProductAttribute::query()
                ->whereIn('product_id', self::productIds(
                    $this->filterQuery->applyCriteria($scope(), $criteria, $networkStoreId, $except)
                ))
                ->whereIn('code', $batchCodes)
                ->whereNotNull('attribute_value_id')
                ->groupBy('code', 'attribute_value_id')
                ->selectRaw('code, attribute_value_id, COUNT(DISTINCT product_id) AS products_count')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $counts[$row->code][(int) $row->attribute_value_id] = (int) $row->products_count;
            }
        }

        return $counts;
    }

    /**
     * Подзапрос id товаров выдачи. select заменяет `products.*`, который
     * навешивает join оффера в сетевом режиме.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private static function productIds(Builder $query): Builder
    {
        return $query->select('products.id');
    }
}
