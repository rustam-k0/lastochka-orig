<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill order_items.origin_product_id для исторических added-позиций (ALADDIN-10274).
 *
 * До фикса storefront/OrderEventTransformer::syncItemsAndRecalc ветка "create new item"
 * не записывала origin_product_id в БД, хотя payload содержал admin's origin_product_id.
 * Эта команда резолвит admin's origin_product_id → storefront's product_id через
 * event_sync_metadata (source='1c') и проставляет origin_product_id.
 *
 * Резолв строится через cross-DB JOIN admin БД на той же машине:
 *   1) storefront.order_id → admin.order_id (admin.event_sync_metadata, source='storefront')
 *   2) admin's order_item с replacement (origin_product_id IS NOT NULL <> product_id)
 *   3) title-match: storefront.order_item.title == admin.product.title (для replacement)
 *   4) admin's origin_product_id → 1C UUID (admin.event_sync_metadata, source='1c')
 *   5) 1C UUID → storefront's product_id (storefront.event_sync_metadata, source='1c')
 *
 * Items без 1С-metadata у origin product (локальные admin-only products), без origin_title
 * или с расхождением title между сервисами — НЕ затрагиваются (остаются NULL). Это
 * сознательный trade-off: лучше пропустить чем записать неверный origin_product_id.
 *
 * Команда:
 *  - Идемпотентна (WHERE origin_product_id IS NULL в UPDATE).
 *  - Не двигает updated_at (QB update без timestamps).
 *  - Не публикует outbox-события.
 *  - Безопасна к параллельным запускам (per-row UPDATE).
 *
 * Использование:
 *   php artisan orders:backfill-origin-product-id                  # dry-run, отчёт
 *   php artisan orders:backfill-origin-product-id --apply          # реальный UPDATE
 *   php artisan orders:backfill-origin-product-id --apply --admin-db=cron_admin
 *
 * Перед --apply на prod обязательно:
 *   mysqldump --single-transaction --quick --no-tablespaces aladdin_store order_items \
 *     | gzip > /home/admin/backups/order-items-backfill-$(date +%Y%m%d-%H%M%S)/order_items.sql.gz
 */
final class BackfillOrderItemOriginProductIdCommand extends Command
{
    protected $signature = 'orders:backfill-origin-product-id
                            {--apply : Реально записать UPDATE (по умолчанию dry-run)}
                            {--admin-db=cron_admin : Имя admin БД на том же MySQL-инстансе}';

    protected $description = 'Backfill order_items.origin_product_id для исторических added-позиций (ALADDIN-10274)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $adminDb = (string) $this->option('admin-db');
        $selfDb = DB::connection()->getDatabaseName();

        $this->line(sprintf('Режим: %s', $apply ? 'APPLY' : 'DRY-RUN'));
        $this->line(sprintf('storefront DB: %s', $selfDb));
        $this->line(sprintf('admin DB:      %s', $adminDb));

        // Total NULL added-items — для отчёта о покрытии.
        $totalNull = (int) DB::table('order_items')
            ->where('status', 'added')
            ->whereNull('origin_product_id')
            ->count('id');

        $resolvable = DB::select(sprintf(
            "SELECT
                soi.id                          AS storefront_oi_id,
                soi.order_id                    AS storefront_order_id,
                soi.title                       AS storefront_title,
                aoi.id                          AS admin_oi_id,
                aoi.origin_product_id           AS admin_origin_pid,
                pm_origin.entity_id             AS storefront_origin_product_id
            FROM `%s`.order_items soi
            JOIN `%s`.event_sync_metadata am
                ON CAST(am.external_id AS UNSIGNED) = soi.order_id
               AND am.source      = 'storefront'
               AND am.entity_type = 'orders'
            JOIN `%s`.order_items aoi
                ON aoi.order_id          = am.entity_id
               AND aoi.origin_product_id IS NOT NULL
               AND aoi.origin_product_id <> aoi.product_id
            JOIN `%s`.products ap_replace
                ON ap_replace.id = aoi.product_id
            JOIN `%s`.event_sync_metadata adm_origin
                ON adm_origin.entity_id   = aoi.origin_product_id
               AND adm_origin.entity_type = 'products'
               AND adm_origin.source      = '1c'
            JOIN `%s`.event_sync_metadata pm_origin
                ON pm_origin.external_id = adm_origin.external_id
               AND pm_origin.entity_type = 'products'
               AND pm_origin.source      = '1c'
            WHERE soi.status            = 'added'
              AND soi.origin_product_id IS NULL
              AND soi.title             = ap_replace.title
            ORDER BY soi.id",
            $selfDb, $adminDb, $adminDb, $adminDb, $adminDb, $selfDb
        ));

        $this->info(sprintf('NULL added-items всего:          %d', $totalNull));
        $this->info(sprintf('Резолвится через 1C metadata:    %d', count($resolvable)));
        $this->info(sprintf('Останется NULL (нерезолвимые):   %d', $totalNull - count($resolvable)));

        if ($resolvable === []) {
            $this->warn('Нет items для backfill.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['storefront_oi', 'order_id', 'title', 'admin_oi', 'admin_origin_pid', 'sf_origin_pid'],
            array_map(static fn ($r) => [
                $r->storefront_oi_id,
                $r->storefront_order_id,
                $r->storefront_title,
                $r->admin_oi_id,
                $r->admin_origin_pid,
                $r->storefront_origin_product_id,
            ], array_slice($resolvable, 0, 30))
        );

        if (count($resolvable) > 30) {
            $this->line(sprintf('... ещё %d строк', count($resolvable) - 30));
        }

        if (! $apply) {
            $this->warn('Запущено в dry-run. Для записи передайте --apply.');

            return self::SUCCESS;
        }

        $updated = 0;
        DB::transaction(function () use ($resolvable, &$updated): void {
            foreach ($resolvable as $row) {
                $updated += DB::table('order_items')
                    ->where('id', $row->storefront_oi_id)
                    ->whereNull('origin_product_id')
                    ->update(['origin_product_id' => (int) $row->storefront_origin_product_id]);
            }
        });

        $this->info(sprintf('Обновлено строк: %d', $updated));

        return self::SUCCESS;
    }
}
