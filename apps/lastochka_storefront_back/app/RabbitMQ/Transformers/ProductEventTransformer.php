<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\MeasurementUnit;
use App\Enums\UnitType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Services\Images\ImageThumbnails;
use App\Models\ProductPriceTier;
use App\Models\StoreProduct;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\RabbitMQ\Support\NetworkCatalog;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Pricing\ProductTierLadderProvider;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use App\Support\NdsRate;
use App\Support\ProductCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ProductEventTransformer extends BaseEventTransformer
{
    private readonly SearchService $searchService;

    /**
     * SearchService инжектится контейнером (EventConsumer резолвит трансформер
     * через app()), но допускаем и прямую инстанциацию (`new`) — например в
     * тестах: тогда резолвим из контейнера лениво.
     */
    public function __construct(?SearchService $searchService = null)
    {
        $this->searchService = $searchService ?? app(SearchService::class);
    }

    /**
     * Источник событий RetailCRM-бриджа. Остаток (quantity) из этого источника
     * НЕ принимаем — складские остатки ведёт только 1С. RetailCRM шлёт по части
     * товаров «безлимит» (100000), что прячет реальный нулевой остаток из 1С.
     */
    private const RETAILCRM_SOURCE = 'retailbridge';

    /**
     * Крупная базовая единица → наименьшая, в которой storefront хранит
     * количество (корзина, шаг продажи, остаток). Товар с шагом продажи
     * всегда живёт в наименьшей единице — см. reconcileQuantumUnits().
     */
    private const LARGE_TO_SMALL_UNIT = [
        'kilogram' => 'gram',
        'liter' => 'milliliter',
    ];

    /**
     * Коды атрибутов КБЖУ для извлечения в основную модель.
     */
    private const NUTRITION_CODES = ['calories', 'proteins', 'fats', 'carbs'];

    /**
     * Маппинг русских названий КБЖУ на коды (fallback при отсутствии code).
     */
    private const NUTRITION_NAME_MAP = [
        'калорийность' => 'calories',
        'калории' => 'calories',
        'ккал' => 'calories',
        'энергетическая ценность' => 'calories',
        'белки' => 'proteins',
        'белок' => 'proteins',
        'жиры' => 'fats',
        'жир' => 'fats',
        'углеводы' => 'carbs',
    ];

    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'shop_code', 'source'];
    }

    /**
     * Валидация события с учётом типа.
     * Для stock_changed требуются только базовые поля + quantity.
     * Для остальных событий требуются title и price.
     *
     * Сетевой каталог: карточка (created/updated) глобальна — у неё нет ни
     * магазина (владелец — якорный store), ни цены (цены/остатки приезжают
     * отдельными пер-магазинными событиями в store_products). Поэтому в
     * сетевом режиме shop_code и price из обязательных исключаются.
     * Флаг выключен → валидация байт-в-байт легаси.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data): bool
    {
        $eventType = $data['event_type'] ?? '';
        $isCardEvent = ! in_array($eventType, ['stock_changed', 'stock_updated', 'price_updated'], true);
        $isNetworkCard = $isCardEvent && NetworkCatalog::enabled();

        // Базовая валидация
        $requiredFields = $isNetworkCard
            ? array_values(array_diff($this->requiredFields(), ['shop_code']))
            : $this->requiredFields();

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                return false;
            }
        }

        // Для stock_changed и stock_updated нужен только quantity
        if (in_array($eventType, ['stock_changed', 'stock_updated'])) {
            return isset($data['quantity']);
        }

        // Для price_updated нужна только price (title не приходит в custom payload)
        if ($eventType === 'price_updated') {
            return isset($data['price']);
        }

        // Для остальных событий (created/updated) нужны title и price
        return $isNetworkCard
            ? isset($data['title'])
            : isset($data['title'], $data['price']);
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $product, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Product.
     *
     * ВАЖНО: Инверсия цен!
     * - В событии price — базовая цена (рубли с копейками)
     * - В событии discount_price — цена со скидкой (рубли с копейками)
     * - В модели price — текущая цена (что платит покупатель), decimal(10,2)
     * - В модели price_old — старая цена (перечёркнутая), decimal(10,2)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        if (NetworkCatalog::enabled()) {
            // Сетевой режим: карточка глобальна, владелец — якорный store.
            // shop_code события (легаси-поток) для карточки игнорируется:
            // цены/остатки магазинов едут отдельными событиями в store_products.
            $storeId = NetworkCatalog::anchorStoreId();

            if ($storeId === null) {
                throw new \RuntimeException(
                    'Cannot process network product card: stores are not synced yet. Event will be retried.'
                );
            }
        } else {
            $storeId = $this->resolveOrCreateStoreByCode($data['shop_code'], $data['source']);

            if ($storeId === null) {
                throw new \RuntimeException(sprintf(
                    'Cannot process product event: no store found for shop_code=%s (source=%s). Event will be retried.',
                    $data['shop_code'] ?? 'null',
                    $data['source'] ?? 'null',
                ));
            }
        }

        // Инверсия цен (хранение в decimal — рубли с копейками).
        // Сетевая карточка приезжает без price — цена магазина ложится
        // в store_products отдельным событием, здесь база 0.
        $basePrice = round((float) ($data['price'] ?? 0), 2);
        $discountPrice = isset($data['discount_price'])
            ? round((float) $data['discount_price'], 2)
            : null;

        $attributes = [
            'store_id' => $storeId,
            'source' => $data['source'],
            // category_ids НЕ задаём здесь: связи управляются linkCategories()
            // из afterCreate/afterUpdate — иначе partial update без category_ids
            // в payload обнуляет локальные связи вместо их сохранения.
            'sku' => $data['sku'] ?? null,
            'barcodes' => $data['barcodes'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'price' => $discountPrice ?? $basePrice,
            'price_old' => $discountPrice ? $basePrice : null,
            'unit_type' => $this->mapUnitType($data['unit_type'] ?? 'piece'),
            'weight' => isset($data['weight']) ? (int) $data['weight'] : null,
            'vat_rate' => isset($data['nds']) ? (int) $data['nds'] : null,
            'is_configurable' => (bool) ($data['is_configurable'] ?? false),
            'is_default_variant' => (bool) ($data['is_default_variant'] ?? false),
        ];

        // Soft-delete при deleted_at != null. Явный null в событии → восстановление.
        if (array_key_exists('deleted_at', $data)) {
            $attributes['deleted_at'] = $data['deleted_at'] !== null
                ? \Carbon\Carbon::parse($data['deleted_at'])
                : null;
        }

        // is_active — только если явно передан в событии.
        // Без этой проверки каждое обновление из 1С (где active всегда true)
        // отменяет деактивацию товара из админки storefront.
        if (isset($data['active'])) {
            $attributes['is_active'] = $data['active'];
        }

        // «Подаётся тёплым» задаётся в админке; 1С/RetailCRM ключ не шлют —
        // conditional set, чтобы их партиальные события не затирали флаг.
        if (array_key_exists('served_warm', $data)) {
            $attributes['served_warm'] = (bool) $data['served_warm'];
        }

        if (array_key_exists('pickup_only', $data)) {
            $attributes['pickup_only'] = (bool) $data['pickup_only'];
        }

        if (array_key_exists('adult_only', $data)) {
            $attributes['adult_only'] = (bool) $data['adult_only'];
        }

        // Маркируемый товар (Честный знак, MID-266) — признак приходит из
        // курьерки (в неё — из 1С); только при наличии ключа.
        if (array_key_exists('is_marked', $data)) {
            $attributes['is_marked'] = (bool) $data['is_marked'];
        }

        // Остальные бизнес-флаги админки — та же защита от партиальных событий:
        // раньше безусловный `?? false` сбрасывал их при каждом импорте каталога из 1С.
        if (array_key_exists('is_culinary', $data)) {
            $attributes['is_culinary'] = (bool) $data['is_culinary'];
        }
        if (array_key_exists('sugar_free', $data)) {
            $attributes['sugar_free'] = (bool) $data['sugar_free'];
        }
        if (array_key_exists('on_search_page', $data)) {
            $attributes['on_search_page'] = (bool) $data['on_search_page'];
        }

        // Галочки «Новинка» / «Успей купить» (автогруппы is_new / is_hurry_up):
        // только при наличии ключа — частичные события из 1С их не сбрасывают.
        if (array_key_exists('is_new', $data)) {
            $attributes['is_new'] = (bool) $data['is_new'];
        }
        if (array_key_exists('is_hurry_up', $data)) {
            $attributes['is_hurry_up'] = (bool) $data['is_hurry_up'];
        }

        // Режим учёта остатка (LASTOCHKI-JAMI-11207). Приходит из admin_back toEvent
        // (Task 9) в том же полном событии product.updated. Conditional set:
        // партиальные/чужие события без ключа не затирают локальное значение.
        if (array_key_exists('stock_mode', $data)) {
            $attributes['stock_mode'] = $data['stock_mode'] ?: 'normal';
        }
        if (array_key_exists('fixed_stock', $data)) {
            $attributes['fixed_stock'] = $data['fixed_stock'] !== null ? (int) $data['fixed_stock'] : null;
        }

        // Остаток (quantity) НЕ принимаем из RetailCRM-бриджа — складские остатки
        // ведёт только 1С. RetailCRM по части товаров шлёт «безлимит» (100000),
        // что перетирает реальный нулевой остаток из 1С и оставляет товар в выдаче.
        // Для прочих источников — прежнее поведение (отсутствие quantity → 0).
        //
        // Для режимов ignore/fixed остатком управляет сайт, а не 1С — входящий
        // quantity из события игнорируем. Эффективный режим берём из самого
        // события (admin toEvent всегда шлёт stock_mode), fallback — 'normal'.
        $incomingMode = $data['stock_mode'] ?? 'normal';
        $ignoreIncomingQuantity = in_array($incomingMode, ['ignore', 'fixed'], true);
        if (! $ignoreIncomingQuantity && ($data['source'] ?? null) !== self::RETAILCRM_SOURCE) {
            // Остаток весового товара приходит в кг, хранится в граммах (см. toStoredQuantity).
            $attributes['quantity'] = $this->toStoredQuantity($data['quantity'] ?? 0, $this->mapUnitType($data['unit_type'] ?? 'piece'));
        }

        // measurement_unit и quantity_step — только если переданы в событии.
        // RetailCRM bridge не отправляет эти поля, и без этой проверки
        // затирает корректные значения от admin_back на piece/1.
        if (isset($data['measurement_unit'])) {
            $attributes['measurement_unit'] = $this->mapMeasurementUnit($data['measurement_unit'], $data['unit_type'] ?? 'piece');
        }
        if (isset($data['quantity_step'])) {
            $attributes['quantity_step'] = (int) $data['quantity_step'];
        }

        // Код товара из 1С (реквизит Код) — отдельное от sku поле, для поиска
        // по весовому штрихкоду. Conditional set: партиал/чужие события
        // (retailbridge) без ключа `code` не затирают значение.
        // Нормализация кода: 1С отдаёт числовые коды с неразрывным пробелом между
        // разрядами («172 484»). У sku нормализации НЕТ намеренно — на
        // (store_id, sku) висит UNIQUE, схлопывание столкнуло бы разные товары.
        if (array_key_exists('code', $data)) {
            $attributes['code'] = ProductCode::normalize($data['code']);
        }

        // PLU весового товара из 1С — по нему ищется весовой штрихкод. Conditional
        // set по тем же причинам, что и code; ведущие нули значимы для показа
        // («00030» как на ценнике), поиск к ним толерантен.
        if (array_key_exists('plu', $data)) {
            $attributes['plu'] = ProductCode::normalize($data['plu']);
        }

        // Дополнительная информация (произвольный текст, напр. гарантия
        // производителя). Conditional set: партиальные события (1С,
        // retailbridge) без ключа не затирают значение; явный null очищает.
        if (array_key_exists('additional_info', $data)) {
            $attributes['additional_info'] = $data['additional_info'];
        }

        // Время готовности товара (LASTOCHKI-JAMI-11648). Conditional set:
        // партиал-события (1С, retailbridge) без ключа не затирают значение.
        if (array_key_exists('ready_fixed_time', $data)) {
            $attributes['ready_fixed_time'] = $data['ready_fixed_time'];
        }

        // Динамические чекбоксы: conditional set — партиальные/чужие события
        // (1С, retailbridge) без ключа не затирают локальные значения.
        if (array_key_exists('custom_checkboxes', $data)) {
            $attributes['custom_checkboxes'] = is_array($data['custom_checkboxes'])
                ? array_map(fn ($v) => (bool) $v, $data['custom_checkboxes'])
                : [];
        }

        // Ставка НДС процентом из 1С (MID-305): приоритетнее кода `nds`,
        // нераспознанная ставка НДС товара не трогает.
        if (array_key_exists('nds_rate', $data)) {
            $vatCode = NdsRate::toVatCode($data['nds_rate']);

            if ($vatCode === false) {
                unset($attributes['vat_rate']);
                Log::warning('ProductEventTransformer: unrecognized nds_rate', [
                    'external_id' => $data['id'] ?? null,
                    'nds_rate' => $data['nds_rate'],
                ]);
            } else {
                $attributes['vat_rate'] = $vatCode;
            }
        }

        // Признак алкогольной продукции из 1С (MID-305): «18+» и «только самовывоз».
        // null снимает оба флага; нераспознанное значение («Да», «Y») флаги не
        // трогает: опечатка в 1С не должна снимать «18+» с алкоголя.
        if (array_key_exists('is_alcohol', $data)) {
            $isAlcohol = $data['is_alcohol'] === null
                ? false
                : filter_var($data['is_alcohol'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($isAlcohol === null) {
                Log::warning('ProductEventTransformer: unrecognized is_alcohol', [
                    'external_id' => $data['id'] ?? null,
                    'is_alcohol' => $data['is_alcohol'],
                ]);
            } else {
                $attributes['adult_only'] = $isAlcohol;
                $attributes['pickup_only'] = $isAlcohol;
            }
        }

        // Карточку ведёт 1С (MID-305): правило контракта «нет ключа — не трогать».
        // Без флага эти поля по-прежнему обнуляются при отсутствии ключа.
        if ($this->isOneCCardMasterEvent($data)) {
            foreach (['description', 'meta_title', 'meta_description', 'meta_keywords', 'barcodes', 'weight'] as $key) {
                if (! array_key_exists($key, $data)) {
                    unset($attributes[$key]);
                }
            }

            if (! array_key_exists('nds', $data) && ! array_key_exists('nds_rate', $data)) {
                unset($attributes['vat_rate']);
            }
        }

        return $attributes;
    }

    /**
     * Перед созданием — проверить что магазин существует.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        if (! $transformedData['store_id']) {
            Log::warning('Product event: store not found', [
                // Сетевая карточка приезжает без shop_code — ключа может не быть.
                'shop_code' => $eventData['shop_code'] ?? null,
                'product_external_id' => $eventData['id'],
            ]);

            return null;
        }

        if (! empty($transformedData['sku'])) {
            $transformedData['sku'] = $this->resolveUniqueSku(
                (string) $transformedData['sku'],
                (int) $transformedData['store_id'],
                (string) ($eventData['id'] ?? ''),
            );
        }

        // Товар, приехавший сразу в режиме fixed, начинает день с полным лимитом:
        // входящий складской остаток для fixed игнорируется (см. fromEvent), без
        // этого он был бы 0 до ближайшего stock:reset-fixed в 00:05.
        if (($transformedData['stock_mode'] ?? 'normal') === 'fixed'
            && ($transformedData['fixed_stock'] ?? null) !== null) {
            $transformedData['quantity'] = (int) $transformedData['fixed_stock'];
        }

        return $transformedData;
    }

    /**
     * Если sku уже занят в магазине — собрать синтетический sku формата
     * `1C-{sku}-{external_id_short}` (с counter-suffix'ом при повторной коллизии).
     * Без external_id защита не применяется (нечем дезамбигуировать).
     */
    private function resolveUniqueSku(string $sku, int $storeId, string $externalId, ?int $exceptProductId = null): string
    {
        $taken = fn (string $candidate): bool => Product::withTrashed()
            ->where('store_id', $storeId)
            ->where('sku', $candidate)
            ->when($exceptProductId !== null, fn ($query) => $query->where('id', '!=', $exceptProductId))
            ->exists();

        if (! $taken($sku)) {
            return $sku;
        }

        if ($externalId === '') {
            return $sku;
        }

        $shortHash = substr((string) preg_replace('/[^a-zA-Z0-9]/', '', $externalId), 0, 8);
        if ($shortHash === '') {
            return $sku;
        }

        $base = sprintf('1C-%s-%s', $sku, $shortHash);
        $candidate = $base;
        $counter = 0;
        while ($taken($candidate)) {
            $counter++;
            $candidate = $base.'-'.$counter;
            if ($counter > 100) {
                Log::error('Failed to generate unique sku after 100 attempts', [
                    'base' => $base,
                    'external_id' => $externalId,
                    'store_id' => $storeId,
                ]);
                break;
            }
        }

        Log::info('SKU collision in beforeCreate — assigned synthetic sku', [
            'original_sku' => $sku,
            'synthetic_sku' => $candidate,
            'store_id' => $storeId,
            'external_id' => $externalId,
        ]);

        return $candidate;
    }

    /**
     * Событие 1С при включённом «1С ведёт карточку товара» (MID-305).
     * События курьерки (event_source = admin) идут прежним путём.
     */
    private function isOneCCardMasterEvent(array $data): bool
    {
        return ($data['source'] ?? null) === '1c'
            && ($data['event_source'] ?? null) === '1c'
            && app(ModuleConfigService::class)->isOneCCardMasterEnabled();
    }

    /**
     * sku из 1С (MID-305): текущий sku, «принадлежащий» присланному (он сам или
     * синтетика 1C-{sku}-{hash8}), не трогаем; иначе подбираем свободный в магазине.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>
     */
    private function applyOneCSku(Model $product, array $eventData, array $transformedData): array
    {
        $sku = (string) ($transformedData['sku'] ?? '');
        $externalId = (string) ($eventData['id'] ?? '');

        if ($sku === '' || $this->skuBelongsTo((string) $product->sku, $sku, $externalId)) {
            unset($transformedData['sku']);

            return $transformedData;
        }

        $transformedData['sku'] = $this->resolveUniqueSku($sku, (int) $product->store_id, $externalId, (int) $product->id);

        return $transformedData;
    }

    private function skuBelongsTo(string $currentSku, string $sku, string $externalId): bool
    {
        if ($currentSku === $sku) {
            return true;
        }

        $shortHash = mb_substr((string) preg_replace('/[^a-zA-Z0-9]/', '', $externalId), 0, 8);
        if ($shortHash === '') {
            return false;
        }

        $synthetic = preg_quote(sprintf('1C-%s-%s', $sku, $shortHash), '/');

        return preg_match('/^'.$synthetic.'(-\d+)?$/u', $currentSku) === 1;
    }

    /**
     * После создания — связать с категорией, скачать изображение, обработать атрибуты.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $product, array $eventData): void
    {
        $this->linkCategories($product, $eventData);
        $this->linkBrand($product, $eventData);

        // Карточку ведёт 1С (MID-305): фото приходят от курьерки её событием.
        $photosFromAdmin = $this->isOneCCardMasterEvent($eventData);
        if (! $photosFromAdmin) {
            $this->processImage($product, $eventData);
        }
        $this->processAttributes($product, $eventData);
        $this->processCardPriceTiers($product, $eventData);

        if (isset($eventData['supplements'])) {
            $this->processSupplements($product, $eventData);
        }

        if (isset($eventData['documents'])) {
            $this->processDocuments($product, $eventData);
        }

        if (! $photosFromAdmin) {
            $this->processVideo($product, $eventData);
        }

        $this->linkParent($product, $eventData);

        if ($product->is_configurable && isset($eventData['configurable_attributes'])) {
            $this->processConfigurableAttributes($product, $eventData['configurable_attributes']);
        }

        $this->handleDefaultVariantUniqueness($product);
        $this->maybeSoftDelete($product, $eventData);

        $this->ensureSlug($product);

        // EventConsumer сохраняет модель через withoutEvents() → ProductObserver
        // (Eloquent-события) НЕ срабатывает → индексация в Meilisearch не происходит.
        // Индексируем явно в самом конце, когда все связи (категории/бренд/цена)
        // уже привязаны и документ полон.
        $this->reindexProduct($product);
    }

    /**
     * Перед обновлением — НЕ перезаписываем `sku` существующего товара.
     *
     * Причина симметрична admin_back: payload.sku = либо исходный 1С sku
     * (может коллидировать с ~2867 группами), либо синтетический admin'овский
     * `1C-{sku}-{ext}`. Если применять fromEvent::sku к update-flow,
     * storefront попытается перезаписать existing synthetic sku на чужой
     * → нарушение UNIQUE(`store_id`, `sku`) → событие в DLQ.
     * Sku при update не трогается: он установлен в beforeCreate и корректен.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeUpdate(Model $product, array $eventData, array $transformedData): ?array
    {
        if ($this->isOneCCardMasterEvent($eventData)) {
            $transformedData = $this->applyOneCSku($product, $eventData, $transformedData);
        } else {
            unset($transformedData['sku']);
        }

        // Смена шага продажи делает позиции живых корзин невалидными
        // (quantity хранится в старых единицах шага) — чистим (LASTOCHKI-JAMI-11200).
        if (array_key_exists('quantity_step', $transformedData)) {
            $this->clearCartItemsOnStepChange($product, (int) $transformedData['quantity_step']);
        }

        $transformedData = $this->keepSiteManagedQuantity($product, $eventData, $transformedData);
        $transformedData = $this->applyFixedStockLimit($product, $transformedData);
        $transformedData = $this->reconcileQuantumUnits($product, $eventData, $transformedData);

        return $transformedData;
    }

    /**
     * Согласование карточки с квантом товара для событий, не знающих про шаг.
     *
     * Шаг продажи задаётся в админке и приезжает только в событиях admin_back
     * (они несут связку quantity_step + measurement_unit + цена за квант).
     * 1c_parser знает лишь базовые единицы 1С: шлёт measurement_unit=kilogram
     * и цену за килограмм, ключа quantity_step в событии нет. Применённое
     * «как есть», такое событие оставляет товар несогласованным — единица
     * kilogram при шаге 500 г — и ломает две вещи:
     *   1) вес позиции заказа: корзина при шаге хранит граммы, а kilogram
     *      включал конверсию ×1000 (прод-заказ 204 от 10.09.2026: 1 кг огурцов
     *      превратился в 1000 кг и 865 995 ₽);
     *   2) цену: 290 ₽/кг подписывалось клиенту как цена за 500 г.
     *
     * Поэтому приводим событие к кванту товара: единицу — к базовой (кг→г,
     * л→мл), цену — к цене за квант по формуле admin_back CategoryQuantumService:
     * round(round(цена_за_базовую / 1000, 4) × шаг, 2).
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function reconcileQuantumUnits(Model $product, array $eventData, array $attributes): array
    {
        // Событие несёт собственный шаг — оно самодостаточно (admin_back).
        if (array_key_exists('quantity_step', $eventData)) {
            return $attributes;
        }

        $step = (int) ($product->quantity_step ?? 1);
        if ($step <= 1) {
            return $attributes;
        }

        $incomingUnit = $attributes['measurement_unit'] ?? null;
        if (! is_string($incomingUnit) || ! isset(self::LARGE_TO_SMALL_UNIT[$incomingUnit])) {
            return $attributes;
        }

        $smallUnit = self::LARGE_TO_SMALL_UNIT[$incomingUnit];

        $attributes['measurement_unit'] = $smallUnit;

        foreach (['price', 'price_old'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = $this->priceForQuantum((float) $attributes[$field], $step);
            }
        }

        Log::info('Quantum reconciled: large unit event applied to product with sale step', [
            'product_id' => $product->id,
            'quantity_step' => $step,
            'event_unit' => $eventData['measurement_unit'] ?? null,
            'event_price' => $eventData['price'] ?? null,
            'price' => $attributes['price'] ?? null,
            'event_id' => $eventData['event_id'] ?? null,
        ]);

        return $attributes;
    }

    /**
     * Цена за квант из цены за базовую единицу (кг/литр) — как admin_back
     * CategoryQuantumService: цена × шаг / 1000 и округление до копеек, без
     * промежуточного округления цены за грамм (оно теряло копейки, и эхо
     * админки расходилось с прямым событием 1С: 178.35 против 178.38).
     */
    protected function priceForQuantum(float $pricePerLargeUnit, int $step): float
    {
        return round($pricePerLargeUnit * $step / 1000, 2);
    }

    /**
     * Карточка из 1c_parser приходит без ключа stock_mode (режим знает только
     * admin_back), и fromEvent по fallback 'normal' принимает её складской
     * остаток. Для товара, который в БД уже в ignore/fixed, остатком управляет
     * сайт — такой quantity отбрасываем, иначе дневной лимит обнуляется при
     * каждом обмене с 1С (LASTOCHKI-JAMI-11207).
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>
     */
    protected function keepSiteManagedQuantity(Model $product, array $eventData, array $transformedData): array
    {
        if (array_key_exists('stock_mode', $eventData)) {
            return $transformedData;
        }

        if (in_array($product->stock_mode, ['ignore', 'fixed'], true)) {
            unset($transformedData['quantity']);
        }

        return $transformedData;
    }

    /**
     * Включение режима «фиксированный дневной остаток» и смена лимита должны
     * срабатывать сразу, не дожидаясь ночного stock:reset-fixed: для fixed
     * входящий остаток из 1С игнорируется (см. fromEvent), поэтому товар с
     * нулевым/отрицательным остатком иначе висел бы невидимым до 00:05.
     *
     * Рядовые события 1С с неизменным лимитом остаток НЕ трогают — иначе
     * дневной расход обнулялся бы при каждом обновлении карточки товара.
     *
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>
     */
    protected function applyFixedStockLimit(Model $product, array $transformedData): array
    {
        $newMode = $transformedData['stock_mode'] ?? $product->stock_mode ?? 'normal';
        if ($newMode !== 'fixed') {
            return $transformedData;
        }

        $newLimit = array_key_exists('fixed_stock', $transformedData)
            ? $transformedData['fixed_stock']
            : $product->fixed_stock;

        if ($newLimit === null) {
            return $transformedData;
        }

        $modeChanged = $product->stock_mode !== 'fixed';
        $limitChanged = (int) ($product->fixed_stock ?? 0) !== (int) $newLimit;

        if ($modeChanged || $limitChanged) {
            $transformedData['quantity'] = (int) $newLimit;
        }

        return $transformedData;
    }

    /**
     * Удалить позиции корзин товара при смене шага продажи (кванта).
     *
     * cart_items.quantity хранится в единицах старого шага и снапшотит цену
     * за старый квант: после смены шага portions считались бы по новому шагу
     * от старого количества (total ×N или 0). Удаление позиции — единственное
     * консистентное поведение; клиент добавит товар заново по новой цене/шагу.
     */
    protected function clearCartItemsOnStepChange(Model $product, int $newStep): void
    {
        $currentStep = (int) ($product->quantity_step ?? 1);

        if ($currentStep === $newStep) {
            return;
        }

        $deleted = \App\Models\CartItem::where('product_id', $product->id)->delete();

        if ($deleted > 0) {
            Log::info('Cart items removed due to quantity_step change', [
                'product_id' => $product->id,
                'old_step' => $currentStep,
                'new_step' => $newStep,
                'deleted_items' => $deleted,
            ]);
        }
    }

    /**
     * После обновления — обновить связи и данные.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $product, array $eventData): void
    {
        $this->linkCategories($product, $eventData);
        $this->linkBrand($product, $eventData);

        $photosFromAdmin = $this->isOneCCardMasterEvent($eventData);

        // media проверяем наравне с image_urls/image_url: реальные payload'ы 1С
        // несут оба ключа, но событие только с media раньше молча игнорировалось.
        if (! $photosFromAdmin && (isset($eventData['image_urls']) || isset($eventData['image_url']) || ! empty($eventData['media']))) {
            $this->processImage($product, $eventData);
        }

        if (isset($eventData['attributes'])) {
            $this->processAttributes($product, $eventData);
        }

        $this->processCardPriceTiers($product, $eventData);

        if (isset($eventData['supplements'])) {
            $this->processSupplements($product, $eventData);
        }

        if (isset($eventData['documents'])) {
            $this->processDocuments($product, $eventData);
        }

        if (! $photosFromAdmin && array_key_exists('video_path', $eventData)) {
            $this->processVideo($product, $eventData);
        }

        $this->linkParent($product, $eventData);

        if ($product->is_configurable && isset($eventData['configurable_attributes'])) {
            $this->processConfigurableAttributes($product, $eventData['configurable_attributes']);
        }

        $this->handleDefaultVariantUniqueness($product);
        $this->maybeSoftDelete($product, $eventData);

        $this->ensureSlug($product);

        // См. afterCreate: persist идёт через withoutEvents(), observer молчит —
        // переиндексируем явно (или удаляем из индекса, если товар стал
        // неактивным/удалённым — это решает reindexProduct).
        $this->reindexProduct($product);
    }

    /**
     * Слаг товара — локальное поле storefront: 1С/admin_back его не шлют,
     * а consumer сохраняет модели через withoutEvents(), поэтому creating-хук
     * модели не срабатывает. Генерируем здесь: при создании и как подстраховка
     * для товаров, созданных до появления слага. Существующий слаг не трогаем —
     * ссылки клиентов должны переживать переименование товара.
     */
    private function ensureSlug(Model $product): void
    {
        if (! $product instanceof Product || ! empty($product->slug)) {
            return;
        }

        $slug = app(\App\Services\Product\ProductSlugGenerator::class)
            ->generate($product->title, (int) $product->id);

        if ($slug === null) {
            return;
        }

        $product->slug = $slug;
        $product->saveQuietly();
    }

    /**
     * Если у variant'а выставлен is_default_variant=true — снимаем флаг у других children.
     */
    private function handleDefaultVariantUniqueness(Model $product): void
    {
        if (! $product instanceof Product) {
            return;
        }

        if (! $product->is_default_variant || ! $product->parent_id) {
            return;
        }

        Product::query()
            ->where('parent_id', $product->parent_id)
            ->where('id', '!=', $product->id)
            ->where('is_default_variant', true)
            ->update(['is_default_variant' => false]);
    }

    /**
     * Soft-delete локально + триггер CleanupDeletedProductFromCartsJob.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function maybeSoftDelete(Model $product, array $eventData): void
    {
        if (! $product instanceof Product) {
            return;
        }

        if (! array_key_exists('deleted_at', $eventData)) {
            return;
        }

        $deletedAt = $eventData['deleted_at'];
        if ($deletedAt === null) {
            if ($product->trashed()) {
                $product->restore();
            }

            return;
        }

        if ($product->trashed()) {
            return;
        }

        $product->delete();

        if (class_exists(\App\Jobs\CleanupDeletedProductFromCartsJob::class)) {
            dispatch(new \App\Jobs\CleanupDeletedProductFromCartsJob($product->id));
        }
    }

    /**
     * После удаления товара (delete-событие) — убрать из поискового индекса.
     * EventConsumer удаляет через withoutEvents() → ProductObserver::deleted
     * не срабатывает, поэтому удаляем из Meilisearch явно.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterDelete(Model $product, array $eventData): void
    {
        if (! $product instanceof Product) {
            return;
        }

        $this->searchService->removeProduct($product->id);

        // Переиндексировать parent при удалении child (агрегаты цены/остатка).
        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }

    /**
     * Отложенная (батчевая) переиндексация Meilisearch для product.batch_updated.
     *
     * В батче каждая позиция проходит штатный пайплайн EventConsumer, и
     * afterCreate/afterUpdate зовут reindexProduct() — по HTTP-вызову в Meili
     * на каждый товар. На полной выгрузке 1С (сотни карточек в сообщении,
     * тысячи сообщений) это делало Meili узким местом всего сервера.
     * ProductBatchHandler включает defer-режим на время батча: reindexProduct
     * только копит id (свой + parent), хендлер индексирует всё одним
     * bulk-вызовом после цикла.
     *
     * Состояние статическое намеренно: EventConsumer резолвит СВОЙ экземпляр
     * трансформера через app() (не singleton), у хендлера — другой экземпляр,
     * а режим должен действовать на тот, что внутри пайплайна. Консьюмер
     * однопоточный, конкурентных батчей в процессе не бывает.
     */
    private static bool $deferReindex = false;

    /** @var array<int, true> id товаров, ожидающих bulk-переиндексации */
    private static array $deferredReindexIds = [];

    public static function beginDeferredReindex(): void
    {
        self::$deferReindex = true;
        self::$deferredReindexIds = [];
    }

    /**
     * Выключить defer-режим и отдать накопленные id (сбрасывая буфер).
     *
     * @return list<int>
     */
    public static function endDeferredReindex(): array
    {
        $ids = array_keys(self::$deferredReindexIds);
        self::$deferReindex = false;
        self::$deferredReindexIds = [];

        return $ids;
    }

    /**
     * Синхронизировать поисковый индекс Meilisearch с текущим состоянием товара.
     *
     * Зеркалит логику {@see \App\Observers\ProductObserver}, который при
     * RabbitMQ-пропагации не вызывается (EventConsumer сохраняет модель через
     * withoutEvents()). indexProduct сам решает index/remove по is_active и
     * parent_id; soft-deleted товар убираем из индекса явно.
     */
    private function reindexProduct(Model $product): void
    {
        if (! $product instanceof Product) {
            return;
        }

        if (self::$deferReindex) {
            self::$deferredReindexIds[(int) $product->id] = true;
            if ($product->parent_id) {
                self::$deferredReindexIds[(int) $product->parent_id] = true;
            }

            return;
        }

        if ($product->trashed()) {
            $this->searchService->removeProduct($product->id);
        } else {
            // Свежие связи (категории привязаны в afterCreate/afterUpdate выше);
            // сбрасываем загруженную ранее коллекцию, чтобы productToDocument
            // подтянул актуальные categories из БД.
            $product->unsetRelation('categories');
            $this->searchService->indexProduct($product);
        }

        // Переиндексировать parent при изменении child (агрегаты цены/остатка).
        if ($product->parent_id) {
            $parent = Product::find($product->parent_id);
            if ($parent && $parent->is_active) {
                $this->searchService->indexProduct($parent);
            }
        }
    }

    /**
     * Custom event handler: product.stock.changed.
     * Обновляет только quantity без полного payload.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processStockChangedEvent(Model $product, array $eventData): void
    {
        // Сетевой режим: остаток пер-магазинный — пишем в store_products,
        // глобальную колонку products.quantity не трогаем.
        if (NetworkCatalog::enabled()) {
            $this->upsertStoreProductStock($product, $eventData);

            return;
        }

        // Остатком ignore/fixed-товара управляет сайт (admin storefront), а не 1С:
        // входящий складской остаток из 1С игнорируем (LASTOCHKI-JAMI-11207).
        if (in_array($product->stock_mode, ['ignore', 'fixed'], true)) {
            return;
        }

        $product->update([
            // Остаток весового товара приходит в кг, хранится в граммах (см. toStoredQuantity).
            'quantity' => $this->toStoredQuantity($eventData['quantity'] ?? 0, $product->unit_type?->value),
        ]);
    }

    /**
     * Custom event handler: product.stock_updated.
     * Обновляет только quantity без полного payload.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processStockUpdatedEvent(Model $product, array $eventData): void
    {
        // Сетевой режим: остаток пер-магазинный — пишем в store_products,
        // глобальную колонку products.quantity не трогаем.
        if (NetworkCatalog::enabled()) {
            $this->upsertStoreProductStock($product, $eventData);

            return;
        }

        // Остатком ignore/fixed-товара управляет сайт (admin storefront), а не 1С:
        // входящий складской остаток из 1С игнорируем (LASTOCHKI-JAMI-11207).
        if (in_array($product->stock_mode, ['ignore', 'fixed'], true)) {
            return;
        }

        $product->update([
            // Остаток весового товара приходит в кг, хранится в граммах (см. toStoredQuantity).
            'quantity' => $this->toStoredQuantity($eventData['quantity'] ?? 0, $product->unit_type?->value),
        ]);
    }

    /**
     * Custom event handler: product.price_updated.
     * Обновляет только price(+price_old) без полного payload.
     *
     * Инверсия цен — та же логика, что в fromEvent(): discount_price (если
     * есть) идёт в price (что платит покупатель), price становится price_old
     * (перечёркнутая). Отсутствие discount_price явно снимает price_old
     * (акция закончилась и 1С перестал слать акционную цену в этом событии).
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processPriceUpdatedEvent(Model $product, array $eventData): void
    {
        if (! isset($eventData['price'])) {
            Log::warning('Price not provided in price_updated event', [
                'product_id' => $product->id,
                'event_id' => $eventData['event_id'] ?? null,
            ]);

            return;
        }

        // Сетевой режим: цена пер-магазинная — пишем в store_products,
        // глобальную колонку products.price не трогаем. А шаг продажи и
        // единица измерения — поля КАРТОЧКИ (общие для всей сети), их
        // применяем к products и здесь: иначе у весового товара залипает
        // квант (неверный total корзины, битый in_stock) и не чистятся
        // корзины при смене шага.
        if (NetworkCatalog::enabled()) {
            $cardFields = $this->reconcileQuantumUnits(
                $product,
                $eventData,
                $this->quantumCardFieldsFromPriceEvent($product, $eventData)
            );
            unset($cardFields['price'], $cardFields['price_old']);

            if ($cardFields !== []) {
                $product->update($cardFields);
            }

            // Финальное ревью (F11): магазин резолвится ОДИН раз и передаётся в
            // upsertStoreProductPrice() и processPriceTiers() — раньше каждый
            // вызывал resolveStoreForPerShopEvent() сам, это лишний запрос и
            // возможный дубль warning при ненайденном магазине.
            $storeId = $this->resolveStoreForPerShopEvent($eventData);
            $this->upsertStoreProductPrice($product, $eventData, $storeId);

            // Цены от количества (MID-308): лесенка магазина события.
            if (array_key_exists('price_tiers', $eventData) && $storeId !== null) {
                $this->processPriceTiers($product, $eventData, $storeId);
            }

            return;
        }

        // Цена с копейками (LASTOCHKI-JAMI-11200): цена кванта весового товара
        // дробная, округление до целого рубля недопустимо (чеки/эквайринг).
        $basePrice = round((float) $eventData['price'], 2);
        $discountPrice = isset($eventData['discount_price'])
            ? round((float) $eventData['discount_price'], 2)
            : null;

        $updates = $this->reconcileQuantumUnits($product, $eventData, array_merge([
            'price' => $discountPrice ?? $basePrice,
            'price_old' => $discountPrice ? $basePrice : null,
        ], $this->quantumCardFieldsFromPriceEvent($product, $eventData)));

        $product->update($updates);

        // Цены от количества (MID-308): в обычном режиме магазин лесенки = магазин товара.
        $this->processPriceTiers(
            $product,
            $eventData,
            $product->store_id !== null ? (int) $product->store_id : null
        );

        Log::info('Product price updated via price_updated event', [
            'product_id' => $product->id,
            'price' => $eventData['price'],
            'discount_price' => $eventData['discount_price'] ?? null,
            'quantity_step' => $eventData['quantity_step'] ?? null,
            'event_id' => $eventData['event_id'] ?? null,
        ]);
    }

    /**
     * Лесенка «цены от количества» магазина (MID-308). Контракт ключа price_tiers:
     * ключа нет: ступени не трогаем; [] или null: лесенка магазина удаляется;
     * массив: полная замена. Битая лесенка отбрасывается целиком с
     * предупреждением, цена события к этому моменту уже применена вызывающим.
     *
     * Ступени хранятся в базовых единицах товара (шт, кг, л), как пришли: в
     * порции их переводит чтение ({@see ProductTierLadderProvider}), поэтому
     * смена кванта таблицу не трогает.
     *
     * При выключенном модуле price_tiers ключ игнорируется.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processPriceTiers(Model $product, array $eventData, ?int $storeId): void
    {
        if (! array_key_exists('price_tiers', $eventData)) {
            return;
        }

        if (! app(ModuleConfigService::class)->isPriceTiersEnabledForConsumer()) {
            return;
        }

        if ($storeId === null || $storeId <= 0) {
            return;
        }

        $ladder = PriceTierLadder::normalize($eventData['price_tiers']);

        if ($ladder === null) {
            // Финальное ревью (F3): лесенка признаётся битой в том числе по
            // count($raw) > MAX_TIERS — ошибка источника (тег, продублированный
            // циклом) даст строку лога на тысячи элементов. Логируем счётчик,
            // тип и не больше 3 элементов вместо всего значения.
            $raw = $eventData['price_tiers'];

            Log::warning('Broken price_tiers ladder dropped, price applied', [
                'product_id' => $product->id,
                'store_id' => $storeId,
                'event_id' => $eventData['event_id'] ?? null,
                'price_tiers_count' => is_array($raw) ? count($raw) : null,
                'price_tiers_type' => get_debug_type($raw),
                'price_tiers_sample' => is_array($raw) ? array_slice($raw, 0, 3) : null,
            ]);

            return;
        }

        // Остатки ревью (R2): периодическая пересинхронизация цен от 1С шлёт
        // ту же лесенку повторно на каждое price_updated — без сравнения это
        // лишний DELETE+INSERT и повторяющийся warning о той же ступени на
        // каждое такое событие. Сравниваем с уже сохранённой лесенкой тем же
        // {@see ProductPriceTier::laddersFor()}, что использует батч-путь
        // ({@see \App\RabbitMQ\Handlers\ProductBatchHandler::existingLadders()}) —
        // не дублируем SQL, переиспользуем существующий метод чтения.
        $existing = ProductPriceTier::laddersFor([$storeId], [(int) $product->id])[$storeId.':'.$product->id] ?? [];

        if (PriceTierLadder::same($ladder, $existing)) {
            return;
        }

        ProductPriceTier::replaceLadder((int) $product->id, $storeId, $ladder);
        app(ProductTierLadderProvider::class)->flush();

        // Финальное ревью (F14): правило «ступень приоритетнее акции» плюс
        // немонотонная лесенка от 1С (не отклоняется) означают, что ступень
        // может молча ПОДНЯТЬ цену клиенту (price_old при этом null — внешне
        // обычная цена). Поведение оставлено (решение заказчика), делаем
        // видимым предупреждением. Warning тоже только при реальном
        // изменении лесенки (см. проверку same() выше) — иначе повторное
        // событие с той же дорогой ступенью предупреждало бы на каждый вызов.
        $this->warnIfTierAboveRegularPrice($product, $ladder, $storeId);
    }

    /**
     * Финальное ревью (F14): предупреждение, если хотя бы одна ступень
     * лесенки дороже обычной цены товара без скидки. Сравнение — в порциях
     * (как читает котировка {@see \App\Services\Pricing\ProductPriceResolver}):
     * в карточном событии admin_back price квантового товара уже цена
     * кванта, а лесенка — за базовую единицу, лобовое сравнение дало бы
     * ложные срабатывания.
     *
     * @param  array<int, array{min_quantity: float, price: float}>  $ladder  результат PriceTierLadder::normalize(), базовые единицы
     */
    private function warnIfTierAboveRegularPrice(Model $product, array $ladder, int $storeId): void
    {
        if ($ladder === []) {
            return;
        }

        [$baseSize, $stepSmallest] = ProductTierLadderProvider::unitSizes($product);
        $portionLadder = PriceTierLadder::toPortions($ladder, $baseSize, $stepSmallest);

        $regularPrice = $this->regularPortionPriceForTierWarning($product, $storeId);

        if ($regularPrice === null || $regularPrice <= 0) {
            return;
        }

        foreach ($portionLadder as $tier) {
            if ($tier['price'] > $regularPrice + 0.0005) {
                Log::warning('price_tiers ladder has a tier above regular price', [
                    'product_id' => $product->id,
                    'store_id' => $storeId,
                    'regular_price' => $regularPrice,
                    'tier' => $tier,
                ]);

                return;
            }
        }
    }

    /**
     * Обычная цена товара за порцию, без скидки: то же место, откуда её
     * берёт котировка — оффер магазина покупки в сетевом каталоге (уже
     * применённый событием price_updated чуть выше по стеку), колонки
     * товара в обычном режиме (тоже уже применённые).
     */
    private function regularPortionPriceForTierWarning(Model $product, int $storeId): ?float
    {
        if (NetworkCatalog::enabled()) {
            $offer = StoreProduct::query()
                ->where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->first(['price', 'price_old']);

            return $offer !== null ? (float) ($offer->price_old ?? $offer->price) : null;
        }

        return (float) ($product->price_old ?? $product->price);
    }

    /**
     * Лесенка из карточного события (created / updated): под тем же условием,
     * что пер-магазинная цена. В сетевом каталоге карточка глобальна и цену
     * магазина не несёт, поэтому лесенку тоже не пишет: она приезжает событием
     * цены с shop_code. В обычном режиме магазин лесенки = магазин товара.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processCardPriceTiers(Model $product, array $eventData): void
    {
        if (! array_key_exists('price_tiers', $eventData) || NetworkCatalog::enabled()) {
            return;
        }

        $this->processPriceTiers(
            $product,
            $eventData,
            $product->store_id !== null ? (int) $product->store_id : null
        );
    }

    /**
     * Карточные (глобальные) поля кванта из price_updated: шаг продажи и
     * единица измерения. Они принадлежат товару, а не магазину, поэтому
     * применяются к products в обоих режимах — и в сетевом тоже.
     *
     * Побочный эффект (как в легаси): смена шага чистит позиции корзин,
     * их quantity хранится в единицах старого шага.
     *
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function quantumCardFieldsFromPriceEvent(Model $product, array $eventData): array
    {
        $updates = [];

        // Квант-обогащённое событие несёт шаг и единицу — применяем согласованно
        // с ценой (цена за квант при старом шаге = неверный total у клиента).
        if (isset($eventData['quantity_step'])) {
            $updates['quantity_step'] = (int) $eventData['quantity_step'];
            $this->clearCartItemsOnStepChange($product, (int) $eventData['quantity_step']);
        }

        if (isset($eventData['measurement_unit'])) {
            $updates['measurement_unit'] = $this->mapMeasurementUnit(
                $eventData['measurement_unit'],
                $eventData['unit_type'] ?? 'piece'
            );
        }

        return $updates;
    }

    /**
     * Сетевой режим: цена магазина в store_products. Инверсия цен та же,
     * что в легаси (discount_price -> price, базовая -> price_old);
     * отсутствие discount_price снимает price_old (акция закончилась).
     *
     * @param  array<string, mixed>  $eventData
     * @param  int|null  $storeId  финальное ревью F11: магазин, резолвленный
     *                              ОДИН раз вызывающим (processPriceUpdatedEvent).
     *                              Параметр обязательный (не optional-с-фолбэком):
     *                              null здесь — легитимный результат резолва
     *                              («магазин не найден»), а не признак «не
     *                              передан», поэтому `??=` был бы неверен —
     *                              повторно резолвил бы и задваивал warning
     *                              ровно в том случае, который чиним.
     */
    private function upsertStoreProductPrice(Model $product, array $eventData, ?int $storeId): void
    {
        if ($storeId === null) {
            return;
        }

        $basePrice = round((float) $eventData['price'], 2);
        $discountPrice = isset($eventData['discount_price'])
            ? round((float) $eventData['discount_price'], 2)
            : null;

        // Цена магазина приезжает в той же единице, что и карточная: событие
        // без шага продажи несёт цену за кг — приводим её к цене за квант.
        $prices = $this->reconcileQuantumUnits($product, $eventData, [
            'measurement_unit' => $eventData['measurement_unit'] ?? null,
            'price' => $discountPrice ?? $basePrice,
            'price_old' => $discountPrice ? $basePrice : null,
        ]);

        StoreProduct::updateOrCreate(
            ['store_id' => $storeId, 'product_id' => $product->id],
            [
                'price' => $prices['price'],
                'price_old' => $prices['price_old'],
                'synced_at' => now(),
            ]
        );

        Log::info('Store product price updated (network_catalog)', [
            'product_id' => $product->id,
            'store_id' => $storeId,
            'price' => $eventData['price'],
            'discount_price' => $eventData['discount_price'] ?? null,
            'event_id' => $eventData['event_id'] ?? null,
        ]);
    }

    /**
     * Сетевой режим: остаток магазина в store_products. Весовой товар:
     * кг из 1С -> граммы (та же семантика stockUnitMultiplier, что в легаси).
     * stock_mode ignore/fixed — сетевые (поле products), остаток из 1С
     * при них игнорируется, как в легаси.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function upsertStoreProductStock(Model $product, array $eventData): void
    {
        if (in_array($product->stock_mode, ['ignore', 'fixed'], true)) {
            return;
        }

        $storeId = $this->resolveStoreForPerShopEvent($eventData);
        if ($storeId === null) {
            return;
        }

        StoreProduct::updateOrCreate(
            ['store_id' => $storeId, 'product_id' => $product->id],
            [
                // Остаток весового товара приходит в кг, хранится в граммах (см. toStoredQuantity).
                'quantity' => $this->toStoredQuantity($eventData['quantity'] ?? 0, $product->unit_type?->value),
                'synced_at' => now(),
            ]
        );

        Log::info('Store product stock updated (network_catalog)', [
            'product_id' => $product->id,
            'store_id' => $storeId,
            'quantity' => $eventData['quantity'] ?? 0,
            'event_id' => $eventData['event_id'] ?? null,
        ]);
    }

    /**
     * store_id для пер-магазинного события (цена/остаток). Без shop_code
     * событие некуда применить — дропаем с логом (ретрай не поможет).
     * Трактовка «магазин не указан» та же, что в admin_back: непустая
     * строка (код "0" остаётся валидным).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function resolveStoreForPerShopEvent(array $eventData): ?int
    {
        if (($eventData['shop_code'] ?? '') === '') {
            Log::warning('Per-shop product event without shop_code dropped (network_catalog)', [
                'event_id' => $eventData['event_id'] ?? null,
            ]);

            return null;
        }

        $storeId = $this->resolveOrCreateStoreByCode(
            (string) $eventData['shop_code'],
            (string) ($eventData['source'] ?? '1c'),
        );

        if ($storeId === null) {
            Log::warning('Per-shop product event: store not found (network_catalog)', [
                'shop_code' => $eventData['shop_code'],
                'event_id' => $eventData['event_id'] ?? null,
            ]);
        }

        return $storeId;
    }

    /**
     * Синхронизирует категории товара по данным события.
     *
     * Контракт payload:
     *  - `category_ids: [external_id]` массив — полная sync (товар принадлежит ровно этим);
     *  - `category_ids: []` — отвязать от всех;
     *  - ключ отсутствует — partial update, связи не трогать;
     *  - legacy shim: `category_id: single|null` без `category_ids` — конвертируем в массив (один релиз).
     *
     * После прохождения outbox-окна (когда у admin_back уже не приходит старый payload)
     * — удалить legacy блок и `test_legacy_shim_single_category_id`.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkCategories(Product $product, array $eventData): void
    {
        // Legacy shim: один релиз поддерживаем старое поле category_id, чтобы не потерять
        // outbox-events, написанные до catchup-deploy admin_back.
        if (array_key_exists('category_id', $eventData) && ! array_key_exists('category_ids', $eventData)) {
            $eventData['category_ids'] = $eventData['category_id'] === null ? [] : [$eventData['category_id']];
        }

        if (! array_key_exists('category_ids', $eventData)) {
            return; // partial update — связи не трогаем
        }

        $externalIds = $eventData['category_ids'];

        if (! is_array($externalIds)) {
            Log::warning('ProductEventTransformer: category_ids is not array', [
                'product_id' => $product->id,
                'value' => $externalIds,
            ]);

            return;
        }

        if ($externalIds === []) {
            $product->categories()->detach();
            $product->touch(); // sync через pivot не триггерит updated → ProductObserver → Meilisearch reindex
            $product->load('categories');

            return;
        }

        $source = $eventData['category_source'] ?? null;
        if ($source === null) {
            Log::warning('ProductEventTransformer: category_ids without category_source', [
                'product_id' => $product->id,
                'external_ids' => $externalIds,
            ]);

            return;
        }

        $payload = [];
        foreach (array_values($externalIds) as $i => $externalId) {
            $localId = $this->resolveEntityId('categories', (string) $externalId, $source);

            if ($localId === null) {
                Log::warning('ProductEventTransformer: category external_id not resolved', [
                    'product_id' => $product->id,
                    'external_id' => $externalId,
                    'source' => $source,
                ]);

                continue;
            }

            $payload[$localId] = ['position' => $i];
        }

        if ($payload === []) {
            // Ни одной категории не удалось зарезолвить — это сигнал что либо storefront
            // отстал от admin_back в синхронизации категорий, либо неверный source.
            return;
        }

        $product->categories()->sync($payload);
        $product->touch(); // sync через pivot не триггерит updated → ProductObserver → Meilisearch reindex
        $product->load('categories');
    }

    /**
     * Связать товар с брендом.
     *
     * Аналогично linkCategory — resolve через metadata, pending reference при отсутствии.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkBrand(Product $product, array $eventData): void
    {
        $brandId = $eventData['brand_id'] ?? null;
        $brandSource = $eventData['brand_source'] ?? null;

        if (! $brandId || ! $brandSource) {
            // Если brand_id не передан — сбросить связь (бренд убрали в админке)
            if ($product->brand_id && array_key_exists('brand_id', $eventData)) {
                $product->update(['brand_id' => null]);
            }

            return;
        }

        // Сетевая карточка приезжает без shop_code — ключа может не быть
        // (для brands это ничего не меняет: резолв идёт по source+external_id).
        $brandEntityId = $this->resolveEntityId(
            'brands',
            $brandId,
            $brandSource,
            $eventData['shop_code'] ?? null
        );

        if ($brandEntityId) {
            $product->update(['brand_id' => $brandEntityId]);

            Log::info('Product linked to brand', [
                'product_id' => $product->id,
                'brand_id' => $brandEntityId,
            ]);

            return;
        }

        // Бренд ещё не создан — pending reference
        $this->createPendingReference(
            $product,
            'brand_id',
            'brands',
            $brandSource,
            $brandId,
            $eventData['shop_code'] ?? 'default'
        );
    }

    /**
     * Синхронизировать документы товара.
     *
     * Полная замена: удаляем старые, скачиваем файлы, создаём новые.
     * Пустой массив = удалить все документы.
     *
     * @param  array<string, mixed>  $eventData
     */
    /**
     * Скачать и сохранить видео товара.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processVideo(Product $product, array $eventData): void
    {
        $videoUrl = $eventData['video_path'] ?? null;

        if (! $videoUrl) {
            // Видео убрали — очищаем путь и файл на диске
            if ($product->video_path) {
                $oldPath = $product->video_path;
                $product->update(['video_path' => null, 'video_source_url' => null]);
                Storage::disk('public')->delete($oldPath);
            }

            return;
        }

        // URL не менялся и файл уже скачан — событие без изменений видео,
        // повторное скачивание не нужно (горячий путь полной выгрузки 1С).
        if ($product->video_source_url === $videoUrl && $product->video_path) {
            return;
        }

        try {
            $localPath = $this->downloadImage($videoUrl, 'products/video', self::LARGE_FILE_DOWNLOAD_TIMEOUT);
        } catch (\Exception $e) {
            Log::warning('Failed to download product video', [
                'product_id' => $product->id,
                'video_url' => $videoUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $oldPath = $product->video_path;
        $product->update(['video_path' => $localPath, 'video_source_url' => $videoUrl]);

        // Заменённый файл больше никем не референсится — удаляем с диска.
        if ($oldPath && $oldPath !== $localPath) {
            Storage::disk('public')->delete($oldPath);
        }

        Log::info('Product video downloaded', [
            'product_id' => $product->id,
            'video_path' => $localPath,
        ]);
    }

    private function processDocuments(Product $product, array $eventData): void
    {
        $documents = $eventData['documents'] ?? [];

        $existing = $product->documents()->get();

        // Реюз уже скачанных файлов по source_url — скачиваем только новые URL.
        $pathsByUrl = $existing->whereNotNull('source_url')
            ->keyBy('source_url')
            ->map(fn (ProductDocument $doc) => $doc->file_path)
            ->all();

        // Скачиваем файлы до транзакции
        $prepared = [];
        foreach ($documents as $doc) {
            $localPath = null;
            $fileUrl = ! empty($doc['file_url']) ? (string) $doc['file_url'] : null;
            if ($fileUrl !== null) {
                $localPath = $pathsByUrl[$fileUrl] ?? null;
                if ($localPath === null) {
                    try {
                        $localPath = $this->downloadImage($fileUrl, 'documents', self::LARGE_FILE_DOWNLOAD_TIMEOUT);
                        $pathsByUrl[$fileUrl] = $localPath;
                    } catch (\Exception $e) {
                        Log::warning('Failed to download product document', [
                            'product_id' => $product->id,
                            'file_url' => $fileUrl,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }
                }
            }

            $prepared[] = [
                'product_id' => $product->id,
                'title' => $doc['title'] ?? '',
                'file_path' => $localPath ?? '',
                'source_url' => $fileUrl,
                'original_name' => $doc['original_name'] ?? null,
                'mime_type' => $doc['mime_type'] ?? null,
                'size' => $doc['size'] ?? null,
                'group' => $doc['group'] ?? null,
                'position' => $doc['position'] ?? 0,
            ];
        }

        DB::transaction(function () use ($product, $prepared) {
            $product->documents()->delete();

            foreach ($prepared as $data) {
                ProductDocument::create($data);
            }
        });

        // Файлы, не переиспользованные новыми строками — удалить с диска,
        // иначе каждая замена документа оставляет сироту.
        $keptPaths = array_filter(array_column($prepared, 'file_path'));
        $orphanPaths = $existing->pluck('file_path')
            ->filter()
            ->diff($keptPaths)
            ->unique()
            ->values()
            ->all();
        if ($orphanPaths !== []) {
            Storage::disk('public')->delete($orphanPaths);
        }

        Log::info('Product documents synced', [
            'product_id' => $product->id,
            'count' => count($prepared),
        ]);
    }

    /**
     * Скачать и сохранить изображения товара.
     *
     * Если в событии есть массив image_urls — используем его (основное + доп. фото).
     * Иначе fallback на одиночный image_url (обратная совместимость).
     * Операции delete/create обёрнуты в транзакцию для атомарности.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Product $product, array $eventData): void
    {
        // Новый формат media — [{url, type, mime_type, position}]
        if (! empty($eventData['media'])) {
            $this->processMedia($product, $eventData['media']);

            return;
        }

        // Fallback: старый формат image_urls (массив строк) — нормализуем к media
        // и обрабатываем единым путём (реюз файлов, чистка сирот).
        $imageUrls = ! empty($eventData['image_urls'])
            ? $eventData['image_urls']
            : (! empty($eventData['image_url']) ? [$eventData['image_url']] : []);

        if (empty($imageUrls)) {
            return;
        }

        $media = [];
        foreach (array_values($imageUrls) as $position => $imageUrl) {
            $media[] = [
                'url' => $imageUrl,
                'type' => 'image',
                'mime_type' => null,
                'position' => $position,
            ];
        }

        $this->processMedia($product, $media);
    }

    /**
     * Обработать медиа нового формата с type (image/video).
     *
     * @param  array<array{url: string, type: string, mime_type: ?string, position: int}>  $media
     */
    private function processMedia(Product $product, array $media): void
    {
        $incoming = [];
        foreach ($media as $item) {
            $url = $item['url'] ?? null;
            if (! $url) {
                continue;
            }

            $incoming[] = [
                'url' => (string) $url,
                'type' => $item['type'] ?? 'image',
                'mime_type' => $item['mime_type'] ?? null,
                'position' => (int) ($item['position'] ?? 0),
            ];
        }

        if ($incoming === []) {
            return;
        }

        $existing = $product->images()->orderBy('position')->get();

        // Набор (url+type+position) не изменился — событие без изменений медиа:
        // ни скачивания, ни перезаписи строк. Горячий путь полной выгрузки 1С,
        // где product.updated прилетает на каждый товар с теми же картинками.
        $incomingSignature = array_map(
            fn (array $item) => [$item['url'], $item['type'], $item['position']],
            $incoming,
        );
        $existingSignature = $existing
            ->map(fn (ProductImage $image) => [$image->source_url, $image->type ?? 'image', (int) $image->position])
            ->all();
        if ($incomingSignature === $existingSignature) {
            return;
        }

        // Реюз уже скачанных файлов по source_url — скачиваем только новые URL.
        $pathsByUrl = $existing->whereNotNull('source_url')
            ->keyBy('source_url')
            ->map(fn (ProductImage $image) => $image->path)
            ->all();

        $rows = [];
        foreach ($incoming as $item) {
            $localPath = $pathsByUrl[$item['url']] ?? null;
            if ($localPath === null) {
                $isVideo = $item['type'] === 'video';
                $folder = $isVideo ? 'products/video' : 'products';
                $timeout = $isVideo ? self::LARGE_FILE_DOWNLOAD_TIMEOUT : 5;
                try {
                    $localPath = $this->downloadImage($item['url'], $folder, $timeout);
                    $pathsByUrl[$item['url']] = $localPath;
                } catch (\Exception $e) {
                    Log::warning('Failed to download product media', [
                        'product_id' => $product->id,
                        'url' => $item['url'],
                        'type' => $item['type'],
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            $rows[] = [
                'product_id' => $product->id,
                'path' => $localPath,
                'source_url' => $item['url'],
                'type' => $item['type'],
                // Тип берём по сохранённому файлу, а не из события: downloadImage
                // мог перекодировать картинку в WebP.
                'mime_type' => $this->mimeTypeForStoredPath($localPath, $item['mime_type']),
                'position' => $item['position'],
            ];
        }

        // Все скачивания упали (transient-сбой) — не трогаем существующие медиа.
        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($product, $rows) {
            $product->images()->delete();

            foreach ($rows as $data) {
                ProductImage::create($data);
            }
        });

        // Файлы, на которые больше не ссылается ни одна строка — удалить с диска,
        // иначе каждая замена оставляет сироту (16.5k файлов / 7.1 GB на dev).
        $keptPaths = array_column($rows, 'path');
        $orphanPaths = $existing->pluck('path')
            ->filter()
            ->diff($keptPaths)
            ->unique()
            ->values()
            ->all();
        if ($orphanPaths !== []) {
            Storage::disk('public')->delete(ImageThumbnails::withThumbnails($orphanPaths));
        }

        Log::info('Product media synced', [
            'product_id' => $product->id,
            'count' => count($rows),
        ]);
    }

    /**
     * Синхронизировать атрибуты товара и извлечь КБЖУ.
     *
     * Различаем два случая:
     * - ключ `attributes` отсутствует → partial update (stock_changed,
     *   price_updated) → не трогаем существующие записи;
     * - ключ есть, даже со значением `[]` → полная синхронизация списка,
     *   удаляем старые (иначе удалённые в admin_back атрибуты остаются
     *   stale на storefront).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processAttributes(Product $product, array $eventData): void
    {
        if (! array_key_exists('attributes', $eventData)) {
            return;
        }

        // Карточку ведёт 1С (MID-305): дописываем по code, чужие характеристики
        // (фильтры каталога из курьерки) не удаляем.
        if ($this->isOneCCardMasterEvent($eventData)) {
            $this->mergeAttributesFromOneC($product, $eventData);

            return;
        }

        $attributes = $eventData['attributes'] ?? [];

        // Удаляем старые — даже если новых нет (иначе остаются stale)
        $product->attributes()->delete();

        $position = 0;

        // Создаём новые
        foreach ($attributes as $attr) {
            ProductAttribute::create([
                'product_id' => $product->id,
                'code' => $attr['code'],
                'name' => $attr['name'],
                'value' => (string) $attr['value'],
                'description' => $attr['description'] ?? null,
                'group_code' => $attr['group'] ?? null,
                'attribute_value_id' => $attr['attribute_value_id'] ?? null,
                'attribute_value_code' => $attr['attribute_value_code'] ?? null,
                'position' => $position++,
            ]);
        }

        // Извлекаем КБЖУ в основную модель
        $this->extractNutrition($product, $attributes);

        // Извлекаем состав в основную модель
        $this->extractComposition($product, $attributes);

        Log::info('Product attributes synced', [
            'product_id' => $product->id,
            'attributes_count' => count($attributes),
        ]);
    }

    /**
     * Характеристики из события 1С (MID-305): upsert по (product_id, code),
     * не присланные остаются. Элемент без code/name/value пропускается.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function mergeAttributesFromOneC(Product $product, array $eventData): void
    {
        $incoming = is_array($eventData['attributes']) ? $eventData['attributes'] : [];
        $valid = [];

        foreach ($incoming as $attr) {
            if (! is_array($attr)
                || ! isset($attr['code'], $attr['name'])
                || ! array_key_exists('value', $attr)
                || $attr['value'] === null
                || is_array($attr['value'])) {
                Log::warning('ProductEventTransformer: invalid 1C attribute skipped', [
                    'product_id' => $product->id,
                    'attribute' => $attr,
                ]);

                continue;
            }

            $valid[] = $attr;
        }

        // Текущие характеристики одной выборкой (без запроса на каждый элемент).
        $current = ProductAttribute::query()->where('product_id', $product->id)->get();
        $byCode = $current->keyBy('code');
        $position = (int) $current->max('position');

        foreach ($valid as $attr) {
            $code = (string) $attr['code'];
            $values = [
                'name' => (string) $attr['name'],
                'value' => (string) $attr['value'],
            ];

            // description/group — только при наличии ключа: 1С, приславшая
            // {code, name, value}, не должна стирать описание и группу.
            if (array_key_exists('description', $attr)) {
                $values['description'] = $attr['description'];
            }
            if (array_key_exists('group', $attr)) {
                $values['group_code'] = $attr['group'];
            }

            $existing = $byCode->get($code);

            if ($existing !== null) {
                $existing->update($values);

                continue;
            }

            $byCode->put($code, ProductAttribute::create(array_merge($values, [
                'product_id' => $product->id,
                'code' => $code,
                'position' => ++$position,
            ])));
        }

        $this->extractNutrition($product, $valid);
        $this->extractComposition($product, $valid);

        Log::info('Product attributes merged from 1C', [
            'product_id' => $product->id,
            'attributes_count' => count($valid),
        ]);
    }

    /**
     * Извлечь КБЖУ из атрибутов в поля модели Product.
     *
     * @param  array<array{code: string, name: string, value: mixed}>  $attributes
     */
    private function extractNutrition(Product $product, array $attributes): void
    {
        $nutritionMap = [
            'calories' => 'calories',
            'proteins' => 'proteins',
            'fats' => 'fats',
            'carbs' => 'carbohydrates',
        ];

        $nutritionData = [];

        foreach ($attributes as $attr) {
            $code = $attr['code'];

            // Прямой маппинг по code
            if (isset($nutritionMap[$code])) {
                $nutritionData[$nutritionMap[$code]] = $this->parseNutritionValue($attr['value']);

                continue;
            }

            // Fallback: маппинг по русскому name
            $normalizedName = mb_strtolower(trim($attr['name'] ?? ''));
            if (isset(self::NUTRITION_NAME_MAP[$normalizedName])) {
                $nutritionCode = self::NUTRITION_NAME_MAP[$normalizedName];
                if (isset($nutritionMap[$nutritionCode])) {
                    $nutritionData[$nutritionMap[$nutritionCode]] = $this->parseNutritionValue($attr['value']);
                }
            }
        }

        if (! empty($nutritionData)) {
            $product->update($nutritionData);
        }
    }

    /**
     * Извлечь числовое значение КБЖУ из строки.
     * Обрабатывает: "12,5", "51,2 ккал(200 кДж)", "3.2", "100", "0".
     */
    private function parseNutritionValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        // Извлекаем первое число (целое или дробное с точкой/запятой)
        if (preg_match('/(\d+[.,]\d+|\d+)/', $value, $matches)) {
            $number = str_replace(',', '.', $matches[1]);

            return (float) $number;
        }

        return null;
    }

    /**
     * Извлечь состав из атрибутов в поле Product.composition.
     *
     * @param  array<array{code: string, name: string, value: mixed}>  $attributes
     */
    private function extractComposition(Product $product, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $code = $attr['code'] ?? '';
            $normalizedName = mb_strtolower(trim($attr['name'] ?? ''));

            if ($code === 'composition' || $normalizedName === 'состав') {
                $product->update(['composition' => (string) $attr['value']]);

                return;
            }
        }
    }

    /**
     * Синхронизировать добавки товара из события.
     * Полная синхронизация: delete + re-create.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processSupplements(Product $product, array $eventData): void
    {
        $supplements = $eventData['supplements'] ?? [];

        DB::transaction(function () use ($product, $supplements, $eventData) {
            // Удаляем старые добавки (каскадно удалятся values)
            $product->supplements()->forceDelete();

            foreach ($supplements as $supplementData) {
                $supplement = Supplement::create([
                    'product_id' => $product->id,
                    'title' => $supplementData['title'],
                    'type' => $supplementData['type'] ?? Supplement::TYPE_ONE,
                    'is_required' => $supplementData['is_required'] ?? false,
                ]);

                foreach ($supplementData['values'] ?? [] as $valueData) {
                    $catalogProductId = null;
                    if (! empty($valueData['product_external_id'])) {
                        $catalogProductId = $this->resolveProductIdByExternal(
                            $valueData['product_external_id'],
                            $eventData['shop_code'] ?? null,
                            $eventData['source'] ?? null,
                        );
                    }

                    SupplementValue::create([
                        'supplement_id' => $supplement->id,
                        'title' => $valueData['title'] ?? '',
                        'price' => array_key_exists('price', $valueData) ? $valueData['price'] : null,
                        'product_id' => $catalogProductId,
                        'max_quantity' => $valueData['max_quantity'] ?? null,
                        'track_stock' => $valueData['track_stock'] ?? null,
                        'position' => $valueData['position'] ?? 0,
                    ]);
                }
            }

            Log::info('Product supplements synced', [
                'product_id' => $product->id,
                'supplements_count' => count($supplements),
            ]);
        });
    }

    /**
     * Резолвить локальный product_id по external_id товара-добавки.
     *
     * Использует тот же механизм event_sync_metadata, которым резолвятся категории
     * и parent-товар: поиск по (entity_type='products', external_id, source, shop_code).
     * Если товар-добавка ещё не синхронизирован — возвращает null и логирует warning,
     * чтобы value был создан без product_id (конфигурация не теряется).
     */
    private function resolveProductIdByExternal(
        string $productExternalId,
        ?string $shopCode,
        ?string $source,
    ): ?int {
        $resolvedId = $this->resolveEntityId(
            'products',
            $productExternalId,
            $source,
            $shopCode,
        );

        if ($resolvedId === null) {
            Log::warning('catalog supplement product not synced yet', [
                'product_external_id' => $productExternalId,
                'shop_code' => $shopCode,
                'source' => $source,
            ]);
        }

        return $resolvedId;
    }

    /**
     * Маппинг unit_type из события в enum. unit_type здесь — единица остатка:
     * Kg = крупная единица (кг, литр), остаток хранится ×1000 в г/мл; Piece —
     * наименьшая единица (г, мл) и штуки, остаток как есть. 1c_parser шлёт
     * kg/l/g/ml: литры раньше падали в Piece и остаток не переводился в мл.
     */
    private function mapUnitType(string $type): string
    {
        return match ($type) {
            'kg', 'l' => UnitType::Kg->value,
            default => UnitType::Piece->value,
        };
    }

    /**
     * Множитель для приведения остатка к наименьшей единице хранения storefront.
     *
     * Весовой товар (unit_type=kg) ведёт остаток в кг, но внутри storefront
     * количество весовых хранится в граммах (как quantity_step, корзина, заказ),
     * поэтому остаток конвертируется кг→граммы (×1000). Штучные (piece) — без
     * конвертации (×1). Без этого in_stock (quantity >= quantity_step) и проверка
     * наличия сравнивают кг с граммами, и весовые товары выпадают из поиска.
     */
    public function stockUnitMultiplier(?string $unitType): int
    {
        return $unitType === UnitType::Kg->value ? 1000 : 1;
    }

    /**
     * Остаток из события → хранимое целое в наименьшей единице (граммы для кг,
     * штуки для piece). 1С шлёт дробные килограммы (0.216, 2.168): усечение
     * до целых кг ДО умножения давало 0 г и 2000 г — весовой товар с остатком
     * меньше килограмма пропадал из каталога. Округляем ПОСЛЕ умножения.
     */
    public function toStoredQuantity(mixed $quantity, ?string $unitType): int
    {
        return (int) round((float) ($quantity ?? 0) * $this->stockUnitMultiplier($unitType));
    }

    /**
     * Маппинг measurement_unit из события в enum.
     * Если measurement_unit не передан — определяем по unit_type (обратная совместимость).
     */
    private function mapMeasurementUnit(?string $measurementUnit, string $unitType): string
    {
        if ($measurementUnit) {
            return match ($measurementUnit) {
                'gram' => MeasurementUnit::Gram->value,
                'kilogram' => MeasurementUnit::Kilogram->value,
                'liter' => MeasurementUnit::Liter->value,
                'milliliter' => MeasurementUnit::Milliliter->value,
                default => MeasurementUnit::Piece->value,
            };
        }

        // Fallback по unit_type для обратной совместимости
        return match ($unitType) {
            'kg' => MeasurementUnit::Kilogram->value,
            default => MeasurementUnit::Piece->value,
        };
    }

    /**
     * Привязать parent product через pending reference.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkParent(Model $product, array $eventData): void
    {
        if (! isset($eventData['parent_id']) || $eventData['parent_id'] === null) {
            if ($product->parent_id !== null) {
                $product->update(['parent_id' => null]);
            }

            return;
        }

        $parentSource = $eventData['parent_source'] ?? $eventData['source'];
        $parentId = $this->resolveEntityId(
            'products',
            (string) $eventData['parent_id'],
            $parentSource,
            $eventData['shop_code'] ?? null
        );

        if ($parentId) {
            $product->update(['parent_id' => $parentId]);
        } else {
            $this->createPendingReference(
                $product,
                'parent_id',
                'products',
                $parentSource,
                (string) $eventData['parent_id'],
                $eventData['shop_code'] ?? 'default'
            );
        }
    }

    /**
     * Синхронизировать configurable_attributes для parent product.
     *
     * @param  array<array{code: string, name: string, position?: int}>  $attributes
     */
    private function processConfigurableAttributes(Model $product, array $attributes): void
    {
        DB::transaction(function () use ($product, $attributes) {
            $product->configurableAttributes()->delete();

            foreach ($attributes as $attr) {
                $product->configurableAttributes()->create([
                    'attribute_code' => $attr['code'],
                    'attribute_name' => $attr['name'],
                    'position' => $attr['position'] ?? 0,
                ]);
            }
        });
    }

    /**
     * Дедупликация перед созданием новой сущности.
     *
     * Идентичность определяется через `event_sync_metadata (source + external_id)`
     * на уровне `EventConsumer` ДО этого вызова. Здесь намеренно НЕ матчим по
     * `sku`: бизнес-ключ не уникален в источнике 1С (~2867 групп дубликатов
     * в djami_1c.products) и пересекается с retailbridge / ручными карточками.
     * Бывший fallback по `sku per store_id` приводил к binding'у разных
     * external_id на одну storefront-карточку и потере данных. Воскрешение
     * soft-deleted по совпадению sku тоже отключено.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return Model|null Всегда null. Сущность создаётся консьюмером заново;
     *                    коллизия sku разрешается синтетическим суффиксом в beforeCreate.
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        // Сетевой режим: карточка лежит в якорном store и приезжает без
        // shop_code — коллизию sku проверяем в нём же.
        $storeId = NetworkCatalog::enabled()
            ? NetworkCatalog::anchorStoreId()
            : $this->resolveOrCreateStoreByCode($eventData['shop_code'], $eventData['source']);

        if ($storeId === null) {
            return null;
        }

        $sku = $eventData['sku'] ?? null;
        if (! $sku) {
            return null;
        }

        $existing = Product::withTrashed()
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->first();
        if ($existing) {
            Log::info('SKU collision: refusing to bind to existing storefront product by sku', [
                'sku' => $sku,
                'store_id' => $storeId,
                'existing_product_id' => $existing->id,
                'incoming_external_id' => $eventData['id'] ?? null,
                'incoming_source' => $eventData['source'] ?? null,
            ]);
        }

        return null;
    }
}
