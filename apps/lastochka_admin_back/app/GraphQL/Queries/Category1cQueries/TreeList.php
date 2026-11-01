<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\Category1cQueries;

use App\Models\Category1c;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Полное дерево 1С-категорий магазина ОДНОЙ JSON-строкой (query `categories_1c_tree`).
 *
 * НАЗНАЧЕНИЕ — быстрая загрузка раздела «Категории 1С». Пагинированный
 * `categories_1c` (maxCount 200) заставлял фронт (OneCColumn) тянуть 6206
 * категорий за 31+ последовательный HTTP-запрос, а accessor'ы is_linked/is_new
 * без eager-load делали по 2 exists()-подзапроса на строку — раздел грузился
 * минутами.
 *
 * Возвращается String (json_encode массива строк), а НЕ [Category1c!]!:
 * по-полевой резолвинг Lighthouse стоит ~140 мкс/поле на dev-VM (2 QEMU-ядра),
 * что на 6206 строк × 9 полей давало ~13 секунд на запрос. Сборка массива в
 * PHP + один json_encode укладывается в миллисекунды. Замер: 2026-07-13,
 * бисекция по полям через реальный /graphql-admin.
 *
 * Сборка идёт БЕЗ гидрации Eloquent-моделей: get() 6.4k Category1c с
 * eager-load pivot стоил ~0.8 c, а map с Carbon-кастами и accessor'ами —
 * ещё ~1.5 c (профилирование 2026-08-13). toBase() + join привязок одним
 * запросом + сравнение дат строками укладывают резолвер в ~0.2 c.
 *
 * Дерево строится на фронте из плоского списка по parent_id — сортировка
 * и фильтрация (search / linked / showOnlyNew) там же, локально.
 */
final class TreeList
{
    public function __invoke($root, array $args): string
    {
        $shopId = (int) $args['shop_id'];

        $categories = Category1c::query()
            ->includeDeleted((bool) ($args['include_deleted'] ?? false))
            ->where('shop_id', $shopId)
            ->toBase()
            ->get(['id', 'name', 'parent_id', 'is_active', 'created_at', 'deleted_at']);

        // Привязки к витринным категориям одним запросом (зеркалит relation
        // showcaseCategories: pivot category_1c_category + живые categories).
        $links = DB::table('category_1c_category as l')
            ->join('categories_1c as c1c', 'c1c.id', '=', 'l.categories_1c_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('c1c.shop_id', $shopId)
            ->whereNull('c.deleted_at')
            ->orderBy('l.position')
            ->get(['l.categories_1c_id as cat1c_id', 'c.id', 'c.title'])
            ->groupBy('cat1c_id');

        // created_at в формате Y-m-d H:i:s сравнивается со строкой корректно.
        $newThreshold = Carbon::now()->subDays(30)->toDateTimeString();

        $rows = [];
        foreach ($categories as $category) {
            $showcase = [];
            foreach ($links->get($category->id, []) as $link) {
                $showcase[] = ['id' => $link->id, 'title' => $link->title];
            }

            $rows[] = [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'is_active' => (bool) $category->is_active,
                'is_linked' => $showcase !== [],
                'is_new' => $category->created_at !== null
                    && $category->created_at > $newThreshold
                    && $showcase === [],
                'is_source_deleted' => $category->deleted_at !== null,
                'showcase_categories' => $showcase,
            ];
        }

        return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
