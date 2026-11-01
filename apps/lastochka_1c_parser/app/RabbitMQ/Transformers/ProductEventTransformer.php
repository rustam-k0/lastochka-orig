<?php

namespace App\RabbitMQ\Transformers;

use App\Models\PriceTier;
use App\Models\Product;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product Event Transformer
 *
 * Трансформирует модель Product в формат событий RabbitMQ
 * согласно спецификации docs/exchange.md
 */
class ProductEventTransformer implements EventTransformer
{
    use ResolvesShopCode;

    /**
     * Готовность таблицы price_tiers (MID-308), закэшированная на этот экземпляр
     * трансформера — см. PriceTier::tableReady(). Транспортер обычно создаётся
     * заново на каждое событие, поэтому instance-кэш не мешает воркеру заметить
     * появление таблицы после миграции на следующем событии.
     */
    private ?bool $priceTiersTableReady = null;

    private function priceTiersTableReady(): bool
    {
        return $this->priceTiersTableReady ??= PriceTier::tableReady();
    }

    /**
     * Маппинг единиц измерения из 1С в стандартизированные значения.
     *
     * Поддерживает два формата ключей:
     *  - человекочитаемые строки («шт», «кг», «грамм»);
     *  - коды ОКЕИ (Общероссийский классификатор единиц измерения),
     *    в которых 1С обычно выгружает атрибут «unit» в CommerceML.
     */
    protected const UNIT_MAPPING = [
        // Строковые представления
        'шт' => 'piece',
        'шт.' => 'piece',
        'штука' => 'piece',
        'кг' => 'kg',
        'кг.' => 'kg',
        'килограмм' => 'kg',
        'г' => 'g',
        'г.' => 'g',
        'грамм' => 'g',
        'л' => 'l',
        'л.' => 'l',
        'литр' => 'l',
        'мл' => 'ml',
        'мл.' => 'ml',
        'миллилитр' => 'ml',
        'м' => 'm',
        'м.' => 'm',
        'метр' => 'm',
        'упак' => 'pack',
        'упак.' => 'pack',
        'упаковка' => 'pack',
        // Коды ОКЕИ
        '796' => 'piece',      // Штука
        '166' => 'kg',         // Килограмм
        '163' => 'g',          // Грамм
        '112' => 'l',          // Литр
        '111' => 'ml',         // Миллилитр
        '006' => 'm',          // Метр
        '778' => 'pack',       // Упаковка
    ];

    /**
     * Маппинг единиц измерения из 1С в формат, который ждёт admin_back
     * `ProductEventTransformer::mapMeasurementUnitToInfoWeightType`:
     * piece/gram/kilogram/liter/milliliter (всё остальное → piece).
     *
     * Поддерживает строковые представления и коды ОКЕИ (как UNIT_MAPPING).
     * Отдельный маппинг от UNIT_MAPPING, потому что admin_back ожидает полные
     * английские слова, а не сокращения kg/g/l/ml.
     */
    protected const MEASUREMENT_UNIT_MAPPING = [
        'шт' => 'piece',
        'шт.' => 'piece',
        'штука' => 'piece',
        'кг' => 'kilogram',
        'кг.' => 'kilogram',
        'килограмм' => 'kilogram',
        'г' => 'gram',
        'г.' => 'gram',
        'грамм' => 'gram',
        'л' => 'liter',
        'л.' => 'liter',
        'литр' => 'liter',
        'мл' => 'milliliter',
        'мл.' => 'milliliter',
        'миллилитр' => 'milliliter',
        // Коды ОКЕИ
        '796' => 'piece',
        '166' => 'kilogram',
        '163' => 'gram',
        '112' => 'liter',
        '111' => 'milliliter',
    ];

