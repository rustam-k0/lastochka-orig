<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category1c;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builder пагинированного списка 1С-категорий (query `categories_1c`).
 *
 * НАЗНАЧЕНИЕ — детерминированная пагинация. Lighthouse `@paginate` навешивает на
 * запрос LIMIT/OFFSET, но без явного ORDER BY MySQL возвращает строки в
 * недетерминированном порядке между независимыми HTTP-запросами разных страниц.
 * Фронт (OneCColumn.vue) грузит ВСЕ страницы циклом и строит дерево категорий из
 * объединения страниц — при «плавающем» порядке часть категорий дублируется между
 * страницами, а часть выпадает совсем (на 322 категориях наблюдалось 244 уникальных
 * из 322). В дереве пропадают промежуточные узлы, а их дети всплывают как корневые
 * (баг «Аджика, горчица, хрен» вне «Бакалея»). Стабильный `orderBy('id')` устраняет
 * рассинхрон страниц — объединение всегда полное и без дублей.
 *
 * Фильтры (shop_id, linked_status, search, parent_id, only_leaves, include_deleted,
 * is_active) перенесены из @scope/@eq-директив схемы сюда: при использовании
 * `@paginate(builder: ...)` Lighthouse не применяет аргументные директивы
 * автоматически. Логика не дублируется — переиспользуются scope-методы модели.
 *
 * Возвращается ИМЕННО Eloquent\Builder (не Query\Builder), чтобы Lighthouse
 * гидрировал модели Category1c и работали accessor'ы типа is_linked / is_new /
 * is_source_deleted и relation showcase_categories.
 */
final class PaginatedList
{
    public function builder($root, array $args): Builder
    {
        $query = Category1c::query();

        // include_deleted — раньше остальных условий: управляет global-scope SoftDeletes.
        $query->includeDeleted((bool) ($args['include_deleted'] ?? false));

        if (array_key_exists('shop_id', $args) && $args['shop_id'] !== null) {
            $query->where('shop_id', $args['shop_id']);
        }

        // linked_status имеет дефолт ALL — scope сам делает no-op для ALL.
        $query->linkedStatus($args['linked_status'] ?? 'ALL');

        if (array_key_exists('search', $args) && $args['search'] !== null) {
            $query->searchByName($args['search']);
        }

        if (array_key_exists('parent_id', $args) && $args['parent_id'] !== null) {
            $query->filterByParent($args['parent_id']);
        }

        $query->onlyLeaves((bool) ($args['only_leaves'] ?? false));

        if (array_key_exists('is_active', $args) && $args['is_active'] !== null) {
            $query->where('is_active', $args['is_active']);
        }

        // Eager-load обязателен: accessor'ы is_linked/is_new без загруженного
        // relation делают по 2 exists()-подзапроса НА КАЖДУЮ строку страницы
        // (на 200 строк = 400 лишних запросов на страницу).
        $query->with('showcaseCategories');

        // Детерминированный порядок — обязателен для корректной пагинации дерева.
        return $query->orderBy('id');
    }
}
