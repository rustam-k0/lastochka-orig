<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Models\Category1c;
use Illuminate\Support\Facades\DB;

/**
 * Observer для App\Models\Category.
 *
 * deleted: при удалении витринной категории — отвязать её от всех
 * categories_1c через pivot category_1c_category. Для тех 1С-категорий,
 * у которых после отвязки не осталось ни одной витринной, сбрасываем
 * linked_at, чтобы UI badge «✓ привязана» исчез и admin видел, что
 * 1С-категория снова требует привязки.
 *
 * Spec §3.5, LASTOCHKI-JAMI-10114.
 */
class CategoryObserver
{
    public function deleted(Category $category): void
    {
        // Находим все 1С-категории, у которых данная Category участвует в pivot.
        $cat1cIds = DB::table('category_1c_category')
            ->where('category_id', $category->id)
            ->pluck('categories_1c_id')
            ->all();

        if ($cat1cIds === []) {
            return;
        }

        // Удаляем pivot-записи для удаляемой Category.
        DB::table('category_1c_category')
            ->where('category_id', $category->id)
            ->delete();

        // Для тех 1С-категорий, у которых теперь нет ни одной привязки — сбрасываем linked_at.
        $nowOrphanIds = DB::table('category_1c_category')
            ->whereIn('categories_1c_id', $cat1cIds)
            ->pluck('categories_1c_id')
            ->all();

        $toUnlink = array_values(array_diff($cat1cIds, $nowOrphanIds));
        if ($toUnlink !== []) {
            Category1c::whereIn('id', $toUnlink)->update(['linked_at' => null]);
        }
    }
}
