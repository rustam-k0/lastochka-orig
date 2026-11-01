<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зачистка каталога storefront перед перезаливкой сети из 1С
 * (см. docs/specs/2026-08-06-network-catalog-design.md §9).
 *
 * Аналог admin_back App\NetworkCatalog\Console\Commands\NetworkCatalogResetCommand,
 * но со storefront-таблицами (mendev_store) и дропом/пересозданием Meili-индекса
 * вместо продуктовых djami-таблиц.
 *
 * Список CATALOG_TABLES и ORDER_TABLES собран полным аудитом миграций
 * storefront_back (grep по foreignId('product_id')/foreignId('order_id') и
 * constrained('products')/constrained('orders')) — шире исходного наброска из
 * ТЗ задачи. Причина: TRUNCATE сбрасывает AUTO_INCREMENT, и после перезаливки
 * из 1С новые товары/тестовые заказы получат те же id, что были у старых. Любая
 * таблица с "повисшим" product_id/order_id после такого TRUNCATE рискует молча
 * "переприкрепиться" к чужой новой записи с переиспользованным id — поэтому
 * здесь чистятся ВСЕ таблицы с FK на products/orders, а не только те, что
 * попали в исходный список ТЗ. Три разных механизма чистки в зависимости от
 * того, обязательна ли ссылка и существуют ли строки без неё:
 *
 * 1. Обязательный (не nullable) FK → truncate всей таблицы (CATALOG_TABLES,
 *    большая часть ORDER_TABLES): строка без products/orders существовать
 *    не может, весь её смысл — быть спутником каталога/заказа.
 * 2. Nullable FK с явным ON DELETE SET NULL в схеме, где сущность осмысленна
 *    и без товара/заказа (banners/stories.link_product_id,
 *    store_reviews/promocode_usages.order_id — баннер, отзыв на магазин,
 *    лимит использования промокода) — ссылка сбрасывается в NULL вручную,
 *    таблица не truncate-ится. TRUNCATE (в отличие от обычного DELETE) эту
 *    FK-экшн не запускает ни на MySQL, ни на SQLite.
 * 3. Nullable FK БЕЗ nullOnDelete (payment_transactions/bonus_transactions:
 *    order_id nullable, но в схеме cascadeOnDelete), где часть строк
 *    легитимно существует без заказа: привязка карты
 *    (PaymentService::bindCard(), order_id=null) и ручные/офлайн бонусные
 *    операции (BonusService, source=manual/offline, order_id=null) — они не
 *    заказ-артефакты и truncate их бы уничтожил. Удаляются точечно только
 *    строки с непустым order_id (см. ORDER_LINKED_OPTIONAL_TABLES).
 */
class NetworkCatalogResetCommand extends Command
{
    protected $signature = 'network-catalog:reset
        {--confirm : Подтверждение необратимой операции}
        {--with-orders : Также удалить заказы (order_items.product_id ссылается на products без физического FK)}';

    protected $description = 'Зачистить каталог storefront для перезаливки сети из 1С (dev-only)';

    /** Каталожные таблицы в порядке удаления (children -> parents). */
    private const CATALOG_TABLES = [
        // Клиентские списки/действия с обязательным FK на products
        'comparison_items',
        'estimate_items',
        'promotion_products',
        'promocode_products',
        'preorders',
        'product_issue_reports',
        'product_reviews',
        'product_documents',
        'configurable_attributes',
        'product_attributes',
        'product_images',
        'product_group_items',
        'favorites',
        // Корзина и добавки: добавка (supplement) сама принадлежит product_id,
        // join- и value-таблицы — её дети
        'join_cart_item_supplements_supplement_values',
        'cart_item_supplements',
        'cart_items',
        'supplement_values',
        'supplements',
        // Витрина сети
        'category_product',
        'store_products',
        'products',
    ];

    /** Таблицы заказов в порядке удаления (children -> parents), только при --with-orders. */
    private const ORDER_TABLES = [
        'join_order_item_supplements_supplement_values',
        'order_item_supplements',
        'order_items',
        'order_addresses',
        'order_promocodes',
        'order_bonuses',
        'order_reviews',
        'order_supplement_idempotency',
        'bonus_holds',
        'payment_receipts',
        'yandex_delivery_claims',
        'orders',
    ];

    /**
     * order_id nullable, но не nullOnDelete (cascadeOnDelete в схеме) — часть строк
     * легитимно существует без заказа (привязка карты, ручные/офлайн бонусные
     * операции). Truncate целиком стёр бы их — удаляются только строки с order_id.
     */
    private const ORDER_LINKED_OPTIONAL_TABLES = [
        'payment_transactions',
        'bonus_transactions',
    ];

