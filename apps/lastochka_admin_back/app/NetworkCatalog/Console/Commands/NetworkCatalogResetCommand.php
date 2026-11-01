<?php

namespace App\NetworkCatalog\Console\Commands;

use App\NetworkCatalog\Support\NetworkCatalogMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Полная зачистка каталожных данных djami перед перезаливкой сети из 1С
 * (см. docs/specs/2026-08-06-network-catalog-design.md §9).
 *
 * Чистит ТОЛЬКО каталог и его синк-метаданные. Магазины, клиенты, настройки,
 * пользователи не затрагиваются. Заказы чистятся опционально (--with-orders),
 * т.к. order_items держит FK на products.
 *
 * Важно: TRUNCATE сбрасывает AUTO_INCREMENT — id заново созданных карточек
 * начнутся с 1. Таблицы, которые ссылаются на products.id, но не входят в
 * каталог по дизайну (product_reviews, product_favourites, cart_items,
 * join_img и т.п. — клиентские/витринные данные), этой командой НЕ чистятся;
 * на dev-стенде с уже накопленными данными это может привести к тому, что
 * старые записи «перепривяжутся» к новому товару с переиспользованным id.
 * Для одноразового включения network_catalog на dev это принятый риск
 * (golden-path верификация из §9 его покрывает), но не для повторных прогонов
 * на стенде с боевыми клиентскими данными.
 */
class NetworkCatalogResetCommand extends Command
{
    protected $signature = 'network-catalog:reset
        {--confirm : Подтверждение необратимой операции}
        {--with-orders : Также удалить заказы (order_items держит FK на products)}';

    protected $description = 'Зачистить каталог djami для перезаливки сети из 1С (dev-only)';

    /** Каталожные таблицы в порядке удаления (children -> parents). */
    private const CATALOG_TABLES = [
        'product_attribute_changes_log',
        'product_history',
        'product_documents',
        'product_attributes',
        'category_product',
        'product_offers',
        'aggregator_quarantine',
        'prices',
        'remains',
        'products',
    ];

    private const ORDER_TABLES = [
        'order_item_supplements',
        'order_items',
        'order_history',
        'orders',
    ];

    public function handle(NetworkCatalogMode $mode): int
    {
        if (App::environment('production')) {
            $this->error('Запрещено на production.');
            return self::FAILURE;
        }

        if (!$mode->isEnabled()) {
            $this->error('Модуль network_catalog выключен. Включите его перед зачисткой.');
            return self::FAILURE;
        }

        if (!$this->option('confirm')) {
            $this->error('Необратимая операция. Запустите с --confirm.');
            return self::FAILURE;
        }

        $withOrders = (bool) $this->option('with-orders');

        if (!$withOrders && DB::table('order_items')->exists()) {
            $this->error('В order_items есть строки с FK на products. Запустите с --with-orders либо очистите заказы вручную.');
            return self::FAILURE;
        }

        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            if ($withOrders) {
                foreach (self::ORDER_TABLES as $table) {
                    $this->truncateIfExists($table);
                }

                // Синк-метаданные заказов обязаны умереть вместе с заказами:
                // TRUNCATE сбрасывает AUTO_INCREMENT, и уцелевшая metadata
                // указывает либо в никуда («Entity not found» → событие
                // теряется после ретраев), либо в чужую новую строку с
                // переиспользованным id (тихий мёрж). Оба сценария пойманы
                // на dev после включения network_catalog (MID-235).
                DB::table('event_sync_metadata')->whereIn('entity_type', ['orders', 'order_items'])->delete();
                DB::table('event_outbox')->whereIn('entity_type', ['orders', 'order_items'])->delete();
            }

            foreach (self::CATALOG_TABLES as $table) {
                $this->truncateIfExists($table);
            }

            DB::table('event_sync_metadata')->where('entity_type', 'products')->delete();
            DB::table('event_outbox')->where('entity_type', 'products')->delete();
        } finally {
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $this->info('Каталог djami зачищен. Дальше: перевыгрузка из 1С (категории -> карточки -> цены -> остатки).');
        $this->info('После включения/выключения network_catalog перезапустите supervisor-воркеры (rabbitmq:consume-all) и php-fpm: global_scope и кэш флага фиксируются при старте процесса.');

        return self::SUCCESS;
    }

    private function truncateIfExists(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            $this->line("  truncated: {$table}");
        }
    }
}
