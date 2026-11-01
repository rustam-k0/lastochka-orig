<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;

/**
 * Выбор пользователя на экране «Фильтр»: диапазон цены, значения
 * select-атрибутов и флаги динамических чекбоксов товара.
 *
 * Каждая из этих групп — фасет: при подсчёте доступности значений группы её
 * собственный выбор не учитывается ({@see CatalogFilterQuery::applyCriteria()},
 * параметр $except). Переключатели выдачи «только со скидкой» / «без сахара»
 * фасетами не являются и сюда не входят.
 */
final class CatalogFilterCriteria
{
    /** Имя группы цены для исключения из фасетного подсчёта. */
    public const PRICE = 'price';

    /**
     * @param  array<string, list<string>>  $attributes  код атрибута → коды значений (OR внутри кода, AND между кодами)
     * @param  list<string>  $flags  включённые флаги динамических чекбоксов
     */
    public function __construct(
        public readonly ?int $priceMin = null,
        public readonly ?int $priceMax = null,
        public readonly array $attributes = [],
        public readonly array $flags = [],
    ) {}

    /**
     * Разобрать query-параметры листинга: `priceMin`, `priceMax` и
     * `filter[code]=value_code1,value_code2`. Deprecated `price_min`/`price_max`
     * принимаются как алиасы.
     *
     * Коды динамических чекбоксов (`custom && enabled && filterable`, см.
     * {@see ModuleConfigService::productFlagFilters()}) имеют приоритет над
     * одноимёнными атрибутами: `filter[<флаг>]=1` (или `true`) включает флаг,
     * любое другое значение фильтр не включает.
     */
    public static function fromRequest(Request $request): self
    {
        $flagFields = array_column(app(ModuleConfigService::class)->productFlagFilters(), 'field');

        $attributes = [];
        $flags = [];
        $filters = $request->input('filter', []);

        foreach (is_array($filters) ? $filters : [] as $code => $raw) {
            if (! is_string($code) || $code === '') {
                continue;
            }

            if (in_array($code, $flagFields, true)) {
                $value = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';

                if (in_array($value, ['1', 'true'], true)) {
                    $flags[] = $code;
                }

                continue;
            }

            $values = array_values(array_filter(array_map(
                static fn ($v) => is_scalar($v) ? trim((string) $v) : null,
                is_array($raw) ? $raw : explode(',', (string) $raw),
            ), static fn ($v) => $v !== null && $v !== ''));

            if ($values !== []) {
                $attributes[$code] = $values;
            }
        }

        return new self(
            priceMin: self::intParam($request, 'priceMin', 'price_min'),
            priceMax: self::intParam($request, 'priceMax', 'price_max'),
            attributes: $attributes,
            flags: $flags,
        );
    }

    /**
     * Ничего не выбрано — выдачу сужать нечем.
     */
    public function isEmpty(): bool
    {
        return $this->priceMin === null
            && $this->priceMax === null
            && $this->attributes === []
            && $this->flags === [];
    }

    private static function intParam(Request $request, string $name, string $deprecatedAlias): ?int
    {
        $key = $request->filled($name) ? $name : ($request->filled($deprecatedAlias) ? $deprecatedAlias : null);

        return $key === null ? null : $request->integer($key);
    }
}
