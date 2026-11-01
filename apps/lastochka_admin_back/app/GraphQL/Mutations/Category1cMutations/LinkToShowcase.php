<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\Category1cMutations;

use App\Jobs\Category1c\PropagateShowcaseCategoriesToShopsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Services\ModuleConfigService;
use App\Services\ShopService;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;

/**
 * Привязать массив 1С-категорий к массиву витринных категорий (аддитивно).
 *
 * Семантика: для каждой 1С-категории делается `syncWithoutDetaching` массива витрин
 * — существующие привязки сохраняются, новые добавляются.
 *
 * Если выбранная витрина ещё не привязана к магазину 1С-категории — она (вместе со
 * всеми предками) автоматически добавляется в `shops_categories` этого магазина через
 * ShopService::attachCategoriesWithEvents (с публикацией события в storefront). Это
 * типовой сценарий только что созданной витрины: товаровед привязывает к ней 1С,
 * витрина сама появляется в магазине и в дереве категорий приложения.
 *
 * Валидации (см. spec §5.4 + 2026-05-14):
 *  - SHOWCASE_NOT_LEAF — у любой из витрин есть children
 *  - CATEGORY_1C_DELETED — 1С-категория удалена в источнике
 *  - CATEGORY_1C_NOT_LEAF — у 1С-категории есть children
 */
final class LinkToShowcase
{
    /**
     * @param  array{category_1c_ids: list<int>, showcase_category_ids: list<int>}  $args
     * @return list<Category1c>
     */
    public function __invoke($_, array $args): array
    {
        $cat1cIds = array_values(array_unique(array_map('intval', $args['category_1c_ids'])));
        $showcaseIds = array_values(array_unique(array_map('intval', $args['showcase_category_ids'])));

        if ($showcaseIds === []) {
            return Category1c::whereIn('id', $cat1cIds)->get()->all();
        }

        // Загружаем все витрины со списком детей; нелистовые отклоняем.
        $showcases = Category::with('childCategories')->whereIn('id', $showcaseIds)->get();
        foreach ($showcases as $showcase) {
            if ($showcase->childCategories && $showcase->childCategories->isNotEmpty()) {
                throw new Error(
                    'Showcase category must be a leaf',
                    null, null, [], null, null,
                    ['code' => 'SHOWCASE_NOT_LEAF', 'showcase_category_id' => $showcase->id]
                );
            }
        }

        $cats1c = Category1c::withTrashed()
            ->with('children')
            ->whereIn('id', $cat1cIds)
            ->get();

        foreach ($cats1c as $cat1c) {
            if ($cat1c->trashed()) {
                throw new Error(
                    'Category 1c is deleted in 1C',
                    null, null, [], null, null,
                    ['code' => 'CATEGORY_1C_DELETED', 'category_1c_id' => $cat1c->id]
                );
            }
            if ($cat1c->children->isNotEmpty()) {
                throw new Error(
                    'Category 1c must be a leaf',
                    null, null, [], null, null,
                    ['code' => 'CATEGORY_1C_NOT_LEAF', 'category_1c_id' => $cat1c->id]
                );
            }
        }

        DB::transaction(function () use ($cats1c, $showcaseIds) {
            foreach ($cats1c as $cat1c) {
                $existingPositions = $cat1c->showcaseCategories()->pluck('category_1c_category.position')->all();
                $nextPosition = $existingPositions === [] ? 0 : (max($existingPositions) + 1);

                $payload = [];
                foreach ($showcaseIds as $id) {
                    $payload[$id] = ['position' => $nextPosition++];
                }

                // syncWithoutDetaching: добавляет новые, не трогает существующие.
                // Position у уже существующих не меняется.
                $cat1c->showcaseCategories()->syncWithoutDetaching($payload);
                $cat1c->forceFill(['linked_at' => now()])->save();
            }
        });

        // Авто-привязка витрин к магазинам — строго ПОСЛЕ коммита привязки:
        // раскатка это следствие сохранённого маппинга, а не его условие. Иначе
        // при падении транзакции job уже улетел бы в очередь, а воркер мог бы
        // начать работу по ещё не сохранённой привязке.
        //
        // Сетевой режим: асинхронно ко ВСЕМ магазинам сети (синхронный цикл по
        // 800+ магазинам заблокировал бы мутацию).
        // Пер-магазинный режим: синхронно к магазинам выбранных 1С-категорий.
        if (ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null) {
            PropagateShowcaseCategoriesToShopsJob::dispatch($showcaseIds);
        } else {
            $shopService = app(ShopService::class);
            foreach ($cats1c->pluck('shop_id')->unique() as $shopId) {
                $shop = Shop::find($shopId);
                if ($shop) {
                    $shopService->attachCategoriesWithEvents($shop, $showcaseIds);
                }
            }
        }

        return Category1c::whereIn('id', $cat1cIds)->get()->all();
    }
}
