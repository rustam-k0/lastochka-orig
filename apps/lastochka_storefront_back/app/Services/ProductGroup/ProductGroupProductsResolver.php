<?php

declare(strict_types=1);

namespace App\Services\ProductGroup;

use App\Enums\ProductGroupFillType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Товары товарной группы по способу наполнения (product_groups.fill_type).
 *
 * - manual — связь {@see ProductGroup::products()} как раньше (позиции админа);
 * - is_new / is_hurry_up — товары с галочкой, свежие сверху (products.id DESC);
 * - purchase_history — товары из доставленных заказов пользователя, сначала
 *   полученные последними;
 * - viewed_not_bought — товары, карточку которых пользователь открывал за
 *   последние personal_view_ttl_days дней и ни разу не получал, кроме лежащих
 *   в корзине; порядок — по весам config('product_groups.ranking').
 *
 * Для критериев товар ограничен магазином группы и стандартной покупаемостью
 * (то же условие, что у ручной группы: сетевой каталог — оффер магазина
 * группы, легаси — товары магазина группы). 18+ фильтрует вызывающий
 * (adultVisible), кроме проверки порога в {@see self::isVisibleFor()}.
 *
 * Порядок по умолчанию задан в самом запросе; вызывающий, применяющий свою
 * сортировку, делает reorder().
 */