    /**
     * Transform Product model to event payload
     *
     * @param Product $product
     * @param Model|null $relatedModel
     * @param string|null $eventId
     * @return array
     */
    public function toEvent(Model $product, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $payload = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($product->updated_at ?? now())->toIso8601String(),
            'id' => $product->external_id,
            'shop_code' => $this->resolveShopCode($relatedModel, $product),
            'source' => config('rabbitmq-events.source', '1c'),
            'sku' => !empty($product->sku) ? $product->sku : "1C-$product->id",
            'code' => $product->code,
            'plu' => $product->plu,
            'barcodes' => $product->barcodes ?? [],
            'title' => $product->name,
            'price' => $this->getPrice($product),
            'discount_price' => $this->getDiscountPrice($product),
            'cost_price' => $this->getCostPrice($product),
            'image_url' => $this->getMainImageUrl($product),
            'quantity' => $this->getTotalStock($product),
            'unit_type' => $this->getUnitType($product),
            'measurement_unit' => $this->getMeasurementUnit($product),
            'description' => $product->description,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'weight' => $this->getAttribute($product, 'weight'),
            'nds' => $this->normalizeNdsToVatCode($this->getAttribute($product, 'nds')),
            // Состав и КБЖУ уходят EAV-атрибутами {code, name, value} — admin_back
            // раскладывает их в product_attributes (category_type_id=100),
            // storefront — в колонки composition/calories/proteins/fats/carbohydrates.
            'attributes' => $this->buildNutritionAttributes($product),
            // 1С GUID категории. category_id/category_source больше не публикуем —
            // 1С-категории едут отдельным справочником через routing_key `category_1c.*`.
            // admin_back матчит продукт с 1С-категорией по этому полю; затем после
            // ручной привязки 1С-категории к showcase-категории admin_back сам
            // публикует product.updated с настоящими category_id + category_source='app'.
            // См. LASTOCHKI-JAMI-10114.
            'category_1c_external_id' => $product->category?->external_id,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
            // Мягкое удаление товара по признаку DelProd из 1С. Потребители
            // различают удаление и восстановление по этому ключу: непустое
            // значение — удалить, явный null — восстановить.
            'deleted_at' => $product->deleted_at?->toIso8601String(),
        ];

        // `active` — только при создании товара (начальный статус на консьюмерах).
        // В updated-событиях НЕ публикуем: 1С не управляет витринной активностью,
        // а собственный is_active здесь практически никогда не бывает false
        // (нет обработки <ПометкаУдаления>) — безусловный active=true стирал
        // ручную деактивацию товара в storefront при каждом полном product.updated.
        // admin_back защищён на своей стороне (LASTOCHKI-JAMI-11983, beforeUpdate
        // unset status_id), storefront отличить событие 1С от события админки
        // не может (admin переиздаёт 1С-товары с тем же source='1c'), поэтому
        // фикс на источнике. Payload собирается в ModelEventObserver синхронно
        // с событием модели (delay_seconds=0), wasRecentlyCreated здесь достоверен.
        if ($product->wasRecentlyCreated) {
            $payload['active'] = (bool) $product->is_active;
        }

        // Цены от количества (MID-308): ключ есть только у товара со ступенями
        // розничного типа цены. У остальных товаров payload остаётся прежним.
        $priceTiers = $this->getPriceTiers($product);
        if ($priceTiers !== []) {
            $payload['price_tiers'] = $priceTiers;
        }

