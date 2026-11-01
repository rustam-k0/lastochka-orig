<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModuleConfigService
{
    public const PROJECT_MODULES_KEY = 'project_modules';
    public const MARKETPLACE_MODE_KEY = 'marketplace_mode';
    public const FREE_SHIPPING_THRESHOLD_KEY = 'marketplace_free_shipping_threshold';

    public const MODE_STANDARD = 'standard';
    public const MODE_MARKETPLACE = 'marketplace';

    public const CACHE_KEY_MARKETPLACE_MODE = 'module_config:marketplace_mode';
    public const CACHE_KEY_FREE_SHIPPING = 'module_config:marketplace_free_shipping_threshold';
    public const CACHE_KEY_PRODUCT_GROUP_FILTERS = 'module_config:product_group_filters';
    public const PRODUCT_GROUP_FILTERS_KEY = 'product_group_filters';
    public const PREORDERS_KEY = 'preorders';
    public const CACHE_KEY_PREORDERS = 'module_config:preorders';
    public const BRANDS_KEY = 'brands';
    public const CACHE_KEY_BRANDS = 'module_config:brands';
    public const DOCUMENTS_KEY = 'product_documents';
    public const CACHE_KEY_DOCUMENTS = 'module_config:product_documents';
    public const VIDEO_KEY = 'product_video';
    public const CACHE_KEY_VIDEO = 'module_config:product_video';
    public const ISSUE_REPORTS_KEY = 'product_issue_reports';
    public const CACHE_KEY_ISSUE_REPORTS = 'module_config:product_issue_reports';
    public const CONFIGURABLE_PRODUCTS_KEY = 'configurable_products';
    public const CACHE_KEY_CONFIGURABLE_PRODUCTS = 'module_config:configurable_products';
    public const ADDRESS_OBJECTS_KEY = 'addresses.objects';
    public const CACHE_KEY_ADDRESS_OBJECTS = 'module_config:addresses_objects';
    public const CDEK_KEY = 'delivery_cdek';
    public const CACHE_KEY_CDEK = 'module_config:delivery_cdek';
    public const RUSSIAN_POST_KEY = 'delivery_russian_post';
    public const CACHE_KEY_RUSSIAN_POST = 'module_config:delivery_russian_post';
    public const B2B_KEY = 'b2b';
    public const CACHE_KEY_B2B = 'module_config:b2b';
    public const PRODUCT_READY_TIME_KEY = 'product_ready_time';
    public const CACHE_KEY_PRODUCT_READY_TIME = 'module_config:product_ready_time';
    public const HEAVY_ORDERS_KEY = 'heavy_orders';
    public const CACHE_KEY_HEAVY_ORDERS = 'module_config:heavy_orders';
    public const COURIER_ASSIGNMENT_KEY = 'courier_assignment';
    public const CACHE_KEY_COURIER_ASSIGNMENT = 'module_config:courier_assignment';
    public const ASSEMBLY_COST_KEY = 'assembly_cost';
    public const CACHE_KEY_ASSEMBLY_COST = 'module_config:assembly_cost';
    public const ENTITY_CHECKBOXES_KEY = 'entity_checkboxes';
    public const CACHE_KEY_ENTITY_CHECKBOXES = 'module_config:entity_checkboxes';
    public const CACHE_KEY_BANNER_PLACEMENTS = 'module_config:entity_checkboxes:banner_placements';
    public const NETWORK_CATALOG_KEY = 'network_catalog';
    public const CACHE_KEY_NETWORK_CATALOG = 'module_config:network_catalog';
    public const ONEC_CARD_MASTER_KEY = 'onec_card_master';
    public const CACHE_KEY_ONEC_CARD_MASTER = 'module_config:onec_card_master';
    public const ONEC_SHOPS_MASTER_KEY = 'onec_shops_master';
    public const CACHE_KEY_ONEC_SHOPS_MASTER = 'module_config:onec_shops_master';
    public const CHECKOUT_WITHOUT_SLOTS_KEY = 'checkout_without_slots';
    public const CACHE_KEY_CHECKOUT_WITHOUT_SLOTS = 'module_config:checkout_without_slots';
    public const PRICE_TIERS_KEY = 'price_tiers';
    public const CACHE_KEY_PRICE_TIERS = 'module_config:price_tiers';
    public const DELIVERY_PRICE_RANGES_KEY = 'delivery_price_ranges';
    public const CACHE_KEY_DELIVERY_PRICE_RANGES = 'module_config:delivery_price_ranges';

    public const DRAFT_ORDERS_UNTIL_PAYMENT_KEY = 'draft_orders_until_payment';
    public const CACHE_KEY_DRAFT_ORDERS_UNTIL_PAYMENT = 'module_config:draft_orders_until_payment';

    private const DRAFT_ORDER_TTL_MINUTES_KEY = 'draft_order_ttl_minutes';
    private const CACHE_KEY_DRAFT_ORDER_TTL_MINUTES = 'module_config:draft_order_ttl_minutes';
    private const DRAFT_ORDER_TTL_MINUTES_DEFAULT = 15;

    private const COLLECTOR_PAYMENT_KEY = 'collector_payment';
    private const CACHE_KEY_COLLECTOR_PAYMENT = 'module_config:collector_payment';
    private const SURCHARGE_WAIT_HOURS_KEY = 'surcharge_wait_hours';
    private const CACHE_KEY_SURCHARGE_WAIT_HOURS = 'module_config:surcharge_wait_hours';
    private const SURCHARGE_WAIT_HOURS_DEFAULT = 24;

    /**
     * Значения, уже прочитанные этим инстансом (= per-request при
     * singleton-регистрации). Ресурсы зовут is*Enabled() на каждый товар
     * листинга — без мемоизации это тысячи запросов к database-кешу
     * на один HTTP-запрос (GlitchTip STOREFRONT_BACK-1E).
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * Cache::remember с per-instance мемоизацией. Ошибки Cache не
     * мемоизируются — fail-safe default отдаёт catch вызывающего метода,
     * следующий вызов попробует прочитать кеш снова.
     */
    private function memoizedRemember(string $cacheKey, callable $resolver): mixed
    {
        if (! array_key_exists($cacheKey, $this->memo)) {
            $this->memo[$cacheKey] = Cache::remember($cacheKey, now()->addMinutes(10), $resolver);
        }

        return $this->memo[$cacheKey];
    }

    /**
     * Вернуть текущий режим работы магазина.
     *
     * Fail-safe: любые исключения/недоступная БД/пустой кеш → 'standard'.
     */
    public function getMarketplaceMode(): string
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_MARKETPLACE_MODE,
                fn () => $this->readMarketplaceModeFromDb()
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read marketplace_mode, defaulting to standard', [
                'error' => $e->getMessage(),
            ]);

            return self::MODE_STANDARD;
        }
    }

    public function isMarketplaceMode(): bool
    {
        return $this->getMarketplaceMode() === self::MODE_MARKETPLACE;
    }

    /**
     * Минимальная сумма корзины для бесплатной доставки в marketplace режиме.
     * null = порог не сконфигурирован.
     */
    public function getMarketplaceFreeShippingThreshold(): ?float
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_FREE_SHIPPING,
                function (): ?float {
                    $raw = StorefrontSetting::getValue(self::FREE_SHIPPING_THRESHOLD_KEY, null);

                    return $raw !== null ? (float) $raw : null;
                }
            );
        } catch (Throwable $e) {
            Log::warning('Failed to read marketplace_free_shipping_threshold', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Проверить, включены ли фильтры комплексных решений.
     */
    public function isProductGroupFiltersEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_PRODUCT_GROUP_FILTERS,
                fn () => $this->readBooleanModule(self::PRODUCT_GROUP_FILTERS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить, включены ли заявки "Под заказ".
     */
    public function isPreordersEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_PREORDERS,
                fn () => $this->readBooleanModule(self::PREORDERS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить, включены ли бренды.
     */
    public function isBrandsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_BRANDS,
                fn () => $this->readBooleanModule(self::BRANDS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить, включены ли документы товаров.
     */
    public function isDocumentsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_DOCUMENTS,
                fn () => $this->readBooleanModule(self::DOCUMENTS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить, включено ли видео товаров.
     */
    public function isVideoEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_VIDEO,
                fn () => $this->readBooleanModule(self::VIDEO_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Проверить, включены ли заявки о неточностях в товарах.
     */
    public function isIssueReportsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_ISSUE_REPORTS,
                fn () => $this->readBooleanModule(self::ISSUE_REPORTS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включены ли вариативные товары.
     */
    public function isConfigurableProductsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_CONFIGURABLE_PRODUCTS,
                fn () => $this->readBooleanModule(self::CONFIGURABLE_PRODUCTS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль доставки СДЭК.
     *
     * Fail-safe: возвращает true если модуль не настроен (не блокирует).
     */
    public function isCdekEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_CDEK,
                fn () => $this->readBooleanModuleWithDefault(self::CDEK_KEY, true)
            );
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Включён ли модуль доставки Почтой России.
     *
     * Fail-safe: возвращает true если модуль не настроен (не блокирует).
     */
    public function isRussianPostEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_RUSSIAN_POST,
                fn () => $this->readBooleanModuleWithDefault(self::RUSSIAN_POST_KEY, true)
            );
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Включён ли модуль B2B.
     *
     * Fail-safe: любые ошибки → false (B2B-логика отключена).
     */
    public function isB2bEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_B2B,
                fn () => $this->readBooleanModule(self::B2B_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль адресных объектов (ФИАС/КЛАДР).
     */
    public function isAddressObjectsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_ADDRESS_OBJECTS,
                fn () => $this->readBooleanModule(self::ADDRESS_OBJECTS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Время готовности товаров».
     */
    public function isProductReadyTimeEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_PRODUCT_READY_TIME,
                fn () => $this->readBooleanModule(self::PRODUCT_READY_TIME_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Тяжёлые заказы» (heavy_orders в project_modules).
     */
    public function isHeavyOrdersEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_HEAVY_ORDERS,
                fn () => $this->readBooleanModule(self::HEAVY_ORDERS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли сетевой каталог (network_catalog в project_modules):
     * одна карточка товара на сеть, цены/остатки пер-магазин в store_products.
     */
    public function isNetworkCatalogEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_NETWORK_CATALOG,
                fn () => $this->readBooleanModule(self::NETWORK_CATALOG_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * «1С ведёт карточку товара» (onec_card_master в project_modules, MID-305):
     * события 1С обновляют sku, дописывают характеристики, не затирают
     * отсутствующие поля; фото приходят от курьерки.
     *
     * Без memoizedRemember: флаг читают долгоживущие консьюмеры товаров, а
     * переключение приходит событием admin_settings, которое обрабатывает
     * другой процесс. Его invalidateCache() делает Cache::forget в общем кеше
     * (CACHE_STORE=database), но memo чужого экземпляра не сбрасывает:
     * консьюмер товаров не видел бы переключения до рестарта.
     */
    public function isOneCCardMasterEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_ONEC_CARD_MASTER,
                now()->addMinutes(10),
                fn () => $this->readBooleanModule(self::ONEC_CARD_MASTER_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * «1С ведёт магазины и сотрудников» (onec_shops_master в project_modules, MID-306):
     * события магазина от 1С не затирают отсутствующие поля, тип всегда «Магазин»,
     * порог тяжёлого заказа принимается в килограммах.
     *
     * Без memoizedRemember: флаг читает долгоживущий консьюмер магазинов, а
     * переключение обрабатывает консьюмер настроек в другом процессе (см.
     * isOneCCardMasterEnabled()).
     */
    public function isOneCShopsMasterEnabled(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_ONEC_SHOPS_MASTER,
                now()->addMinutes(10),
                fn () => $this->readBooleanModule(self::ONEC_SHOPS_MASTER_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Назначение курьера сборщиком»
     * (courier_assignment в project_modules).
     */
    public function isCourierAssignmentEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_COURIER_ASSIGNMENT,
                fn () => $this->readBooleanModule(self::COURIER_ASSIGNMENT_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Стоимость сборки» (assembly_cost в project_modules).
     */
    public function isAssemblyCostEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_ASSEMBLY_COST,
                fn () => $this->readBooleanModule(self::ASSEMBLY_COST_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Цены от количества» (price_tiers в project_modules, MID-308):
     * ступенчатая цена товара по количеству в позиции. Вариант для HTTP-запросов:
     * ресурсы и резолвер цены зовут его на каждый товар выдачи.
     *
     * Fail-safe: любая ошибка чтения = выключено (прежнее поведение платформы).
     */
    public function isPriceTiersEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_PRICE_TIERS,
                fn () => $this->readBooleanModule(self::PRICE_TIERS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Тот же флаг для долгоживущих консьюмеров товаров (приём лесенки из
     * событий цен). Без memoizedRemember: переключение приходит событием
     * admin_settings, которое обрабатывает другой процесс; его invalidateCache()
     * чистит общий кеш, но memo чужого экземпляра не сбрасывает
     * (см. isOneCCardMasterEnabled()).
     */
    public function isPriceTiersEnabledForConsumer(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_PRICE_TIERS,
                now()->addMinutes(10),
                fn () => $this->readBooleanModule(self::PRICE_TIERS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Включён ли модуль «Диапазоны стоимости доставки» (delivery_price_ranges
     * в project_modules, MID-280): ступени доставки зоны/магазина в лесенке
     * {@see \App\Services\Delivery\ZoneDeliveryLadder} и в выдаче API
     * ({@see \App\Http\Resources\Api\V1\DeliveryPriceRanges}). Снимок заказа
     * (delivery_price_snapshot) флаг не читает — заказ, оформленный при
     * включённом модуле, досчитывается по своему снимку и после выключения.
     *
     * Fail-safe: любая ошибка чтения = выключено (поведение платформы до фичи).
     */
    public function isDeliveryPriceRangesEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_DELIVERY_PRICE_RANGES,
                fn () => $this->readBooleanModule(self::DELIVERY_PRICE_RANGES_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Тот же флаг для долгоживущих консьюмеров (обработка событий магазина).
     * Без memoizedRemember: переключение приходит событием admin_settings,
     * которое обрабатывает другой процесс; его invalidateCache() чистит общий
     * кеш, но memo чужого экземпляра не сбрасывает (см. isOneCCardMasterEnabled()).
     */
    public function isDeliveryPriceRangesEnabledForConsumer(): bool
    {
        try {
            return (bool) Cache::remember(
                self::CACHE_KEY_DELIVERY_PRICE_RANGES,
                now()->addMinutes(10),
                fn () => $this->readBooleanModule(self::DELIVERY_PRICE_RANGES_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Модуль «Оформление заказа без слотов» (MID-256): клиент может оформить
     * заказ без deliverySlotId — доставка «в ближайшее время». Слот при этом
     * остаётся опциональным (переданный слот учитывается как раньше).
     */
    public function isCheckoutWithoutSlotsEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_CHECKOUT_WITHOUT_SLOTS,
                fn () => $this->readBooleanModule(self::CHECKOUT_WITHOUT_SLOTS_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Модуль «Заказ только после оплаты»: при онлайн-оплате новой картой
     * (redirect-флоу ЮKassa) заказ создаётся черновиком и становится
     * настоящим только после подтверждения холда.
     *
     * Выключен — прежнее поведение: заказ сразу боевой, корзина очищается
     * в момент оформления, событие уезжает в курьерку до оплаты.
     */
    public function isDraftOrdersUntilPaymentEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_DRAFT_ORDERS_UNTIL_PAYMENT,
                fn () => $this->readBooleanModule(self::DRAFT_ORDERS_UNTIL_PAYMENT_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Сколько минут черновик ждёт подтверждения оплаты (настройка
     * draft_order_ttl_minutes из АП). Некорректные значения → дефолт 15 минут.
     *
     * Дольше держать нельзя: всё это время товар списан с остатка и слот занят.
     */
    public function getDraftOrderTtlMinutes(): int
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_DRAFT_ORDER_TTL_MINUTES,
                function (): int {
                    $raw = StorefrontSetting::getValue(self::DRAFT_ORDER_TTL_MINUTES_KEY);
                    $minutes = (int) $raw;

                    return $minutes >= 1 ? $minutes : self::DRAFT_ORDER_TTL_MINUTES_DEFAULT;
                }
            );
        } catch (Throwable $e) {
            return self::DRAFT_ORDER_TTL_MINUTES_DEFAULT;
        }
    }

    /**
     * Модуль «Списание оплаты сборщиком»: сборщик синхронно списывает
     * итоговую стоимость заказа при завершении сборки.
     */
    public function isCollectorPaymentEnabled(): bool
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_COLLECTOR_PAYMENT,
                fn () => $this->readBooleanModule(self::COLLECTOR_PAYMENT_KEY)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Срок ожидания доплаты в часах (настройка surcharge_wait_hours из АП).
     * Некорректные/пустые значения → дефолт 24 часа.
     */
    public function getSurchargeWaitHours(): int
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_SURCHARGE_WAIT_HOURS,
                function (): int {
                    $raw = StorefrontSetting::getValue(self::SURCHARGE_WAIT_HOURS_KEY);
                    $hours = (int) $raw;

                    return $hours >= 1 ? $hours : self::SURCHARGE_WAIT_HOURS_DEFAULT;
                }
            );
        } catch (Throwable $e) {
            return self::SURCHARGE_WAIT_HOURS_DEFAULT;
        }
    }

    /**
     * Кастомные чекбоксы товаров, выводимые флаг-фильтрами витрины.
     *
     * Определения приходят из admin_back через admin_settings-событие
     * (storefront_settings.key = entity_checkboxes). Берутся только элементы
     * Product с custom && enabled && filterable и валидным слагом — слаг далее
     * интерполируется в JSON-path запроса, поэтому regex-фильтр обязателен.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function productFlagFilters(): array
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_ENTITY_CHECKBOXES,
                fn (): array => $this->customCheckboxes('Product', requireFilterable: true),
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Места показа баннеров: кастомные чекбоксы сущности MerchantBanner.
     *
     * Тот же источник, что и у productFlagFilters() (entity_checkboxes из
     * admin_back). Берутся элементы MerchantBanner с custom && enabled и
     * валидным слагом — слаг интерполируется в JSON-path запроса
     * (`Banner::scopeForPlacement`), поэтому regex-фильтр обязателен.
     * Признак filterable у баннеров не используется: каждый включённый
     * тумблер — это место показа, по которому витрина умеет фильтровать.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function bannerPlacements(): array
    {
        try {
            return $this->memoizedRemember(
                self::CACHE_KEY_BANNER_PLACEMENTS,
                fn (): array => $this->customCheckboxes('MerchantBanner', requireFilterable: false),
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Кастомные включённые чекбоксы сущности из настройки entity_checkboxes.
     *
     * @return array<int, array{field: string, label: string}>
     */
    private function customCheckboxes(string $entity, bool $requireFilterable): array
    {
        $config = StorefrontSetting::getValue(self::ENTITY_CHECKBOXES_KEY, null);
        if (! is_array($config) || ! is_array($config[$entity] ?? null)) {
            return [];
        }

        $result = [];
        foreach ($config[$entity] as $item) {
            if (! is_array($item)
                || ! ($item['custom'] ?? false)
                || ! ($item['enabled'] ?? false)
                || ($requireFilterable && ! ($item['filterable'] ?? false))
            ) {
                continue;
            }
            $field = (string) ($item['field'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $field)) {
                continue;
            }
            $result[] = ['field' => $field, 'label' => (string) ($item['label'] ?? $field)];
        }

        return $result;
    }

    public function invalidateCache(): void
    {
        $this->memo = [];

        Cache::forget(self::CACHE_KEY_MARKETPLACE_MODE);
        Cache::forget(self::CACHE_KEY_FREE_SHIPPING);
        Cache::forget(self::CACHE_KEY_PRODUCT_GROUP_FILTERS);
        Cache::forget(self::CACHE_KEY_PREORDERS);
        Cache::forget(self::CACHE_KEY_BRANDS);
        Cache::forget(self::CACHE_KEY_DOCUMENTS);
        Cache::forget(self::CACHE_KEY_VIDEO);
        Cache::forget(self::CACHE_KEY_ISSUE_REPORTS);
        Cache::forget(self::CACHE_KEY_CONFIGURABLE_PRODUCTS);
        Cache::forget(self::CACHE_KEY_ADDRESS_OBJECTS);
        Cache::forget(self::CACHE_KEY_CDEK);
        Cache::forget(self::CACHE_KEY_RUSSIAN_POST);
        Cache::forget(self::CACHE_KEY_B2B);
        Cache::forget(self::CACHE_KEY_PRODUCT_READY_TIME);
        Cache::forget(self::CACHE_KEY_HEAVY_ORDERS);
        Cache::forget(self::CACHE_KEY_NETWORK_CATALOG);
        Cache::forget(self::CACHE_KEY_ONEC_CARD_MASTER);
        Cache::forget(self::CACHE_KEY_ONEC_SHOPS_MASTER);
        Cache::forget(self::CACHE_KEY_CHECKOUT_WITHOUT_SLOTS);
        Cache::forget(self::CACHE_KEY_COURIER_ASSIGNMENT);
        Cache::forget(self::CACHE_KEY_ASSEMBLY_COST);
        Cache::forget(self::CACHE_KEY_PRICE_TIERS);
        Cache::forget(self::CACHE_KEY_DELIVERY_PRICE_RANGES);
        Cache::forget(self::CACHE_KEY_ENTITY_CHECKBOXES);
        Cache::forget(self::CACHE_KEY_BANNER_PLACEMENTS);
        Cache::forget(self::CACHE_KEY_COLLECTOR_PAYMENT);
        Cache::forget(self::CACHE_KEY_SURCHARGE_WAIT_HOURS);
        Cache::forget(self::CACHE_KEY_DRAFT_ORDERS_UNTIL_PAYMENT);
        Cache::forget(self::CACHE_KEY_DRAFT_ORDER_TTL_MINUTES);
    }

    /**
     * Включён ли произвольный boolean-модуль project_modules.
     *
     * Обёртка для мест, где ключ модуля приходит из конфига (например,
     * фильтрация OpenAPI-документации), а не зафиксирован в коде.
     */
    public function isModuleEnabled(string $moduleKey, bool $default = false): bool
    {
        return $this->readBooleanModuleWithDefault($moduleKey, $default);
    }

    /**
     * Читает boolean-модуль из project_modules блоба.
     */
    private function readBooleanModule(string $moduleKey): bool
    {
        $projectModules = StorefrontSetting::getValue(self::PROJECT_MODULES_KEY, null);

        if (! is_array($projectModules)) {
            return false;
        }

        $module = $projectModules[$moduleKey] ?? null;

        if (! is_array($module)) {
            return false;
        }

        return (bool) ($module['enabled'] ?? false);
    }

    /**
     * Читает boolean-модуль из project_modules блоба с явным дефолтом.
     *
     * Если модуль не настроен в project_modules — возвращает $default.
     */
    private function readBooleanModuleWithDefault(string $moduleKey, bool $default): bool
    {
        $projectModules = StorefrontSetting::getValue(self::PROJECT_MODULES_KEY, null);

        if (! is_array($projectModules)) {
            return $default;
        }

        $module = $projectModules[$moduleKey] ?? null;

        if (! is_array($module)) {
            return $default;
        }

        return (bool) ($module['enabled'] ?? $default);
    }

    /**
     * Читает marketplace_mode из project_modules блоба.
     */
    private function readMarketplaceModeFromDb(): string
    {
        $projectModules = StorefrontSetting::getValue(self::PROJECT_MODULES_KEY, null);

        if (! is_array($projectModules)) {
            return self::MODE_STANDARD;
        }

        $module = $projectModules[self::MARKETPLACE_MODE_KEY] ?? null;

        if (! is_array($module)) {
            return self::MODE_STANDARD;
        }

        if (($module['type'] ?? null) === 'select') {
            $value = $module['value'] ?? null;

            return in_array($value, [self::MODE_STANDARD, self::MODE_MARKETPLACE], true)
                ? $value
                : self::MODE_STANDARD;
        }

        return self::MODE_STANDARD;
    }
}