final class ProductGroupProductsResolver
{
    /** Статусы позиций, которые пользователь действительно получил (null — до сборки/старые заказы). */
    private const RECEIVED_ITEM_STATUSES = ['added', 'confirmed'];

    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
    ) {}

    /**
     * Builder товаров группы или null, если группу этому пользователю не
     * отдаём (личная группа и гость).
     *
     * Для manual возвращается связь BelongsToMany (pivot position/quantity
     * нужен ресурсу товара), для критериев — Eloquent Builder по products.
     */
    public function query(ProductGroup $group, ?User $user): ?Builder
    {
        $type = $group->fillType();

        if ($type === ProductGroupFillType::Manual) {
            return $group->products();
        }

        if ($type->isPersonal() && $user === null) {
            return null;
        }

        $query = $this->purchasableInGroupStore($group);

        return match ($type) {
            ProductGroupFillType::IsNew => $query
                ->where('products.is_new', true)
                ->orderByDesc('products.id'),
            ProductGroupFillType::IsHurryUp => $query
                ->where('products.is_hurry_up', true)
                ->orderByDesc('products.id'),
            ProductGroupFillType::PurchaseHistory => $this->purchaseHistory($query, (int) $user->id),
            ProductGroupFillType::ViewedNotBought => $this->viewedNotBought($query, (int) $user->id, (int) $group->store_id),
        };
    }

    /**
     * Отдавать ли группу пользователю. Общие группы (manual, is_new,
     * is_hurry_up) — всегда; личные — только авторизованному и при
     * количестве товаров не меньше personal_min_products.
     *
     * Порог считается с учётом 18+ так же, как выдача: для авторизованного
     * {@see \App\Services\Catalog\AdultContentPolicy} смотрит только на
     * adult_confirmed профиля.
     */
    public function isVisibleFor(ProductGroup $group, ?User $user): bool
    {
        if (! $group->fillType()->isPersonal()) {
            return true;
        }

        $query = $this->query($group, $user);

        if ($query === null || $user === null) {
            return false;
        }

        $min = (int) config('product_groups.personal_min_products', 3);

        $count = $query
            ->adultVisible((bool) $user->adult_confirmed)
            ->reorder()
            ->count();

        return $count >= $min;
    }

    /**
     * Товары, доступные к покупке в магазине группы.
     */
    private function purchasableInGroupStore(ProductGroup $group): EloquentBuilder
    {
        $storeId = (int) $group->store_id;
        $query = Product::query();

        if ($this->moduleConfig->isNetworkCatalogEnabled()) {
            return $query->purchasableInNetworkStore($storeId);
        }

        return $query->forStore($storeId)->purchasable();
    }

    /**
     * «Из истории заказов»: одна строка на товар, сначала полученные последними.
     */
    private function purchaseHistory(EloquentBuilder $query, int $userId): EloquentBuilder
    {
        return $query
            ->whereExists(fn (QueryBuilder $q) => $this->receivedItems($q->selectRaw('1'), $userId)
                ->whereColumn('ph_oi.product_id', 'products.id'))
            ->orderByDesc(fn (QueryBuilder $q) => $this->receivedItems(
                $q->selectRaw('MAX(COALESCE(ph_o.delivered_at, ph_o.updated_at))'),
                $userId,
            )->whereColumn('ph_oi.product_id', 'products.id'))
            ->orderByDesc('products.id');
    }

    /**
     * «Смотрел, но не купил».
     */
    private function viewedNotBought(EloquentBuilder $query, int $userId, int $storeId): EloquentBuilder
    {
        $viewedFrom = now()->subDays((int) config('product_groups.personal_view_ttl_days', 14));

        $query
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('product_views as vnb_pv')
                ->whereColumn('vnb_pv.product_id', 'products.id')
                ->where('vnb_pv.user_id', $userId)
                ->where('vnb_pv.last_viewed_at', '>=', $viewedFrom))
            ->whereNotExists(fn (QueryBuilder $q) => $this->receivedItems($q->selectRaw('1'), $userId)
                ->whereColumn('ph_oi.product_id', 'products.id'))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')
                ->from('cart_items as vnb_ci')
                ->join('carts as vnb_c', 'vnb_c.id', '=', 'vnb_ci.cart_id')
                ->where('vnb_c.user_id', $userId)
                ->whereColumn('vnb_ci.product_id', 'products.id'));

        [$scoreSql, $scoreBindings] = $this->viewedScore($userId, $storeId);

        return $query
            ->orderByRaw("({$scoreSql}) DESC", $scoreBindings)
            ->orderByDesc(fn (QueryBuilder $q) => $q->select('vnb_lv.last_viewed_at')
                ->from('product_views as vnb_lv')
                ->where('vnb_lv.user_id', $userId)
                ->whereColumn('vnb_lv.product_id', 'products.id'))
            ->orderByDesc('products.id');
    }

    /**
     * Вес товара в «Смотрел, но не купил»: views_count × views
     * + cart_removed (клал в корзину и убрал) + favorite (в избранном)
     * + discount (сейчас скидка: ручная или акция).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function viewedScore(int $userId, int $storeId): array
    {
        $weights = (array) config('product_groups.ranking', []);
        $wViews = $weights['views'] ?? 1;
        $wCartRemoved = $weights['cart_removed'] ?? 2;
        $wFavorite = $weights['favorite'] ?? 2;
        $wDiscount = $weights['discount'] ?? 1;
        $now = now();

        // Ручная скидка: в сетевом каталоге — старая цена оффера магазина
        // группы, в легаси — колонка карточки (как Product::hasDiscount()).
        if ($this->moduleConfig->isNetworkCatalogEnabled()) {
            $manualSql = 'EXISTS (SELECT 1 FROM store_products vnb_sp'
                .' WHERE vnb_sp.product_id = products.id AND vnb_sp.store_id = ?'
                .' AND vnb_sp.is_active = ? AND vnb_sp.price_old IS NOT NULL)';
            $manualBindings = [$storeId, true];
        } else {
            $manualSql = 'products.price_old IS NOT NULL';
            $manualBindings = [];
        }

        // Акция — те же условия, что Product::activePromotions().
        $promoSql = 'EXISTS (SELECT 1 FROM promotion_products vnb_pp'
            .' INNER JOIN promotions vnb_pr ON vnb_pr.id = vnb_pp.promotion_id'
            .' WHERE vnb_pp.product_id = products.id AND vnb_pr.deleted_at IS NULL'
            .' AND vnb_pr.is_active = ? AND vnb_pr.starts_at <= ? AND vnb_pr.ends_at >= ?)';

        $sql = 'COALESCE((SELECT vnb_s.views_count * ?'
            .' + CASE WHEN vnb_s.cart_removed_at IS NOT NULL THEN ? ELSE 0 END'
            .' FROM product_views vnb_s WHERE vnb_s.user_id = ? AND vnb_s.product_id = products.id), 0)'
            .' + CASE WHEN EXISTS (SELECT 1 FROM favorites vnb_f WHERE vnb_f.user_id = ? AND vnb_f.product_id = products.id)'
            .' THEN ? ELSE 0 END'
            ." + CASE WHEN ({$manualSql} OR {$promoSql}) THEN ? ELSE 0 END";

        $bindings = [
            $wViews, $wCartRemoved, $userId,
            $userId, $wFavorite,
            ...$manualBindings,
            true, $now, $now,
            $wDiscount,
        ];

        return [$sql, $bindings];
    }

    /**
     * Позиции, которые пользователь получил (правила «Из истории заказов»):
     * заказ доставлен, позиция не снята при сборке (cancelled) и не заменена
     * (replaced — исходный товар замены; сама замена лежит отдельной строкой
     * added), и не возвращена полностью (доставленное quantity = 0).
     */
    private function receivedItems(QueryBuilder $q, int $userId): QueryBuilder
    {
        return $q->from('order_items as ph_oi')
            ->join('orders as ph_o', 'ph_o.id', '=', 'ph_oi.order_id')
            ->where('ph_o.user_id', $userId)
            ->where('ph_o.status', 'delivered')
            ->whereNull('ph_o.deleted_at')
            ->where(fn (QueryBuilder $s) => $s->whereNull('ph_oi.status')
                ->orWhereIn('ph_oi.status', self::RECEIVED_ITEM_STATUSES))
            // quantity доставленной позиции уже за вычетом возврата (курьерка шлёт
            // доставленное), returned_quantity — в порциях и справочно: повторное
            // вычитание выкидывало полученный товар (доставлен 1, возвращено 2).
            ->where('ph_oi.quantity', '>', 0);
    }
}