        return $payload;
    }

    /**
     * Transform event payload to Product model attributes
     *
     * @param array $data
     * @return array
     */
    public function fromEvent(array $data): array
    {
        // Вариативные parent-товары не синхронизируются с 1С — пропускаем.
        if (!empty($data['is_configurable'])) {
            \Log::info('Skip configurable parent product from RabbitMQ', [
                'external_id' => $data['id'] ?? null,
                'source' => $data['source'] ?? null,
            ]);

            return [];
        }

        return [
            'external_id' => $data['id'],
            'name' => $data['title'],
            'sku' => $data['sku'] ?? null,
            'barcodes' => $data['barcodes'] ?? null,
            'barcode' => $data['barcodes'][0] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $data['active'] ?? true,
        ];
    }

    /**
     * Validate event payload structure
     *
     * @param array $data
     * @return bool
     */
    public function validate(array $data): bool
    {
        $required = ['event_id', 'id', 'shop_code', 'source', 'title'];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получить розничную цену товара
     */
    protected function getPrice(Product $product): float
    {
        // Витринная цена с приоритетом типов: берём первый существующий тип из
        // config('exchange1c.retail_price_types'). Нужно для магазинов без «Розничной»
        // цены (ТСК выгружает только ОПТ/Гасимов/закупка) → fallback на ОПТ.
        $priceTypes = config('exchange1c.retail_price_types', ['Розничная']);
        if (empty($priceTypes)) {
            $priceTypes = ['Розничная'];
        }

        $prices = $product->relationLoaded('prices')
            ? $product->prices
            : $product->prices()->get();

        foreach ($priceTypes as $type) {
            $price = $prices->firstWhere('price_type', $type);
            if ($price) {
                return (float) $price->value;
            }
        }

        // Fallback (LASTOCHKI-JAMI-11200): на магазинах с ЕДИНСТВЕННЫМ типом цены
        // price_type хранится как GUID (offers.xml без секции ТипыЦен), имена не
        // матчатся и getPrice возвращал 0 → полные события рассылали нулевые цены.
        // Берём этот единственный тип. При нескольких именованных типах без
        // розничного — остаёмся на 0 (не показываем клиенту закупочную/опт).
        if ($prices->count() === 1) {
            $only = $prices->first();
            if ((float) $only->value > 0) {
                return (float) $only->value;
            }
        }

        return 0;
    }

    /**
     * Получить акционную цену товара — берётся из той же строки prices,
     * что и витринная цена (см. getPrice()), поле discount_value.
     * Акция — свойство конкретного price_type/блока <Цена>, а не отдельный
     * тип цены: не нужен отдельный маппинг типов для распознавания скидки.
     */
    protected function getDiscountPrice(Product $product): ?float
    {
        $priceTypes = config('exchange1c.retail_price_types', ['Розничная']);
        if (empty($priceTypes)) {
            $priceTypes = ['Розничная'];
        }

        $prices = $product->relationLoaded('prices')
            ? $product->prices
            : $product->prices()->get();

        foreach ($priceTypes as $type) {
            $price = $prices->firstWhere('price_type', $type);
            if ($price) {
                return $price->discount_value !== null ? (float) $price->discount_value : null;
            }
        }

        // Тот же fallback на единственный тип, что и в getPrice().
        if ($prices->count() === 1) {
            $only = $prices->first();

            return $only->discount_value !== null ? (float) $only->discount_value : null;
        }

        return null;
    }

    /**
     * Закупочная цена (себестоимость) — первый существующий тип из
     * config('exchange1c.cost_price_types'). null = закупочной цены нет.
     */
    protected function getCostPrice(Product $product): ?float
    {
        $priceTypes = config('exchange1c.cost_price_types', []);
        if (empty($priceTypes)) {
            return null;
        }

        // Непереданная в БД модель (unit-тесты, «сырой» new Product) не может иметь
        // сохранённой закупочной цены — не трогаем БД (иначе запрос prices() без
        // таблицы/строк). В проде продукт всегда persisted (OffersImporter/consumer).
        if (! $product->exists && ! $product->relationLoaded('prices')) {
            return null;
        }

        $prices = $product->relationLoaded('prices')
            ? $product->prices
            : $product->prices()->get();

        foreach ($priceTypes as $type) {
            $price = $prices->firstWhere('price_type', $type);
            if ($price && (float) $price->value > 0) {
                return (float) $price->value;
            }
        }

        return null;
    }

    /**
     * Тип цены, из которого берётся витринная цена товара: тот же выбор, что в
     * getPrice() (первый существующий тип из retail_price_types, затем fallback на
     * единственный тип цены). null: витринной цены у товара нет.
     */
    public function resolveRetailPriceType(Product $product): ?string
    {
        $priceTypes = config('exchange1c.retail_price_types', ['Розничная']);
        if (empty($priceTypes)) {
            $priceTypes = ['Розничная'];
        }

        $prices = $product->relationLoaded('prices')
            ? $product->prices
            : $product->prices()->get();

        foreach ($priceTypes as $type) {
            if ($prices->firstWhere('price_type', $type)) {
                return (string) $type;
            }
        }

        if ($prices->count() === 1) {
            $only = $prices->first();
            if ((float) $only->value > 0) {
                return (string) $only->price_type;
            }
        }

        return null;
    }

    /**
     * Лесенка «цены от количества» (MID-308) розничного типа цены в формате события:
     * [{min_quantity, price}, ...] по возрастанию порога, в тех же единицах, что price.
     * Пустой массив: ступеней нет, ключ price_tiers в событие не добавляется.
     *
     * @param  string|null  $eventPriceType  тип цены события price_updated; лесенка
     *                                       отдаётся, только если это розничный тип
     * @return array<int, array{min_quantity: float, price: float}>
     */
    protected function getPriceTiers(Product $product, ?string $eventPriceType = null): array
    {
        // Несохранённая модель (unit-тесты, «сырой» new Product) ступеней иметь не может:
        // не трогаем БД, как и в getCostPrice().
        if (! $product->exists && ! $product->relationLoaded('priceTiers')) {
            return [];
        }

        // Таблицы price_tiers ещё нет (окно выкладки миграции, MID-308): без этой
        // проверки сборка ЛЮБОГО события товара (toEvent(), price_updated) падала бы
        // на обращении к связи. Кэшируется на экземпляр трансформера — см. PriceTier::tableReady().
        if (! $product->relationLoaded('priceTiers') && ! $this->priceTiersTableReady()) {
            return [];
        }

        $tiers = $product->relationLoaded('priceTiers')
            ? $product->priceTiers
            : $product->priceTiers()->get();

        if ($tiers->isEmpty()) {
            return [];
        }

        $retailType = $this->resolveRetailPriceType($product);

        if ($retailType === null || ($eventPriceType !== null && $eventPriceType !== $retailType)) {
            return [];
        }

        return $tiers
            ->where('price_type', $retailType)
            ->sortBy('min_quantity')
            ->map(static fn (PriceTier $tier): array => [
                'min_quantity' => (float) $tier->min_quantity,
                'price' => (float) $tier->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Получить общий остаток товара по всем складам
     */
    protected function getTotalStock(Product $product): float
    {
        if ($product->relationLoaded('stocks')) {
            $total = (float) $product->stocks->sum('quantity');
        } else {
            $total = (float) $product->stocks()->sum('quantity');
        }

        return max(0, $total);
    }

    /**
     * Получить URL главного изображения
     */
    protected function getMainImageUrl(Product $product): ?string
    {
        $image = null;

        if ($product->relationLoaded('mainImage')) {
            $image = $product->mainImage;
        } else {
            $image = $product->images()->where('is_main', true)->first();
        }

        if (!$image || !$image->path) {
            return null;
        }

        // Возвращаем полный URL
        return url(Storage::url($image->path));
    }

    /**
     * Определить тип единицы измерения
     */
    protected function getUnitType(Product $product): string
    {
        $unit = $product->attributes['unit'] ?? null;

        if (!$unit) {
            return 'piece';
        }

        $unitLower = mb_strtolower(trim($unit));

        return self::UNIT_MAPPING[$unitLower] ?? 'piece';
    }

    /**
     * Определить единицу измерения в формате admin_back (полные слова).
     * Используется для заполнения `info_weight_type_id` на приёмке.
     */
    protected function getMeasurementUnit(Product $product): string
    {
        return self::measurementUnitOf($product);
    }

    /**
     * Единица товара 1С (piece/kilogram/gram/liter/milliliter) по attributes.unit:
     * текст («кг») или код ОКЕИ («166»). Общая для событий товара и строк заказа.
     */
    public static function measurementUnitOf(Product $product): string
    {
        $unit = $product->attributes['unit'] ?? null;

        if (!$unit) {
            return 'piece';
        }

        $unitLower = mb_strtolower(trim((string) $unit));

        return self::MEASUREMENT_UNIT_MAPPING[$unitLower] ?? 'piece';
    }

    /**
     * Получить системный атрибут товара из плоского JSON поля attributes.
     * Произвольные характеристики (properties/requisites) через 1С
     * больше не сохраняются — см. CatalogImporter::parseAttributes().
     */
    protected function getAttribute(Product $product, string $key): mixed
    {
        return ($product->attributes ?? [])[$key] ?? null;
    }

    /**
     * Состав и КБЖУ, выгружаемые в admin_back/storefront как EAV-атрибуты.
     * Ключ — код атрибута (совпадает с AttributeSeeder admin_back,
     * category_type_id=100, и NUTRITION_NAME_MAP storefront), значение —
     * человекочитаемое имя для поля name (= title атрибута в admin_back).
     */
    private const NUTRITION_ATTRIBUTE_NAMES = [
        'composition' => 'Состав',
        'calories' => 'Ккал',
        'proteins' => 'Белки',
        'fats' => 'Жиры',
        'carbs' => 'Углеводы',
    ];

    /**
     * Собрать состав и КБЖУ в формат attributes[] = [{code, name, value}, …],
     * который ждёт admin_back (ProductEventTransformer::processAttributes
     * строго требует все три ключа). admin_back находит Attribute по code,
     * привязывает значение к атрибуту секции «Состав» и публикует дальше
     * в storefront, где extractNutrition/extractComposition раскладывают
     * их в колонки по кодам/русским именам.
     *
     * Пустые значения пропускаются — товары без КБЖУ не несут лишних
     * атрибутов и не затирают ранее заполненные значения на приёмке
     * (updateOrCreate по product_id+attribute_id).
     */
    protected function buildNutritionAttributes(Product $product): array
    {
        $attributes = [];

        foreach (self::NUTRITION_ATTRIBUTE_NAMES as $code => $name) {
            $value = $this->getAttribute($product, $code);

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $attributes[] = [
                'code' => $code,
                'name' => $name,
                'value' => trim((string) $value),
            ];
        }

        return $attributes;
    }

    /**
     * Сырая ставка НДС из 1С (CommerceML) → код ставки ЮKassa (1–6, 11, 12) или null.
     * 1С присылает «22», «20», «18», «10», «0», «Без НДС», «22/122», «20/120», «10/110».
     *
     * С 01.01.2026 базовая ставка НДС в РФ повышена до 22% (ФЗ от 28.11.2025 №425-ФЗ),
     * расчётная — до 22/122; льготная 10% сохранена. По справочнику ЮKassa новым ставкам
     * соответствуют vat_code 11 (22%) и 12 (22/122); прежние 4 (20%) и 6 (20/120)
     * остаются доступны для переходных/исторических операций.
     */
    public function normalizeNdsToVatCode(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $v = mb_strtolower(trim((string) $raw));
        if ($v === '') {
            return null;
        }

        if (str_contains($v, 'без') || str_contains($v, 'не облага')) {
            return 1;
        }
        if (str_contains($v, '22/122')) {
            return 12;
        }
        if (str_contains($v, '20/120')) {
            return 6;
        }
        if (str_contains($v, '10/110')) {
            return 5;
        }

        $digits = preg_replace('/[^0-9.]/', '', $v);

        return match ($digits) {
            '22' => 11,
            '20', '18' => 4,
            '10' => 3,
            '0', '0.0' => 2,
            default => null,
        };
    }

    /**
     * Transform model to custom event payload
     *
     * Called by CustomEventPublisher for custom events like 'price_updated'.
     *
     * @param Model|Product $model
     * @param string $eventType Custom event type (e.g., 'price_updated')
     * @param array $customPayload Additional data from dispatch() call
     * @param string|null $eventId Event ID
     * @return array|null Custom payload or null to use default
     */
    public function toCustomEvent(
        Model $model,
        string $eventType,
        array $customPayload,
        ?string $eventId = null
    ): ?array {
        if ($eventType === 'price_updated') {
            return $this->buildPriceUpdatedPayload($model, $customPayload, $eventId);
        }

        if ($eventType === 'stock_updated') {
            return $this->buildStockUpdatedPayload($model, $customPayload, $eventId);
        }

        // Для других кастомных событий — использовать дефолтное поведение
        return null;
    }

    /**
     * Build payload for price_updated event
     *
     * @param Product $product
     * @param array $customPayload
     * @param string|null $eventId
     * @return array
     */
    protected function buildPriceUpdatedPayload(
        Product $product,
        array $customPayload,
        ?string $eventId = null
    ): array {
        $payload = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_type' => 'price_updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $product->external_id,
            'shop_code' => $this->resolveShopCode(null, $product),
            'source' => config('rabbitmq-events.source', '1c'),
            'sku' => $product->sku ?? "1C-{$product->id}",
            'price' => $customPayload['price'] ?? 0,
            'old_price' => $customPayload['old_price'] ?? null,
            'price_type' => $customPayload['price_type'] ?? 'Розничная',
            'currency' => $customPayload['currency'] ?? 'RUB',
            'discount_price' => $customPayload['discount_price'] ?? null,
            // Закупочная цена: если dispatch передал её явно (изменилась именно
            // закупочная) — берём из payload; иначе резолвим из БД по cost_price_types.
            'cost_price' => array_key_exists('cost_price', $customPayload)
                ? $customPayload['cost_price']
                : $this->getCostPrice($product),
            // Единица измерения товара в price_updated. Нужна, чтобы admin_back
            // мог само-исцелять base_unit_type_id (сырую единицу 1С) при каждом
            // обновлении цены: иначе залипшая единица (кг-товар с base_unit=грамм)
            // не исправляется — полный product.updated приходит крайне редко, —
            // и нормализация цены раздувает price_per_kg/value в 1000 раз
            // (LASTOCHKI-JAMI-11200).
            'unit_type' => $this->getUnitType($product),
            'measurement_unit' => $this->getMeasurementUnit($product),
        ];

        // Цены от количества (MID-308). Явный ключ из dispatch (лесенка изменилась,
        // в том числе [] при исчезновении тега) приоритетнее; иначе добавляем
        // сохранённую лесенку розничного типа, если она есть. Товары без ступеней
        // ключ не получают: для них payload не меняется.
        if (array_key_exists('price_tiers', $customPayload)) {
            $payload['price_tiers'] = $customPayload['price_tiers'] ?? [];
        } else {
            $priceTiers = $this->getPriceTiers($product, $customPayload['price_type'] ?? null);
            if ($priceTiers !== []) {
                $payload['price_tiers'] = $priceTiers;
            }
        }

        return $payload;
    }

    /**
     * Build payload for stock_updated event
     *
     * @param Product $product
     * @param array $customPayload
     * @param string|null $eventId
     * @return array
     */
    protected function buildStockUpdatedPayload(
        Product $product,
        array $customPayload,
        ?string $eventId = null
    ): array {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_type' => 'stock_updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => $product->external_id,
            'shop_code' => $this->resolveShopCode(null, $product),
            'source' => config('rabbitmq-events.source', '1c'),
            'sku' => $product->sku ?? "1C-{$product->id}",
            'warehouse_id' => $customPayload['warehouse_id'] ?? null,
            'warehouse_name' => $customPayload['warehouse_name'] ?? null,
            'quantity' => $customPayload['quantity'] ?? 0,
            'old_quantity' => $customPayload['old_quantity'] ?? null,
        ];
    }
}
