<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builder объединённого списка «Без штрихкода» (FOODLINK-11039).
 *
 * Сливает два источника в одну пагинируемую выдачу:
 *  - карантин агрегатора (`aggregator_quarantine`, reason=no_ean, неразрешённые) —
 *    source="quarantine", у этих строк на фронте есть действие «Проставить штрихкод»;
 *  - проблемные товары 1С (`onec_problem_products`, code=missing_barcode) —
 *    source="onec", read-only.
 *
 * Общая проекция колонок (порядок в обоих SELECT обязан совпадать для UNION):
 *   source, id, shop_id, shop_title, name, sku, created_at.
 *
 * Используется через @paginate(builder: ...): Lighthouse сам навешивает LIMIT/OFFSET
 * и COUNT на union-запрос (Laravel оборачивает union в подзапрос для count).
 */
final class QuarantineNoBarcode
{
    public function builder($root, array $args): Builder
    {
        $shopId = $args['shop_id'] ?? null;

        // local_product_id (FOODLINK-11458): id товара курьерки для перехода на
        // карточку. Для quarantine — прямая ссылка q.local_product_id; для onec —
        // резолв по уникальному ключу products(shop_id, articul=sku), с учётом
        // soft-delete. Порядок колонок в обоих SELECT обязан совпадать (UNION).
        $quarantine = DB::table('aggregator_quarantine as q')
            ->leftJoin('shops as sq', 'sq.id', '=', 'q.shop_id')
            ->selectRaw(
                "'quarantine' as source, q.id as id, q.shop_id as shop_id, "
                . "sq.title as shop_title, q.incoming_title as name, null as sku, "
                . "q.created_at as created_at, q.local_product_id as local_product_id"
            )
            ->whereNull('q.resolved_at')
            ->where('q.reason', 'no_ean');

        $onec = DB::table('onec_problem_products as p')
            ->leftJoin('shops as sp', 'sp.id', '=', 'p.shop_id')
            ->leftJoin('products as pr', function ($join) {
                $join->on('pr.shop_id', '=', 'p.shop_id')
                    ->on('pr.articul', '=', 'p.sku')
                    ->whereNull('pr.deleted_at');
            })
            ->selectRaw(
                "'onec' as source, p.id as id, p.shop_id as shop_id, "
                . "sp.title as shop_title, p.name as name, p.sku as sku, "
                . "p.created_at as created_at, pr.id as local_product_id"
            )
            ->where('p.code', 'missing_barcode');

        if ($shopId !== null) {
            $quarantine->where('q.shop_id', $shopId);
            $onec->where('p.shop_id', $shopId);
        }

        return $quarantine->unionAll($onec)->orderBy('created_at', 'desc');
    }
}