    public function handle(ModuleConfigService $modules, SearchClientInterface $search): int
    {
        if (App::environment('production')) {
            $this->error('Запрещено на production.');

            return self::FAILURE;
        }

        if (! $modules->isNetworkCatalogEnabled()) {
            $this->error('Модуль network_catalog выключен. Включите его перед зачисткой.');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('Необратимая операция. Запустите с --confirm.');

            return self::FAILURE;
        }

        $withOrders = (bool) $this->option('with-orders');

        if (! $withOrders && DB::table('order_items')->exists()) {
            $this->error('В order_items есть строки со ссылкой на products (product_id). Запустите с --with-orders либо очистите заказы вручную.');

            return self::FAILURE;
        }

        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            if ($withOrders) {
                $this->nullifyOrderReferences();
                $this->deleteOrderLinkedRows();
                foreach (self::ORDER_TABLES as $table) {
                    $this->truncateIfExists($table);
                }

                // Синк-метаданные заказов обязаны умереть вместе с заказами:
                // TRUNCATE сбрасывает AUTO_INCREMENT, и уцелевшая metadata
                // указывает либо в никуда («Entity not found» → событие
                // теряется после ретраев), либо в чужую новую строку с
                // переиспользованным id (тихий мёрж). Поймано на dev после
                // включения network_catalog (MID-235).
                DB::table('event_sync_metadata')->whereIn('entity_type', ['orders', 'order_items'])->delete();
                DB::table('event_outbox')->whereIn('entity_type', ['orders', 'order_items'])->delete();
            }

            $this->nullifyProductReferences();
            foreach (self::CATALOG_TABLES as $table) {
                $this->truncateIfExists($table);
            }

            DB::table('event_sync_metadata')->where('entity_type', 'products')->delete();
        } finally {
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        try {
            $search->resetIndex();
            $this->line('  meili: индекс products удалён и пересоздан');
        } catch (\Throwable $e) {
            $this->warn('  meili: '.$e->getMessage());
        }

        $this->info('Каталог storefront зачищен.');
        $this->info('После перезаливки из 1С выполните: php artisan search:reindex');
        $this->info('После включения/выключения network_catalog перезапустите php-fpm и supervisor-воркеры (rabbitmq:consume-all, rabbitmq:publish-outbox, queue:work) на всех модулях: кэш флага и долгоживущие процессы иначе продолжат работать по-старому.');

        return self::SUCCESS;
    }

    /**
     * banners/stories ссылаются на products необязательным полем link_product_id
     * (ON DELETE SET NULL). TRUNCATE эту FK-экшн не запускает — сбрасываем вручную,
     * чтобы после перезаливки баннер/история не "ожили" со ссылкой на чужой новый
     * товар, случайно получивший переиспользованный id.
     */
    private function nullifyProductReferences(): void
    {
        foreach (['banners', 'stories'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'link_product_id')) {
                DB::table($table)->whereNotNull('link_product_id')->update(['link_product_id' => null]);
            }
        }
    }

    /**
     * store_reviews/promocode_usages ссылаются на orders необязательным полем
     * order_id (ON DELETE SET NULL) — это не заказ-артефакты (отзыв на магазин,
     * лимит использования промокода имеют смысл и без заказа), поэтому не
     * truncate-им таблицу целиком, а сбрасываем ссылку так же, как это сделал бы
     * обычный DELETE (TRUNCATE эту FK-экшн не запускает).
     */
    private function nullifyOrderReferences(): void
    {
        foreach (['store_reviews', 'promocode_usages'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                DB::table($table)->whereNotNull('order_id')->update(['order_id' => null]);
            }
        }
    }

    /**
     * payment_transactions/bonus_transactions: удаляет только строки, реально
     * привязанные к заказу (order_id не пусто). Строки без заказа (bindCard,
     * ручные/офлайн бонусные операции) переживают --with-orders — см. докблок
     * класса и ORDER_LINKED_OPTIONAL_TABLES.
     */
    private function deleteOrderLinkedRows(): void
    {
        foreach (self::ORDER_LINKED_OPTIONAL_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'order_id')) {
                DB::table($table)->whereNotNull('order_id')->delete();
            }
        }
    }

    private function truncateIfExists(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            $this->line("  truncated: {$table}");
        }
    }
}
