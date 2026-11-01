<?php

namespace App\Transformers;

use App\Models\Attribute;
use App\Support\NdsRate;
use App\Support\ProductCode;
use App\Support\StockUnits;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductHistory;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\Status;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\Category1c\ProductShowcaseLinker;
use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use App\Services\ProductShopPrice\ProductShopPriceService;
use App\Services\Quantum\CategoryQuantumService;
use App\Services\Quantum\UnitFamily;
use Comrades\RabbitMQEvents\Concerns\DownloadsExternalImages;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\HasCustomEventHandlers;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Product Event Transformer
 *
 * Трансформер для синхронизации товаров согласно спецификации exchange.md
 * Обрабатывает полный набор полей: цены, остатки, штрихкоды, категории, атрибуты
 */
class ProductEventTransformer implements EventTransformer
{
  use ValidatesEventData;
  use HasEventHandlerHooks;
  use HasCustomEventHandlers;
  use DownloadsExternalImages;
  use ResolvesExternalReferences;
  use ResolvesShopCode;

  protected array $requiredFields = [
    'event_id',
    'id',
    'shop_code',
    'source',
    'sku',
    'title',
    'price',
    'unit_type',
  ];

  /**
   * Обязательные поля для кастомных событий
   * Кастомные события требуют меньше полей чем полный created/updated
   */
  protected array $customEventRequiredFields = [
    'price_updated' => ['event_id', 'id', 'source', 'price'],
    'stock_updated' => ['event_id', 'id', 'source', 'quantity'],
    'category_changed' => ['event_id', 'id', 'source', 'category_id'],
  ];

  /**
   * Внешние ссылки для разрешения
   */
  protected array $externalReferences = [
    'category_id' => [
      'type' => Category::class,
      'source_field' => 'category_source',
      'id_field' => 'category_id',
      'nullable' => true,
    ],
    'brand_id' => [
      'type' => Brand::class,
      'source_field' => 'brand_source',
      'id_field' => 'brand_id',
      'nullable' => true,
    ],
  ];

  /**
   * Маппинг типов единиц измерения exchange.md → Type константы
   */
  protected array $unitTypeMapping = [
    'kg' => Type::UNIT_FOOD,      // весовой
    'piece' => Type::UNIT_PIECE,  // штучный
  ];

  /**
   * Атрибуты, которые следует игнорировать при импорте,
   * так как они являются основными полями продукта
   */
  protected array $ignoredAttributes = [
    'meta_title',
    'meta_keywords',
    'meta_description',
    'weight',
    'manage_stock',
    'status',
    'guest_checkout',
    'sku',
    'name',
    'url_key',
    'product_number',
    'short_description',
    'description',
    'price',
    'cost',
    'special_price',
    'special_price_from',
    'special_price_to',
  ];

  /**
   * Валидация входящего события товара.
   *
   * Сетевой каталог (network_catalog): карточка товара одна на всю сеть, она
   * не принадлежит конкретной торговой точке и не несёт цену — `shop_code` и
   * `price` в событиях `created`/`updated` перестают быть обязательными. Цены и
   * остатки приезжают отдельными пер-магазинными событиями (`price_updated` /
   * `stock_updated`), у которых свой набор обязательных полей в
   * `$customEventRequiredFields`, и он тут не затрагивается. Легаси-карточка,
   * в которой `shop_code` и `price` есть, остаётся валидной — набор полей
   * только ослабляется.
   *
   * Стандартная ветка (флаг выключен) — дословная логика трейта
   * ValidatesEventData: метод трейта нельзя вызвать как `parent::validate()`,
   * а `insteadof` не даёт вызывать его условно, поэтому тело продублировано.
   * Любая правка трейта в пакете должна быть отражена и здесь.
   */
  public function validate(array $data): bool
  {
    $eventType = $data['event_type'] ?? 'updated';
    $isCardEvent = in_array($eventType, ['created', 'updated'], true);

    if ($isCardEvent && app(NetworkCatalogMode::class)->isEnabled()) {
      $required = array_values(array_diff($this->requiredFields, ['shop_code', 'price']));
      $missingFields = $this->findMissingFieldsFromList($data, $required);

      if (!empty($missingFields)) {
        Log::warning('Product network-card validation failed', [
          'event_type' => $eventType,
          'missing_fields' => $missingFields,
          'received_fields' => array_keys($data),
          'event_id' => $data['event_id'] ?? 'unknown',
          'transformer' => get_class($this),
        ]);

        return false;
      }

      return true;
    }

    // Для deleted-событий — минимальная валидация
    if (str_contains($eventType, 'deleted')) {
      return !empty($data['id']) && !empty($data['event_id']);
    }

    $requiredFields = $this->getRequiredFieldsForEventType($eventType);

    if (empty($requiredFields)) {
      Log::warning('No required fields defined for validation', [
        'transformer' => get_class($this),
        'event_type' => $eventType,
      ]);

      return true;
    }

    $missingFields = $this->findMissingFieldsFromList($data, $requiredFields);

    if (!empty($missingFields)) {
      Log::warning($this->getEntityType() . ' event validation failed', [
        'event_type' => $eventType,
        'missing_fields' => $missingFields,
        'received_fields' => array_keys($data),
        'event_id' => $data['event_id'] ?? 'unknown',
        'transformer' => get_class($this),
      ]);

      return false;
    }

    return true;
  }

  /**
   * Конвертировать Product в событие для публикации
   */
  public function toEvent(Model $product, ?object $relatedModel = null, ?string $eventId = null): array
  {
    // CustomEventListener из comrades/laravel-rabbitmq-events передаёт сюда
    // Laravel event (ProductUpdated), а не Shop. Helper'ы и vendor-трейт
    // ResolvesShopCode типизированы строго `?Model` — приводим к `?Shop` один раз.
    $shop = $relatedModel instanceof Shop ? $relatedModel : null;

    // Получить цену товара для магазина
    // price = оригинальная цена (price_without_discount или value если скидки нет)
    // discount_price = скидочная цена (value если есть price_without_discount)
    $priceData = $this->getProductPriceData($product, $shop);
    $price = $priceData['price'];
    $discountPrice = $priceData['discount_price'];

    // Получить остатки товара для магазина
    $quantity = $this->getProductQuantity($product, $shop);

    // Получить metadata для товара (если товар пришёл из внешнего источника, например 1C)
    // Это важно для SyncEntity, чтобы отправлять правильный source и external_id
    $productMetadata = EventSyncMetadata::where([
        'entity_type' => 'products',
        'entity_id' => $product->id,
    ])->first();

    // Если есть metadata — товар пришёл из внешнего источника
    // Используем external_id и source из metadata
    $productId = $productMetadata ? $productMetadata->external_id : (string)$product->id;
    $productSource = $productMetadata ? $productMetadata->source : config('rabbitmq-events.source');

    $eventData = [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => now(),
      'id' => $productId,
      'shop_code' => $this->resolveShopCode($shop, $product),
      'source' => $productSource,

      // Обязательные поля
      'sku' => $product->articul ?? (string)$product->id,
      // Код товара из 1С — для поиска по весовому штрихкоду в storefront/курьерке.
      'code' => $product->code,
      // PLU весового товара из 1С: весы печатают его в весовом штрихкоде.
      'plu' => $product->plu,
      'title' => $product->title,
      'price' => (float)$price,
      'unit_type' => $this->stockUnitTypeToExternal($product),
      // Квант в килограммах нормализуется в граммы (storefront ждёт шаг
      // в граммах при measurement_unit=gram/kilogram) — LASTOCHKI-JAMI-11200.
      'quantity_step' => $product->info_weight_type_id == Type::INFO_WEIGHT_KILOGRAM && $product->info_weight
        ? $product->info_weight * 1000
        : ($product->info_weight ?? 1),
      'measurement_unit' => $product->info_weight_type_id == Type::INFO_WEIGHT_KILOGRAM && $product->info_weight
        ? 'gram'
        : $this->mapInfoWeightTypeToMeasurementUnit($product->info_weight_type_id),

      // Опциональные поля
      'barcodes' => !empty($product->barcodes)
        ? $product->barcodes
        : ($product->barcode ? [$product->barcode] : []),
      'discount_price' => $discountPrice,
      'image_url' => $this->getProductImageUrl($product),
      'image_urls' => $this->getProductImageUrls($product),
      'media' => $this->getProductMedia($product),
      // Остаток весового — кг с дробной частью (0.828); усечение до int
      // обнуляло остаток < 1 кг на витрине через эхо product.updated.
      'quantity' => round($quantity, 3),
      'description' => $product->description,
      // Дополнительная информация (произвольный текст, напр. гарантия производителя).
      // Ключ присутствует всегда, в т.ч. null — сброс должен доезжать до storefront.
      'additional_info' => $product->additional_info,
      'meta_title' => $product->meta_title,
      'meta_description' => $product->meta_description,
      'meta_keywords' => $product->meta_keywords,
      'weight' => $product->weight,
      'nds' => $product->vat_code,
      'active' => $product->status_id === Status::CODE_ACTIVE,
      'is_culinary' => (bool) $product->is_culinary,
      'sugar_free' => (bool) $product->sugar_free,
      'on_search_page' => (bool) $product->on_search_page,
      'served_warm' => (bool) $product->served_warm,
      'pickup_only' => (bool) $product->pickup_only,
      'adult_only' => (bool) $product->adult_only,
      // Маркируемый товар (Честный знак, MID-266): витрине и приложениям
      // нужен признак, чтобы требовать скан кода при сборке.
      'is_marked' => (bool) $product->is_marked,
      // Галочки автоматических товарных групп «Новинки» / «Успей купить».
      'is_new' => (bool) $product->is_new,
      'is_hurry_up' => (bool) $product->is_hurry_up,
      'video_path' => $product->video_path ? PathNormalizer::url($product->video_path) : null,

      // Время готовности товара (LASTOCHKI-JAMI-11648, ежедневное ЧЧ:ММ).
      // Ключ присутствует всегда, в т.ч. null — сброс должен доезжать до storefront.
      // TIME-колонка отдаёт H:i:s — нормализуем к H:i.
      'ready_fixed_time' => $product->ready_fixed_time
        ? \Carbon\Carbon::createFromTimeString((string) $product->ready_fixed_time)->format('H:i')
        : null,

      // Атрибуты/характеристики
      'attributes' => $this->transformAttributes($product),

      // Configurable product fields
      'parent_id' => $this->resolveParentExternalId($product),
      'parent_source' => $product->parent_id ? $this->resolveParentSource($product) : null,
      'is_configurable' => (bool) $product->is_configurable,
      'is_default_variant' => (bool) ($product->is_default_variant ?? false),
      'configurable_attributes' => $this->buildConfigurableAttributes($product),

      // Режим управления остатками (stock override)
      'stock_mode' => (string) ($product->effective_stock_mode ?? 'normal'),
      'fixed_stock' => $product->effective_fixed_stock !== null ? (int) $product->effective_fixed_stock : null,

      // Динамические чекбоксы (map field => bool), всегда полная map
      'custom_checkboxes' => json_decode($product->custom_checkboxes ?: '{}', true) ?: [],

      // Timestamps
      'created_at' => $product->created_at,
      'updated_at' => $product->updated_at,
      'deleted_at' => $product->deleted_at,
    ];

    // Цены от количества (MID-308): лесенка магазина события, только в обычном
    // режиме. В сетевом каталоге ступени, как и цена, едут пер-магазинным
    // price_updated (ProductShopPriceService::publishOfferEvents). Лесенка
    // читается здесь же одним запросом и только при включённом модуле: в
    // relations сущности products связь намеренно не добавлена, при
    // выключенном модуле к таблице ступеней не должно быть ни одного запроса
    // (на форке в окне между выкладкой кода и миграцией таблицы ещё нет).
    if (ModuleConfigService::isPriceTiersEnabled() && !app(NetworkCatalogMode::class)->isEnabled()) {
      $eventData['price_tiers'] = $this->priceTiersForEvent($product, $shop);
    }

    // Добавки (supplements) — включаем если связь загружена.
    // Пустой массив — это тоже валидное состояние: означает «все supplements удалены»,
    // storefront должен получить его и синхронизировать (удалить свои).
    if ($product->relationLoaded('supplements')) {
      $shopCode = $eventData['shop_code'];
      $eventData['supplements'] = $product->supplements->map(function ($supplement) use ($shopCode) {
        return [
          'id' => $supplement->id,
          'title' => $supplement->title,
          'type' => $supplement->type,
          'is_required' => (bool) $supplement->is_required,
          'values' => $supplement->relationLoaded('values')
            ? $supplement->values->map(fn ($v) => [
                'id'                  => $v->id,
                'title'               => $v->title,
                'price'               => $v->price === null ? null : (int) $v->price,
                'product_external_id' => $v->product_id
                    ? $this->resolveProductExternalId($v->product_id)
                    : null,
                'max_quantity'        => $v->max_quantity,
                'track_stock'         => $v->track_stock,
                'position'            => (int) $v->position,
              ])->toArray()
            : [],
        ];
      })->toArray();
    }

    // Документы товара — включаем если связь загружена
    if ($product->relationLoaded('documents')) {
      $eventData['documents'] = $product->documents->map(function ($doc) {
        $fileUrl = null;
        if ($doc->file_path) {
          $fileUrl = PathNormalizer::url($doc->file_path);
        }
        return [
          'id' => $doc->id,
          'title' => $doc->title,
          'file_url' => $fileUrl,
          'original_name' => $doc->original_name,
          'mime_type' => $doc->mime_type,
          'size' => $doc->size,
          'group' => $doc->group,
          'position' => (int) $doc->position,
        ];
      })->toArray();
    }

    // Категории товара — массив через pivot `category_product` в порядке pivot.position.
    // Поле `products.showcase_category_id` удалено в Phase A; в payload отдаём
    // `category_ids: [external_id]` (массив), `category_source: string`. Пустой массив —
    // явная отвязка (storefront detach all). Подробнее: spec 2026-05-14-multi-category-products.
    $categories = $product->categories()->get(['categories.id']);

    $categoryExternalIds = [];
    $categorySource = null;

    foreach ($categories as $category) {
        $metadata = EventSyncMetadata::where([
            'entity_type' => 'categories',
            'entity_id' => $category->id,
        ])->first();

        if ($metadata !== null) {
            $categoryExternalIds[] = $metadata->external_id;
            $categorySource = $metadata->source; // last-wins — все категории создаются admin'ом, source одинаковый
        } else {
            $categoryExternalIds[] = (string) $category->id;
            $categorySource = config('rabbitmq-events.source');
        }
    }

    $eventData['category_ids'] = $categoryExternalIds;
    $eventData['category_source'] = $categoryExternalIds === [] ? null : $categorySource;

    // Добавить brand_id + brand_source (аналогично category)
    if ($product->brand_id) {
        $brandMetadata = EventSyncMetadata::where([
            'entity_type' => 'brands',
            'entity_id' => $product->brand_id,
        ])->first();

        if ($brandMetadata) {
            $eventData['brand_id'] = $brandMetadata->external_id;
            $eventData['brand_source'] = $brandMetadata->source;
        } else {
            $eventData['brand_id'] = (string)$product->brand_id;
            $eventData['brand_source'] = config('rabbitmq-events.source');
        }
    }

    // Resolve parent_id через metadata (аналогично category_id)
    if ($product->parent_id) {
        $parentMetadata = EventSyncMetadata::where([
            'entity_type' => 'products',
            'entity_id' => $product->parent_id,
        ])->first();

        if ($parentMetadata) {
            $eventData['parent_id'] = $parentMetadata->external_id;
            $eventData['parent_source'] = $parentMetadata->source;
        } else {
            $eventData['parent_id'] = (string) $product->parent_id;
            $eventData['parent_source'] = config('rabbitmq-events.source');
        }
    }

    // Configurable attributes (только для parent)
    if ($product->is_configurable) {
        $category = $product->categories->first();
        if ($category && $category->attribute_family_id) {
            $family = \App\Models\AttributeFamily::with(['attributes' => function ($q) {
                $q->wherePivot('is_configurable', true)
                  ->where('type_id', \App\Models\Type::ATTRIBUTE_SELECT_ONE);
            }])->find($category->attribute_family_id);

            if ($family) {
                $eventData['configurable_attributes'] = $family->attributes->map(function ($attr, $index) {
                    return [
                        'code' => $attr->code,
                        'name' => $attr->title,
                        'position' => $attr->pivot->position ?? $index,
                    ];
                })->values()->toArray();
            }
        }
    }

    return $eventData;
  }

  /**
   * Конвертировать событие в атрибуты Product для create/update
   *
   * Обязательные поля (sku, title, unit_type) гарантированы `$requiredFields`
   * и проставляются всегда. Остальные — conditional set: присваиваются только
   * если ключ явно присутствует в payload, иначе Eloquent update затрёт
   * существующее значение при партиал-апдейтах (например, от retailbridge,
   * который не шлёт barcodes / description / meta_* / флаги).
   */
  public function fromEvent(array $data): array
  {
    $attributes = [
      'articul' => $data['sku'],
      'title' => $data['title'],
      'unit_type_id' => $this->mapUnitTypeFromExternal($data['unit_type']),
    ];

    // Код товара из 1С (реквизит Код) — отдельное от артикула поле.
    // Conditional set: партиал-события без ключа `code` не затирают значение.
    // Нормализация: 1С форматирует числовые коды неразрывным пробелом между
    // разрядами («172 484»), а на ценнике сборщик видит те же цифры и вводит их
    // слитно. У articul нормализации НЕТ намеренно: на (shop_id, articul) висит
    // UNIQUE, и схлопывание «158 666» → «158666» столкнуло бы разные товары.
    if (array_key_exists('code', $data)) {
      $attributes['code'] = ProductCode::normalize($data['code']);
    }

    // PLU-код товара из 1С — аналогично code, conditional set.
    if (array_key_exists('plu', $data)) {
      $attributes['plu'] = $data['plu'];
    }

    // Мягкое удаление товара по признаку DelProd из 1С. Conditional set:
    // партиал-событие без ключа состояние не трогает, непустое значение
    // удаляет, явный null восстанавливает.
    //
    // Факт существования товара принадлежит источнику: если карточку удалили
    // вручную в админке, а 1С продолжает выгружать товар как живой, следующий
    // обмен товар вернёт. Для скрытия товара силами администратора есть
    // деактивация (status_id), её этот блок не трогает.
    if (array_key_exists('deleted_at', $data)) {
      $attributes['deleted_at'] = $data['deleted_at'] !== null
        ? \Carbon\Carbon::parse($data['deleted_at'])
        : null;
    }

    if (array_key_exists('barcodes', $data)) {
      $list = is_array($data['barcodes']) ? array_values(array_filter($data['barcodes'], fn ($v) => $v !== null && $v !== '')) : [];
      $attributes['barcodes'] = !empty($list) ? $list : null;
      $attributes['barcode'] = $list[0] ?? null;
    }

    if (array_key_exists('description', $data)) {
      $attributes['description'] = $data['description'];
    }

    if (array_key_exists('meta_title', $data)) {
      $attributes['meta_title'] = $data['meta_title'];
    }

    if (array_key_exists('meta_description', $data)) {
      $attributes['meta_description'] = $data['meta_description'];
    }

    if (array_key_exists('meta_keywords', $data)) {
      $attributes['meta_keywords'] = $data['meta_keywords'];
    }

    if (array_key_exists('weight', $data)) {
      $attributes['weight'] = $data['weight'];
    }

    // НДС товара (код ставки ЮKassa 1-6). 1С перезаписывает только если прислал.
    if (array_key_exists('nds', $data)) {
      $attributes['vat_code'] = $data['nds'] !== null ? (int) $data['nds'] : null;
    }

    // Ставка НДС процентом из 1С (MID-305). Приоритетнее кода `nds`;
    // нераспознанное значение НДС товара не трогает (опечатка в 1С не должна
    // стирать правильную ставку), null/пусто — очищает.
    if (array_key_exists('nds_rate', $data)) {
      unset($attributes['vat_code']);
      $vatCode = NdsRate::toVatCode($data['nds_rate']);

      if ($vatCode === false) {
        Log::warning('Unrecognized nds_rate in product event', [
          'external_id' => $data['id'] ?? null,
          'nds_rate' => $data['nds_rate'],
          'event_id' => $data['event_id'] ?? null,
        ]);
      } else {
        $attributes['vat_code'] = $vatCode;
      }
    }

    // Закупочная цена (себестоимость) из 1С — только при наличии ключа,
    // partial-события без cost_price не затирают значение
    if (array_key_exists('cost_price', $data)) {
      $attributes['cost_price'] = $data['cost_price'] !== null ? (float) $data['cost_price'] : null;
    }

    if (array_key_exists('active', $data)) {
      $attributes['status_id'] = $data['active'] ? Status::CODE_ACTIVE : Status::CODE_NOT_PUBLISHED;
    }

    if (array_key_exists('is_culinary', $data)) {
      $attributes['is_culinary'] = (bool) $data['is_culinary'];
    }

    if (array_key_exists('sugar_free', $data)) {
      $attributes['sugar_free'] = (bool) $data['sugar_free'];
    }

    if (array_key_exists('on_search_page', $data)) {
      $attributes['on_search_page'] = (bool) $data['on_search_page'];
    }

    if (array_key_exists('served_warm', $data)) {
      $attributes['served_warm'] = (bool) $data['served_warm'];
    }

    if (array_key_exists('pickup_only', $data)) {
      $attributes['pickup_only'] = (bool) $data['pickup_only'];
    }

    if (array_key_exists('adult_only', $data)) {
      $attributes['adult_only'] = (bool) $data['adult_only'];
    }

    // Признак алкогольной продукции из 1С (MID-305): включает/снимает сразу
    // «18+» и «только самовывоз». Приоритетнее одноимённых флагов в событии.
    // null снимает оба флага; нераспознанное значение («Да», «Y») флаги не
    // трогает: опечатка в 1С не должна снимать «18+» с алкоголя.
    if (array_key_exists('is_alcohol', $data)) {
      $isAlcohol = $data['is_alcohol'] === null
        ? false
        : filter_var($data['is_alcohol'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

      if ($isAlcohol === null) {
        Log::warning('Unrecognized is_alcohol in product event', [
          'external_id' => $data['id'] ?? null,
          'is_alcohol' => $data['is_alcohol'],
          'event_id' => $data['event_id'] ?? null,
        ]);
      } else {
        $attributes['adult_only'] = $isAlcohol;
        $attributes['pickup_only'] = $isAlcohol;
      }
    }

    // Маркируемый товар (Честный знак, MID-266): 1С шлёт is_marked в
    // product.batch_updated. Только при наличии ключа — партиальные события
    // (retailbridge, цены/остатки) не должны сбрасывать признак.
    if (array_key_exists('is_marked', $data)) {
      $attributes['is_marked'] = (bool) $data['is_marked'];
    }

    // «Новинка» / «Успей купить» ведёт админ вручную. Только при наличии ключа:
    // частичные события из 1С (без этих ключей) не должны сбрасывать галочки.
    if (array_key_exists('is_new', $data)) {
      $attributes['is_new'] = (bool) $data['is_new'];
    }

    if (array_key_exists('is_hurry_up', $data)) {
      $attributes['is_hurry_up'] = (bool) $data['is_hurry_up'];
    }

    // Обновляем info_weight_type_id только если событие квант-aware (несёт
    // quantity_step вместе с measurement_unit). Парсер 1С шлёт measurement_unit
    // в КАЖДОМ полном событии (из БазоваяЕдиница), и безусловная перезапись
    // затирала единицу кванта, выставленную в админке (LASTOCHKI-JAMI-11200).
    // Инициализация единицы для НОВЫХ товаров — в beforeCreate.
    if (isset($data['measurement_unit'], $data['quantity_step'])) {
      $attributes['info_weight_type_id'] = $this->mapMeasurementUnitToInfoWeightType($data['measurement_unit']);
    }

    // Сырая единица товара из 1С (база для семейства и нормализации цены кванта).
    // Не перетирается квантом (в отличие от info_weight_type_id). 1С шлёт
    // measurement_unit из БазоваяЕдиница в полном событии; fallback — unit_type
    // (LASTOCHKI-JAMI-11200, адаптивные единицы кванта).
    if (isset($data['measurement_unit'])) {
      $attributes['base_unit_type_id'] = $this->mapMeasurementUnitToInfoWeightType($data['measurement_unit']);
    } elseif (isset($data['unit_type'])) {
      $attributes['base_unit_type_id'] = $this->mapUnitTypeToInfoWeightType($data['unit_type']);
    }

    // Резолв 1С-категории через event_sync лоокап (LASTOCHKI-JAMI-10114).
    // category_1c_external_id — внешний id из 1c_parser; в admin_back хранится
    // в categories_1c.external_id. Парадигма array_key_exists — partial event
    // без поля не должен трогать category_1c_id; явный null = отвязать.
    //
    // Если Category1c ещё не приехала — сохраняем external_id в pending,
    // чтобы Category1cObserver::created/updated дозаполнил связку позже.
    // Без этого partial events (stock/price без category_1c_external_id)
    // не дают шанса повторить резолв.
    if (array_key_exists('category_1c_external_id', $data)) {
      if ($data['category_1c_external_id'] === null) {
        $attributes['category_1c_id'] = null;
        $attributes['pending_category_1c_external_id'] = null;
      } else {
        $externalId = (string) $data['category_1c_external_id'];
        $cat1cShopId = ModuleConfigService::getCategory1cNetworkAnchorShopId();
        if ($cat1cShopId === null) {
          $shopCode = $data['shop_code'] ?? config('rabbitmq-events.shop_code', 'default');
          $cat1cShopId = $this->resolveShopId((string) $shopCode);
        }
        $cat1c = Category1c::where('shop_id', $cat1cShopId)
          ->where('external_id', $externalId)
          ->first();
        if ($cat1c !== null) {
          $attributes['category_1c_id'] = $cat1c->id;
          $attributes['pending_category_1c_external_id'] = null;
        } else {
          $attributes['category_1c_id'] = null;
          $attributes['pending_category_1c_external_id'] = $externalId;
        }
      }
    }

    return $attributes;
  }

  /**
   * Дедупликация перед созданием новой сущности.
   *
   * Идентичность определяется через `event_sync_metadata (source + external_id)`
   * на уровне `EventConsumer` ДО этого вызова. Здесь намеренно НЕ матчим по
   * `articul`/`sku`: бизнес-ключ не уникален в источнике 1С (одна номенклатурная
   * позиция со многими характеристиками — один articul, разные external_id) и
   * пересекается с retailbridge / ручными карточками. Бывший fallback по
   * `articul` приводил к binding'у разных external_id на одну admin-карточку,
   * затиранию данных легаси и невозможности восстановить корректную привязку
   * через resync (см. memory/1c-integration.md, секция «дубликатные sku в 1С»).
   *
   * Воскрешение soft-deleted товара по совпадению sku тоже отключено — оно
   * восстанавливало несвязанные позиции при дубликатах в источнике.
   *
   * @return Model|null Всегда null. Сущность создаётся консьюмером заново;
   *         коллизия articul (в пределах магазина, UNIQUE(shop_id, articul))
   *         разрешается синтетическим суффиксом в beforeCreate.
   */
  public function findExistingEntity(array $eventData, array $transformedData): ?Model
  {
    return null;
  }

  /**
   * Хук: ПЕРЕД созданием товара
   * Разрешаем category_id если указан + защита от sku-collision на articul.
   */
  public function beforeCreate(array $eventData, array $transformedData): ?array
  {
    // Создавать товар по `updated` вправе только владелец идентичности
    // (event_source === source): так работает самовосстановление, например
    // resync 1С. Пересланное чужое обновление неизвестного товара — это не
    // новый товар, а потерянная привязка у отправителя: 16.09.2026 Bagisto
    // переслал обновления под source=retailbridge, и курьерка создала 741 дубль.
    $eventType = $eventData['event_type'] ?? 'updated';
    $eventSource = $eventData['event_source'] ?? null;
    $source = $eventData['source'] ?? null;

    if ($eventType === 'updated' && $eventSource !== null && $source !== null && $eventSource !== $source) {
      Log::warning('Пропуск создания товара по пересланному updated: привязки нет', [
        'external_id' => $eventData['id'] ?? null,
        'source' => $source,
        'event_source' => $eventSource,
        'shop_code' => $eventData['shop_code'] ?? null,
        'event_id' => $eventData['event_id'] ?? null,
      ]);

      return null;
    }

    // ✅ ВАЖНО: Категории — справочники, общие для всех магазинов
    // Ищем БЕЗ shop_code чтобы избежать дубликатов
    if (!empty($eventData['category_id']) && !empty($eventData['category_source'])) {
      $categoryId = $this->resolveCategoryWithoutShopCode(
        $eventData['category_source'],
        $eventData['category_id']
      );

      if ($categoryId) {
        // Категория будет прикреплена через pivot в afterCreate
        Log::info('Category resolved for product', [
          'category_id' => $categoryId,
          'external_id' => $eventData['category_id'],
        ]);
      }
    }

    // Магазин резолвится ДО insert'а: shop_id участвует в UNIQUE(shop_id, articul),
    // поэтому он должен попасть в сам INSERT (а не проставляться пост-фактум в
    // processRelatedData), и этим же магазином скоупится проверка коллизии sku.
    //
    // Сетевой каталог: карточка общая для сети, её владелец — якорный магазин.
    // `shop_code` события (если он есть — легаси-поток) на принадлежность
    // карточки не влияет, он используется только для цен и остатков. Иначе
    // одна и та же номенклатура приезжала бы отдельной карточкой на каждую
    // торговую точку, что сетевой режим как раз и устраняет.
    $networkMode = app(NetworkCatalogMode::class);
    $shopId = $networkMode->isEnabled()
      ? $networkMode->anchorShopId()
      : $this->resolveShopId(
          (string) ($eventData['shop_code'] ?? config('rabbitmq-events.shop_code', 'default'))
        );
    $transformedData['shop_id'] = $shopId;

    if (!empty($transformedData['articul'])) {
      $transformedData['articul'] = $this->resolveUniqueArticul(
        (string) $transformedData['articul'],
        (string) ($eventData['id'] ?? ''),
        $shopId
      );
    }

    // Инициализация единицы кванта для НОВОГО товара: fromEvent применяет
    // measurement_unit только в квант-aware событиях (защита ручных значений),
    // здесь же товара ещё нет — затирать нечего (LASTOCHKI-JAMI-11200).
    if (!isset($transformedData['info_weight_type_id']) && isset($eventData['measurement_unit'])) {
      $transformedData['info_weight_type_id'] = $this->mapMeasurementUnitToInfoWeightType(
        (string) $eventData['measurement_unit']
      );
    }

    return $transformedData;
  }

  /**
   * Если sku уже занят чужим product ТОГО ЖЕ магазина — собрать синтетический
   * articul формата `1C-{sku}-{external_id_short}` (с counter-suffix'ом при
   * повторной коллизии). Без external_id защита не применяется (нечем
   * дезамбигуировать).
   *
   * Проверка занятости скоупится магазином: DB-инвариант — UNIQUE(shop_id,
   * articul), одинаковый sku в разных магазинах легален и сохраняет
   * оригинальное значение. Глобальная проверка (как было раньше) на полной
   * выгрузке сети из N магазинов помечала «коллизией» каждый товар каждого
   * магазина после первого и крутила counter-цикл до ~N итераций-запросов
   * на товар — квадратичное замедление плюс мусорные артикулы у всей базы.
   */
  protected function resolveUniqueArticul(string $sku, string $externalId, int $shopId, ?int $exceptProductId = null): string
  {
    $taken = fn (string $articul): bool => Product::withTrashed()
      ->where('shop_id', $shopId)
      ->where('articul', $articul)
      ->when($exceptProductId !== null, fn ($query) => $query->where('id', '!=', $exceptProductId))
      ->exists();

    if (!$taken($sku)) {
      return $sku;
    }

    if ($externalId === '') {
      return $sku;
    }

    $shortHash = substr(preg_replace('/[^a-zA-Z0-9]/', '', $externalId), 0, 8);
    if ($shortHash === '') {
      return $sku;
    }

    $base = sprintf('1C-%s-%s', $sku, $shortHash);
    $candidate = $base;
    $counter = 0;
    while ($taken($candidate)) {
      $counter++;
      $candidate = $base . '-' . $counter;
      if ($counter > 100) {
        Log::error('Failed to generate unique articul after 100 attempts', [
          'base' => $base,
          'shop_id' => $shopId,
          'external_id' => $externalId,
        ]);
        break;
      }
    }

    Log::info('SKU collision in beforeCreate — assigned synthetic articul', [
      'original_sku' => $sku,
      'shop_id' => $shopId,
      'synthetic_articul' => $candidate,
      'external_id' => $externalId,
    ]);

    return $candidate;
  }

  /**
   * Событие 1С при включённом «1С ведёт карточку товара» (MID-305).
   * Только источник 1С и только его собственные события: повторная публикация
   * курьеркой (event_source = admin) идёт прежним путём.
   */
  protected function isOneCCardMasterEvent(array $eventData): bool
  {
    return ($eventData['source'] ?? null) === '1c'
      && ($eventData['event_source'] ?? null) === '1c'
      && ModuleConfigService::isOneCCardMasterEnabled();
  }

  /**
   * Обновление карточки, которую ведёт 1С (MID-305): артикул и активность
   * перезаписываются. Артикул, уже «принадлежащий» присланному sku (он сам или
   * его синтетика 1C-{sku}-{hash8}), не трогаем — иначе пинг-понг синтетики.
   */
  protected function beforeUpdateFromOneCMaster(Model $product, array $eventData, array $transformedData): array
  {
    $sku = (string) ($transformedData['articul'] ?? '');
    $externalId = (string) ($eventData['id'] ?? '');

    if ($sku === '' || $this->articulBelongsToSku((string) $product->articul, $sku, $externalId)) {
      unset($transformedData['articul']);
    } else {
      $transformedData['articul'] = $this->resolveUniqueArticul($sku, $externalId, (int) $product->shop_id, (int) $product->id);
    }

    if (array_key_exists('status_id', $transformedData)
      && (int) $transformedData['status_id'] !== (int) $product->status_id) {
      ProductHistory::log(
        $product->id,
        ProductHistory::ACTION_STATUS_CHANGED,
        ProductHistory::ACTOR_SYSTEM,
        null,
        '1c',
        (string) $product->status_id,
        (string) $transformedData['status_id'],
        'Статус из 1С'
      );
    }

    return $transformedData;
  }

  protected function articulBelongsToSku(string $articul, string $sku, string $externalId): bool
  {
    if ($articul === $sku) {
      return true;
    }

    $shortHash = mb_substr((string) preg_replace('/[^a-zA-Z0-9]/', '', $externalId), 0, 8);
    if ($shortHash === '') {
      return false;
    }

    $synthetic = preg_quote(sprintf('1C-%s-%s', $sku, $shortHash), '/');

    return preg_match('/^' . $synthetic . '(-\d+)?$/u', $articul) === 1;
  }

  /**
   * Сравнить фото из события 1С с текущими и при расхождении поставить
   * фоновую задачу (MID-305). Время события — в кэш: задача старше него
   * пропускается.
   */
  protected function dispatchOneCImageSync(Product $product, array $eventData): void
  {
    if (array_key_exists('additional_image_urls', $eventData)
      && $eventData['additional_image_urls'] !== null
      && !is_array($eventData['additional_image_urls'])) {
      Log::warning('additional_image_urls is not an array, ignored', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? null,
      ]);
    }

    $sync = app(\App\Services\OneC\OneCProductImageSync::class);
    $desired = \App\Services\OneC\OneCProductImageSync::desiredFromEvent($eventData);

    if (!$desired['main_provided'] && !$desired['additional_provided']) {
      return;
    }

    // Метка времени пишется для любого события с фото, ДО сравнения с текущими:
    // при A -> B -> A третье событие задачу не ставит (фото ещё A), но задача B,
    // выполненная позже, должна увидеть более свежее событие и пропуститься.
    // TTL вместо вечной записи: метка нужна, пока задачи товара в очереди
    // (попытки с backoff укладываются в минуты), двое суток с запасом.
    $timestamp = (string) ($eventData['event_timestamp'] ?? now()->toIso8601String());
    \Illuminate\Support\Facades\Cache::put(
      \App\Jobs\OneC\SyncProductImagesFromOneCJob::latestKey($product->id),
      $timestamp,
      now()->addDays(2)
    );

    if (!$sync->needsSync($product, $desired)) {
      return;
    }

    // При QUEUE_CONNECTION=sync задача выполняется прямо здесь, внутри
    // транзакции консьюмера события: RuntimeException из OneCProductImageSync
    // (полный провал скачивания) иначе откатил бы весь апдейт карточки
    // (цену, остаток, атрибуты), а не только фото. При асинхронной очереди
    // ретрай сбойного фото делает сама очередь (tries/backoff задачи).
    try {
      \App\Jobs\OneC\SyncProductImagesFromOneCJob::dispatch($product->id, $desired, $timestamp);
    } catch (\Throwable $e) {
      Log::warning('1С: синхронизация фото товара не выполнена', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? null,
        'error' => $e->getMessage(),
      ]);
      // Исключение не пробрасываем (иначе откат карточки), но в трекер ошибок
      // оно должно попасть: под этим catch и программные ошибки dispatch.
      report($e);
    }
  }

  /**
   * Хук: ПОСЛЕ создания товара
   * Обрабатываем связанные данные: цены, остатки, штрихкоды, категории, атрибуты, справочники
   */
  public function afterCreate(Model $product, array $eventData): void
  {
    Log::info('🔵 ProductEventTransformer::afterCreate START', [
      'product_id' => $product->id,
      'external_id' => $eventData['id'] ?? null,
      'sku' => $eventData['sku'] ?? null,
    ]);

    // Начальный статус нового товара из внешнего источника — единственный
    // момент, когда автоматика имеет право задать status_id (LASTOCHKI-JAMI-11983).
    // Дальше — только вручную через ProductHide/ProductPublish, см. beforeUpdate().
    ProductHistory::log(
      $product->id,
      ProductHistory::ACTION_STATUS_CHANGED,
      ProductHistory::ACTOR_SYSTEM,
      null,
      $eventData['source'] ?? null,
      null,
      (string) $product->status_id,
      'Начальный статус при создании товара из внешнего источника'
    );

    $this->processRelatedData($product, $eventData, true);

    // Aggregator-режим (FOODLINK-10874): даём шанс построить платформенную карточку
    // и оффер. Безусловно — в форках без listener'а событие no-op (см. §6.3, §13.3).
    event(new \App\Aggregator\Events\ProductImportedFromEvent($product, $eventData));

    Log::info('✅ ProductEventTransformer::afterCreate DONE', [
      'product_id' => $product->id,
      'title' => $product->title,
    ]);
  }

  /**
   * Хук: ПЕРЕД обновлением товара.
   *
   * НЕ перезаписываем `articul` существующего товара. Причина: payload.sku
   * = исходный sku из 1С (может быть дублирующимся среди ~2867 групп).
   * `beforeCreate` для новых product'ов разрешает коллизию через синтетический
   * articul `1C-{sku}-{external_id_short}`. Если применять fromEvent::articul
   * к update-flow — admin попытается перезаписать existing synthetic articul
   * на исходный sku → integrity violation на UNIQUE(`shop_id`, `articul`)
   * → событие в DLQ. Решение: для update articul вообще не трогаем
   * (он уже корректный с момента create).
   *
   * НЕ перезаписываем `status_id` существующего товара (LASTOCHKI-JAMI-11983).
   * `fromEvent()` ставит status_id из ключа `active`, если он есть в payload —
   * это нужно ТОЛЬКО для инициализации нового товара в beforeCreate/afterCreate.
   * Но 1c_parser кладёт `active` в КАЖДОЕ событие product.updated (toEvent без
   * conditional set), и его собственный is_active никогда не становится false
   * (нет обработки ПометкаУдаления/деактивации на стороне 1С) — то есть на
   * update `active` в payload практически всегда true. Любое рутинное
   * изменение каталожного поля в 1С (название/штрихкод/категория/атрибуты,
   * см. ProductSyncPublishCondition::SIGNIFICANT_FIELDS) триггерит полный
   * product.updated и через array_key_exists('active') в fromEvent() стирал
   * ручную деактивацию администратора (ProductHide) уже на следующем цикле
   * обмена. Активность товара — ручной manual-override флаг, автоматика не
   * должна его трогать вообще (тот же принцип, что и для категорий — см.
   * CategoryEventTransformer::fromEvent, где active вообще не читается).
   */
  public function beforeUpdate(Model $product, array $eventData, array $transformedData): ?array
  {
    if ($this->isOneCCardMasterEvent($eventData)) {
      return $this->beforeUpdateFromOneCMaster($product, $eventData, $transformedData);
    }

    unset($transformedData['articul']);
    unset($transformedData['status_id']);

    return $transformedData;
  }

  /**
   * Хук: ПОСЛЕ обновления товара
   */
  public function afterUpdate(Model $product, array $eventData): void
  {
    Log::info('🔵 ProductEventTransformer::afterUpdate START', [
      'product_id' => $product->id,
      'external_id' => $eventData['id'] ?? null,
    ]);

    $this->processRelatedData($product, $eventData, false);

    // Aggregator-режим (FOODLINK-10874): синхронизируем оффер при обновлении товара
    // из 1С (цена/остаток/штрихкоды). Безусловно — в форках no-op.
    event(new \App\Aggregator\Events\ProductImportedFromEvent($product, $eventData));

    Log::info('✅ ProductEventTransformer::afterUpdate DONE', [
      'product_id' => $product->id,
    ]);
  }

  /**
   * Обработать все связанные данные товара
   * Общий метод для afterCreate и afterUpdate
   */
  protected function processRelatedData(Model $product, array $eventData, bool $isCreate): void
  {
    // Сетевой каталог: карточка принадлежит якорному магазину (проставлен в
    // beforeCreate), а событие конкретной точки несёт только её цену и остаток.
    $networkMode = app(NetworkCatalogMode::class);
    $isNetwork = $networkMode->isEnabled();

    // «Магазин в событии указан» — одно определение на весь метод: непустая
    // строка. Оператор `??` его не даёт (пустая строка не null), и событие с
    // `shop_code: ''` уехало бы в resolveShopId('') → createShopByCode('') →
    // магазин-пустышка с пустыми code/title, к которому потом привязывались бы
    // категории; вдобавок пустой код навсегда оседает в статическом кэше
    // трейта. Сравнение с '' (а не empty()) сохраняет валидным код "0".
    $hasShopCode = ($eventData['shop_code'] ?? '') !== '';

    // Пер-магазинные данные пишем лишь тогда, когда магазин в событии реально
    // указан: у сетевой карточки без shop_code цене/остатку лечь некуда —
    // fallback-магазин из конфига приписал бы их случайной точке.
    $writeShopScopedData = !$isNetwork || $hasShopCode;

    // Магазин для справочных привязок (сейчас — pivot «категория ↔ магазин»,
    // шаг 4). Для сетевой карточки без shop_code это ЯКОРЬ: сентинел 'default'
    // из конфига ResolvesShopCode::resolveShopId разворачивает в хардкод id=1
    // БЕЗ проверки существования магазина — при отсутствующем shops.id=1 это
    // FK-violation на shops_categories и всё событие в DLQ, а при существующем
    // чужом id=1 — тихая привязка категорий не к тому магазину.
    // Легаси-ветка оставлена дословно прежней (флаг выключен → байт-в-байт).
    $shopCode = $isNetwork
      ? ($hasShopCode ? (string) $eventData['shop_code'] : $this->networkAnchorShopCode($networkMode))
      : ($eventData['shop_code'] ?? config('rabbitmq-events.shop_code'));
    $shopId = $this->resolveShopId($shopCode);

    // Обновить shop_id на товаре (всегда при создании, при изменении — при обновлении).
    // В сетевом режиме — никогда: событие магазина не должно «переезжать» общую
    // карточку из якоря в свою точку.
    if (!$isNetwork && ($isCreate || $product->shop_id !== $shopId)) {
      $product->withoutEvents(function () use ($product, $shopId) {
        $product->update(['shop_id' => $shopId]);
      });

      if (!$isCreate) {
        Log::debug('Product shop_id updated', [
          'product_id' => $product->id,
          'old_shop_id' => $product->getOriginal('shop_id'),
          'new_shop_id' => $shopId,
        ]);
      }
    }

    // 1. Обработать цену (с учетом скидки, step, price_per_kg)
    if (isset($eventData['price']) && $writeShopScopedData) {
      $this->processPrice(
        $product,
        $eventData['price'],
        $shopCode,
        $eventData['discount_price'] ?? null,
        $eventData['unit_type'] ?? null,
        isset($eventData['quantity_step']) ? (int) $eventData['quantity_step'] : null
      );
    }

    // 1a. Цены от количества (MID-308): лесенка магазина события, под тем же
    // условием, что запись цены. До шагов 4b/4c: они могут опубликовать
    // ProductUpdated, и в карточном событии должна уехать уже новая лесенка.
    if (isset($eventData['price']) && $writeShopScopedData) {
      $this->processPriceTiers($product, $eventData, $shopId);
    }

    // 1b. Синхронизация базовой единицы из 1С (info_weight_type_id) для весовых.
    // Пересчёт кванта идёт ниже (после привязки витринных категорий, шаг 4c),
    // т.к. квант берётся из витринной категории товара (LASTOCHKI-JAMI-11200 v2).
    // info_weight_type_id — единица ПОРЦИИ: у товара с фасовкой (info_weight
    // задан) она своя (г при порции 300), базовая единица 1С живёт в
    // base_unit_type_id. Перезапись «кг» давала «порцию 300 кг» до пересчёта по
    // цене (4c), а у карточки без цены (сетевой каталог) — насовсем.
    if (isset($eventData['measurement_unit']) && (int) ($product->info_weight ?? 0) <= 0) {
      $mappedType = $this->mapMeasurementUnitToInfoWeightType((string) $eventData['measurement_unit']);

      if ((int) $product->info_weight_type_id !== $mappedType) {
        Product::withoutEvents(function () use ($product, $mappedType) {
          $product->forceFill(['info_weight_type_id' => $mappedType])->save();
        });
      }
    }

    // 2. Обработать остатки (только когда магазин в событии указан — см. выше)
    if (isset($eventData['quantity']) && $writeShopScopedData) {
      $this->processQuantity($product, $eventData['quantity'], $shopCode);
    }

    // 3. Обработать штрихкоды
    if (!empty($eventData['barcodes']) && is_array($eventData['barcodes'])) {
      $this->processBarcodes($product, $eventData['barcodes']);
    }

    // 4. Обработать категорию (single-source: 1С/retailbridge events приходят с одной category_id).
    // Multi-showcase привязки для товара формируются через ручной запуск SyncCategoryMappingsJob,
    // который читает Category1c.showcaseCategories. Здесь — replace одной категорией (1С authoritative).
    if (!empty($eventData['category_id']) && !empty($eventData['category_source'])) {
      $this->processCategory($product, $eventData['category_id'], $eventData['category_source'], $shopCode);
    }

    // 4b. Витринная привязка для 1С-товара: связать товар с витринными категориями,
    // замапленными на его Category1c (pivot category_1c_category → category_product).
    // Раньше это делал ТОЛЬКО ручной SyncCategoryMappingsJob, поэтому каждый новый
    // товар из 1С приезжал с category_1c_id, но без витрины → был невидим в курьерке
    // и на витрине до ручного перезапуска синка. Линкуем идемпотентно (no-op если у
    // Category1c нет витрин или связка не изменилась); ProductShowcaseLinker сам
    // диспатчит ProductUpdated при изменении (outbox → storefront).
    if ($product->category_1c_id) {
      app(ProductShowcaseLinker::class)->sync($product);
    }

    // 4c. Пересчёт цены за квант по витринным категориям товара
    // (LASTOCHKI-JAMI-11200 v2). Квант берётся из categories.quantum_grams
    // (минимальный среди категорий товара); сырая цена за кг — в prices.price_per_kg.
    // Выполняется ПОСЛЕ привязки витрин (4b), т.к. квант зависит от категорий.
    //
    // Берём ЭФФЕКТИВНУЮ (акционную, если есть) цену той же логикой, что и
    // processPrice() выше — иначе applyToProduct() пересчитывает value от сырой
    // БАЗОВОЙ цены 1С и затирает акционную цену, только что записанную шагом 1.
    //
    // Условие то же, что у шага 1: пересчёт правит строку prices конкретного
    // магазина, поэтому для сетевой карточки без shop_code он так же неуместен.
    if (isset($eventData['price']) && $writeShopScopedData) {
      app(\App\Services\Quantum\CategoryQuantumService::class)
        ->applyToProduct($product->fresh(), $shopId, $this->resolveEffectivePrice(
          (float) $eventData['price'],
          $eventData['discount_price'] ?? null
        ));
    }

    // 5. Обработать атрибуты
    if (!empty($eventData['attributes']) && is_array($eventData['attributes'])) {
      $this->processAttributes($product, $eventData['attributes']);
    }

    // 5b. Признак учёта остатков из 1С (MID-305). После привязки витринных
    // категорий (4b): эффективный режим «по категории» зависит от них. После
    // атрибутов (5): смена режима сразу публикует ProductUpdated, и в нём
    // должны быть характеристики этой же позиции.
    if (array_key_exists('track_stock', $eventData)) {
      app(\App\Services\Stock\OneCTrackStockApplier::class)->apply($product, $eventData['track_stock']);
    }

    // 6. Фото. Карточку ведёт 1С (MID-305) — главное фото и слайдер
    // синхронизирует фоновая задача; иначе прежнее поведение.
    if ($this->isOneCCardMasterEvent($eventData)) {
      $this->dispatchOneCImageSync($product, $eventData);
    } elseif (!empty($eventData['image_url']) && !$product->img) {
      $this->downloadExternalImage(
        $product,
        $eventData,
        'image_url',
        'img',
        Status::CODE_HEAD_IMG,
        'products',
        $product->title
      );
    }

    // 7. Пересчёт % заполненности после обработки связанных данных
    // (LASTOCHKI-JAMI-11653): события Eloquent на inbound-пути заглушены
    // withoutEvents, listener на ProductUpdated здесь не сработает.
    app(\App\Services\ProductFillPercentService::class)->recalculate($product);
  }

  /**
   * shop_code якорного магазина сети — «магазин по умолчанию» для сетевой
   * карточки, приехавшей без shop_code.
   *
   * Возвращается именно КОД, а не id: вниз по processRelatedData магазин
   * передаётся кодом (processCategory / createCategoryFromExternal), и
   * подмена сигнатур ради одного случая только размножила бы пути.
   *
   * Реальный `shops.code` якоря годится не всегда: пустой код и сентинел
   * конфига ('default') ResolvesShopCode::resolveShopId разворачивает в
   * хардкод id=1 без проверки существования — ровно та ловушка, от которой
   * мы уходим. В этих случаях отдаём формат `shop_{id}`: трейт резолвит его
   * по первичному ключу и только если магазин существует.
   *
   * Если якорной строки в shops нет вовсе (anchorShopId() отдал fallback 1 на
   * пустой таблице) — возвращаем сентинел конфига, то есть прежнее легаси-
   * поведение: новых магазинов-пустышек мы при этом не создаём.
   */
  protected function networkAnchorShopCode(NetworkCatalogMode $networkMode): string
  {
    $anchorId = $networkMode->anchorShopId();
    $anchor = Shop::query()->whereKey($anchorId)->first(['id', 'code']);

    if ($anchor === null) {
      return (string) config('rabbitmq-events.shop_code', 'default');
    }

    // NULL в колонке code (магазин заведён руками до бэкфила) — тот же случай,
    // что и пустая строка: кодом такой магазин не адресуется.
    $anchorCode = (string) ($anchor->code ?? '');
    $configCode = (string) config('rabbitmq-events.shop_code', 'default');

    if ($anchorCode === '' || $anchorCode === $configCode || $anchorCode === 'default') {
      return 'shop_' . $anchorId;
    }

    return $anchorCode;
  }

  /**
   * Обработать цену товара для магазина
   * Использует pessimistic locking для защиты от race conditions
   *
   * Логика скидки:
   * - Если есть discount_price: value = discount_price, price_without_discount = price
   * - Если нет discount_price: value = price, price_without_discount = null
   *
   * Логика step:
   * - Весовой товар (kg): step = 1000
   * - Штучный товар (piece): step = 1
   */
  protected function processPrice(Product $product, float $price, string $shopCode, ?float $discountPrice = null, ?string $unitType = null, ?int $quantityStep = null): void
  {
    $shopId = $this->resolveShopId($shopCode);

    Price::withoutEvents(function () use ($product, $shopId, $price, $discountPrice, $unitType, $quantityStep) {
      \DB::transaction(function () use ($product, $shopId, $price, $discountPrice, $unitType, $quantityStep) {
        // ✅ Атомарный upsert с блокировкой (теперь учитываем shop_id)
        $priceModel = Price::lockForUpdate()
          ->where('product_id', $product->id)
          ->where('shop_id', $shopId)
          ->where('type_id', Type::PRICE_DEFAULT)
          ->first();

        // Сырая единица товара (для нормализации цены к наименьшей единице семейства).
        $rawUnitTypeId = $product->base_unit_type_id
          ?? ($unitType !== null ? $this->mapUnitTypeToInfoWeightType($unitType) : $product->info_weight_type_id);
        $baseSize = UnitFamily::sizeInSmallest($rawUnitTypeId);

        // Определить step: приоритет у quantity_step из события; событие без
        // quantity_step НЕ сбрасывает существующий шаг (LASTOCHKI-JAMI-11200 —
        // раньше partial price_updated без unit_type ронял step в 1 и квант
        // терялся), для нового прайса — fallback 1.
        $step = $quantityStep
          ?? ($priceModel?->step ? (int) $priceModel->step : null)
          ?? 1;

        $rawValue = $discountPrice > 0 ? $discountPrice : $price;

        // Сырая цена, нормализованная к цене за наименьшую единицу семейства
        // (за грамм / за мл / за штуку) — база пересчёта цены кванта (admin-only).
        $pricePerSmall = $rawValue > 0 ? round($rawValue / $baseSize, CategoryQuantumService::PER_SMALL_PRECISION) : null;

        // Данные для сохранения (включая shop_id)
        $priceData = [
          'value' => $discountPrice > 0 ? $discountPrice : $price,
          'price_without_discount' => $discountPrice > 0 ? $price : null,
          'price_per_small_unit' => $pricePerSmall,
          'step' => $step,
          'shop_id' => $shopId,
        ];

        // price_per_kg (за кг) — shared-колонка, читается purchase_back/delivery_back.
        // Сохраняем как раньше: только для весовых (масса), цена за кг.
        if (UnitFamily::family($rawUnitTypeId) === UnitFamily::MASS && $rawValue > 0) {
          $priceData['price_per_kg'] = round($rawValue * 1000 / $baseSize, 2);
        }

        if ($priceModel) {
          $priceModel->forceFill($priceData)->save();
        } else {
          $newPrice = new Price();
          $newPrice->forceFill(array_merge($priceData, [
            'product_id' => $product->id,
            'type_id' => Type::PRICE_DEFAULT,
          ]))->save();
        }
      });
    });

    Log::debug('Price processed', [
      'product_id' => $product->id,
      'shop_id' => $shopId,
      'price' => $price,
      'discount_price' => $discountPrice,
      'unit_type' => $unitType,
    ]);
  }

  /**
   * Лесенка «цены от количества» магазина из события (MID-308).
   *
   * Семантика ключа price_tiers: ключа нет — ступени магазина не трогаем;
   * [] или null — лесенка магазина удаляется; массив — полная замена.
   * Единицы те же, что у price в событии 1С: базовые единицы товара.
   *
   * Модуль выключен — ключ игнорируется, в price_tiers ничего не пишется.
   * Битая лесенка (порог или цена не больше нуля, дубли порогов, не массив)
   * отбрасывается целиком с предупреждением в лог; цена позиции к этому
   * моменту уже применена вызывающей стороной. Немонотонная лесенка от 1С
   * не отклоняется.
   */
  protected function processPriceTiers(Product $product, array $eventData, int $shopId): void
  {
    if (!array_key_exists('price_tiers', $eventData)) {
      return;
    }

    if (!ModuleConfigService::isPriceTiersEnabled()) {
      return;
    }

    $ladder = PriceTierLadder::normalize($eventData['price_tiers']);

    if ($ladder === null) {
      Log::warning('price_tiers dropped: invalid ladder, price applied without tiers', [
        'product_id' => $product->id,
        'shop_id' => $shopId,
        'event_id' => $eventData['event_id'] ?? 'unknown',
        'price_tiers' => $eventData['price_tiers'],
      ]);

      return;
    }

    $changed = app(ProductShopPriceService::class)->syncPriceTiers($product->id, $shopId, $ladder);

    Log::debug('Price tiers processed', [
      'product_id' => $product->id,
      'shop_id' => $shopId,
      'tiers' => count($ladder),
      'changed' => $changed,
    ]);
  }

  /**
   * Эффективная сырая цена товара — та же логика, что и в processPrice():
   * акционная, если она задана и положительна, иначе базовая. Используется
   * везде, где пересчитывается производная от цены величина (квант/шаг),
   * чтобы акционная цена не затиралась пересчётом от сырой базовой цены.
   */
  protected function resolveEffectivePrice(float $price, mixed $discountPrice): float
  {
    $discountPrice = $discountPrice !== null ? (float) $discountPrice : null;

    return $discountPrice > 0 ? $discountPrice : $price;
  }

  /**
   * Обработать остатки товара для магазина
   * Использует pessimistic locking для защиты от race conditions
   */
  protected function processQuantity(Product $product, float $quantity, string $shopCode): void
  {
    $shopId = $this->resolveShopId($shopCode);

    // Нормализуем остаток: отрицательные значения из источника приводим к 0
    $quantity = max(0, $quantity);

    Remain::withoutEvents(function () use ($product, $shopId, $quantity) {
      \DB::transaction(function () use ($product, $shopId, $quantity) {
        // ✅ Атомарный upsert с блокировкой (теперь учитываем shop_id)
        $remain = Remain::lockForUpdate()
          ->where('product_id', $product->id)
          ->where('shop_id', $shopId)
          ->first();

        if ($remain) {
          $remain->forceFill([
            'quantity' => $quantity,
            'shop_id' => $shopId,
          ])->save();
        } else {
          $newRemain = new Remain();
          $newRemain->forceFill([
            'product_id' => $product->id,
            'shop_id' => $shopId,
            'quantity' => $quantity,
          ])->save();
        }
      });
    });

    Log::debug('Quantity processed', [
      'product_id' => $product->id,
      'shop_id' => $shopId,
      'quantity' => $quantity,
    ]);
  }

  /**
   * Обработать штрихкоды товара (после создания/обновления сущности)
   * Сохраняет весь массив в barcodes, первый — в barcode для обратной совместимости
   */
  protected function processBarcodes(Product $product, array $barcodes): void
  {
    $list = array_values(array_filter($barcodes, fn ($v) => $v !== null && $v !== ''));
    $primaryBarcode = $list[0] ?? null;

    $product->withoutEvents(function () use ($product, $list, $primaryBarcode) {
      $product->update([
        'barcodes' => !empty($list) ? $list : null,
        'barcode' => $primaryBarcode,
      ]);
    });

    Log::debug('Barcodes processed', [
      'product_id' => $product->id,
      'barcodes' => $list,
      'primary' => $primaryBarcode,
    ]);
  }

  /**
   * Обработать категорию товара
   * Согласно exchange.md товар должен быть ТОЛЬКО в ОДНОЙ категории (category_id, не category_ids)
   * Используем sync() для замены всех категорий на указанную
   *
   * Если категория не найдена — создаём её автоматически и фиксируем в metadata
   */
  protected function processCategory(Product $product, string $categoryExternalId, string $categorySource, string $shopCode): void
  {
    // ✅ ВАЖНО: Категории — это справочники, общие для всех магазинов
    // Ищем БЕЗ shop_code чтобы избежать дубликатов
    $categoryId = $this->resolveCategoryWithoutShopCode($categorySource, $categoryExternalId);

    if (!$categoryId) {
      // Категория не найдена — создаём автоматически (тоже без shop_code scope)
      $categoryId = $this->createCategoryFromExternal($categoryExternalId, $categorySource, $shopCode);
    }

    if ($categoryId) {
      // ✅ Заменить все категории на указанную (товар должен быть только в ОДНОЙ категории)
      $product->categories()->sync([$categoryId]);

      // ✅ Убедиться что категория привязана к магазину
      $shopId = $this->resolveShopId($shopCode);
      ShopCategory::firstOrCreate([
          'shop_id' => $shopId,
          'category_id' => $categoryId,
      ]);

      Log::debug('Category synced (replaced all)', [
        'product_id' => $product->id,
        'category_id' => $categoryId,
        'shop_id' => $shopId,
        'external_id' => $categoryExternalId,
      ]);
    } else {
      Log::warning('Category not found and could not be created for product', [
        'product_id' => $product->id,
        'category_external_id' => $categoryExternalId,
        'category_source' => $categorySource,
      ]);
    }
  }

  /**
   * Создать категорию из внешнего события и зафиксировать в metadata
   *
   * @param string $externalId Внешний ID категории
   * @param string $source Источник события
   * @param string $shopCode Код магазина
   * @return int|null ID созданной категории или null при ошибке
   */
  protected function createCategoryFromExternal(string $externalId, string $source, string $shopCode): ?int
  {
    try {
      // Создаём категорию с названием-заглушкой (внешний ID как название)
      // Название будет обновлено когда придёт полное событие категории
      $categoryTitle = "Category #{$externalId}";

      $category = Category::withoutEvents(function () use ($categoryTitle) {
        $category = new Category();
        $category->forceFill([
          'title' => $categoryTitle,
          'link' => Category::getUniqueLink($categoryTitle),
        ]);
        $category->save();
        return $category;
      });

      // Создаём запись в EventSyncMetadata для связи external_id с локальным id
      // ✅ ВАЖНО: используем 'default' shop_code для категорий (справочники общие)
      EventSyncMetadata::create([
        'entity_type' => 'categories',
        'entity_id' => $category->id,
        'source' => $source,
        'external_id' => $externalId,
        'shop_code' => 'default',
        'last_event_id' => Str::uuid()->toString(),
        'last_event_timestamp' => now(),
        'metadata' => [
          'auto_created' => true,
          'auto_created_at' => now()->toIso8601String(),
          'auto_created_reason' => 'product_category_reference',
          'awaiting_full_sync' => true,
        ],
      ]);

      // Создаём связь категории с магазином
      $shopId = $this->resolveShopId($shopCode);
      ShopCategory::firstOrCreate([
          'shop_id' => $shopId,
          'category_id' => $category->id,
      ]);

      Log::info('Category auto-created from product reference', [
        'category_id' => $category->id,
        'category_title' => $categoryTitle,
        'external_id' => $externalId,
        'source' => $source,
        'shop_code' => $shopCode,
      ]);

      return $category->id;

    } catch (\Illuminate\Database\QueryException $e) {
      // Race condition: категория уже была создана другим процессом
      if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
        Log::debug('Race condition in category auto-creation, fetching existing', [
          'external_id' => $externalId,
          'source' => $source,
        ]);

        // Повторно пробуем найти (без shop_code — категории общие)
        return $this->resolveCategoryWithoutShopCode($source, $externalId);
      }

      Log::error('Failed to auto-create category', [
        'external_id' => $externalId,
        'source' => $source,
        'error' => $e->getMessage(),
      ]);

      return null;
    }
  }

  /**
   * Обработать атрибуты товара
   *
   * Формат: [{code: "color", name: "Цвет", value: "Красный"}, ...]
   */
  protected function processAttributes(Product $product, array $attributes): void
  {
    ProductAttribute::withoutEvents(function () use ($product, $attributes) {
      \DB::transaction(function () use ($product, $attributes) {
        foreach ($attributes as $attributeData) {
          // Валидация структуры
          if (!is_array($attributeData) || !isset($attributeData['code']) || !isset($attributeData['name']) || !isset($attributeData['value'])) {
            Log::warning('Invalid attribute structure', [
              'product_id' => $product->id,
              'attribute_data' => $attributeData,
            ]);
            continue;
          }

          $code = $attributeData['code'];
          $name = $attributeData['name'];
          $value = $attributeData['value'];

          // Игнорировать атрибут, если он в списке исключений
          if (in_array($code, $this->ignoredAttributes)) {
            continue;
          }

          // Найти или создать Attribute по code (если есть) или по title
          $attribute = \App\Models\Attribute::where('code', $code)->first();

          if (!$attribute) {
            // Попытка найти по title
            $attribute = \App\Models\Attribute::where('title', $name)->first();
          }

          if (!$attribute) {
            // Создать новый атрибут с защитой от race conditions
            $attribute = $this->findOrCreateAttributeWithCode($code, $name);
          }

          if (!$attribute) {
            Log::warning('Failed to create attribute', [
              'product_id' => $product->id,
              'attribute_code' => $code,
              'attribute_name' => $name,
            ]);
            continue;
          }

          // Определить тип значения и соответствующее поле
          $valueData = $this->mapAttributeValue($value);

          // Создать или обновить ProductAttribute
          ProductAttribute::updateOrCreate(
            [
              'product_id' => $product->id,
              'attribute_id' => $attribute->id,
            ],
            $valueData
          );

          Log::debug('Product attribute processed', [
            'product_id' => $product->id,
            'attribute_code' => $code,
            'attribute_name' => $name,
            'attribute_value' => $value,
          ]);
        }
      });
    });
  }

  /**
   * Найти или создать атрибут с code и title
   *
   * Логика:
   * 1. Ищем по code
   * 2. Если не нашли - ищем по title
   * 3. Если нашли по title - обновляем code (для последующего быстрого поиска)
   * 4. Если не нашли вообще - создаем новый атрибут с code и title
   *
   * Использует forceFill() для установки code (поле не в fillable)
   */
  protected function findOrCreateAttributeWithCode(string $code, string $title): ?\App\Models\Attribute
  {
    try {
      // 1. Поиск по code
      $attribute = \App\Models\Attribute::where('code', $code)->first();

      if ($attribute) {
        return $attribute;
      }

      // 2. Поиск по title (name)
      $attribute = \App\Models\Attribute::where('title', $title)->first();

      if ($attribute) {
        // 3. Нашли по title - обновляем code для последующего поиска
        $attribute->forceFill(['code' => $code]);
        $attribute->save();

        Log::info('Attribute code updated', [
          'attribute_id' => $attribute->id,
          'title' => $title,
          'new_code' => $code,
        ]);

        return $attribute;
      }

      // 4. Не нашли ни по code, ни по title - создаем новый
      $attribute = new \App\Models\Attribute();

      // Определяем тип: по умолчанию строка, если в названии есть "описание" - текст
      $typeId = Type::ATTRIBUTE_STRING;
      if (mb_stripos($title, 'описание') !== false) {
        $typeId = Type::ATTRIBUTE_TEXT;
      }

      $attribute->forceFill([
        'title' => $title,
        'code' => $code,
        'type_id' => $typeId,
        'category_type_id' => Type::ATTRIBUTE_OTHER,  // Значение из boot()
      ]);
      $attribute->save();

      Log::info('New attribute created', [
        'attribute_id' => $attribute->id,
        'title' => $title,
        'code' => $code,
      ]);

      return $attribute;
    } catch (\Illuminate\Database\QueryException $e) {
      // Duplicate key - повторно найти
      if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
        Log::debug('Race condition in attribute creation, retrying find', [
          'code' => $code,
          'title' => $title,
        ]);

        // Повторный поиск по code или title
        return \App\Models\Attribute::where('code', $code)
          ->orWhere('title', $title)
          ->first();
      }

      // Другая ошибка - логировать и вернуть null
      Log::error('Failed to create attribute', [
        'code' => $code,
        'title' => $title,
        'error' => $e->getMessage(),
      ]);

      return null;
    }
  }

  /**
   * Маппинг значения атрибута в соответствующие поля ProductAttribute
   */
  protected function mapAttributeValue($value): array
  {
    // Определить тип значения и соответствующее поле
    $valueField = match (true) {
      is_int($value) => 'int_value',
      is_float($value) => 'float_value',
      is_string($value) && strlen($value) > 255 => 'text_value',
      default => 'string_value',
    };

    return [
      'string_value' => $valueField === 'string_value' ? $value : null,
      'text_value' => $valueField === 'text_value' ? $value : null,
      'int_value' => $valueField === 'int_value' ? $value : null,
      'float_value' => $valueField === 'float_value' ? $value : null,
      'attribute_value_id' => null, // Для простых значений не используем
    ];
  }

  /**
   * Получить данные о ценах товара для магазина
   *
   * Логика:
   * - Если есть price_without_discount: price = price_without_discount, discount_price = value
   * - Если нет price_without_discount: price = value, discount_price = null
   *
   * @return array{price: float, discount_price: float|null}
   */
  protected function getProductPriceData(Product $product, ?Model $shop = null): array
  {
    $priceModel = null;

    if ($shop instanceof Shop) {
      // Фильтр по магазину обязателен: у сетевой карточки цен столько же,
      // сколько точек, и без shop_id событие магазина B уезжало бы с ценой
      // магазина A (first()). Для пер-магазинного товара строка всё равно одна,
      // так что фильтр находит её же — легаси-поведение не меняется.
      $priceModel = Price::where('product_id', $product->id)
        ->where('shop_id', $shop->id)
        ->where('type_id', Type::PRICE_DEFAULT)
        ->first();
    } else {
      // Fallback: цена по умолчанию
      $priceModel = $product->defaultPrice;
    }

    if (!$priceModel) {
      return ['price' => 0, 'discount_price' => null];
    }

    // Если есть price_without_discount - значит есть скидка. Он хранится как
    // пришёл из 1С — за базовую единицу (кг), а value — за порцию: переводим
    // в порцию, иначе у товара с фасовкой ложная скидка «650 → 178.20».
    if ($priceModel->price_without_discount > 0) {
      return [
        'price' => StockUnits::basePriceToPortion($product, (float) $priceModel->price_without_discount), // Оригинальная цена
        'discount_price' => (float)$priceModel->value, // Цена со скидкой
      ];
    }

    // Нет скидки
    return [
      'price' => (float)$priceModel->value,
      'discount_price' => null,
    ];
  }

  /**
   * Лесенка «цены от количества» магазина события (MID-308) для toEvent():
   * [['min_quantity' => float, 'price' => float], ...] в базовых единицах
   * товара, [] — лесенки нет. Магазин тот же, что у shop_code события:
   * переданный Shop либо магазин товара. Один запрос к price_tiers; вызывать
   * только при включённом модуле.
   *
   * @return array<int, array{min_quantity: float, price: float}>
   */
  protected function priceTiersForEvent(Product $product, ?Shop $shop): array
  {
    $shopId = $shop?->id ?? $product->shop_id;

    if (!$shopId) {
      return [];
    }

    return app(ProductShopPriceService::class)->ladderFor((int) $product->id, (int) $shopId);
  }

  /**
   * Получить остатки товара для магазина
   */
  protected function getProductQuantity(Product $product, ?Model $shop = null): float
  {
    if ($shop instanceof Shop) {
      // Остаток берём того же магазина, для которого публикуется событие
      // (см. getProductPriceData) — иначе точке уедет чужой остаток.
      $remain = Remain::where('product_id', $product->id)
        ->where('shop_id', $shop->id)
        ->first();

      return (float)($remain?->quantity ?? 0);
    }

    // Fallback: сумма всех остатков
    return (float)Remain::where('product_id', $product->id)->sum('quantity');
  }

  /**
   * Получить URL изображения товара
   */
  protected function getProductImageUrl(Product $product): ?string
  {
    $path = $product->img?->path;

    if (!$path) {
      return null;
    }

    return PathNormalizer::url($path);
  }

  /**
   * Получить массив URL всех изображений товара (основное + слайдер).
   * Основное фото идёт первым (position 0), затем слайдер.
   *
   * @return string[]
   */
  /**
   * Получить медиа товара с типом (image/video) для передачи в событии.
   * Формат: [{url, type, mime_type, position}]
   */
  protected function getProductMedia(Product $product): array
  {
    $media = [];
    $position = 0;

    // Основное фото
    $mainImg = $product->img;
    if ($mainImg?->path) {
      $media[] = [
        'url' => PathNormalizer::url($mainImg->path),
        'type' => $this->detectMediaType($mainImg),
        'mime_type' => $mainImg->type,
        'position' => $position++,
      ];
    }

    // Слайдер
    foreach ($product->images as $image) {
      if ($image->path) {
        $media[] = [
          'url' => PathNormalizer::url($image->path),
          'type' => $this->detectMediaType($image),
          'mime_type' => $image->type,
          'position' => $position++,
        ];
      }
    }

    return $media;
  }

  /**
   * Определить тип медиа по mime-type или расширению файла.
   */
  protected function detectMediaType(object $img): string
  {
    $mime = $img->type ?? '';
    if (str_starts_with($mime, 'video/')) {
      return 'video';
    }

    $ext = pathinfo($img->path ?? '', PATHINFO_EXTENSION);
    if (in_array(strtolower($ext), ['mp4', 'webm', 'mov', 'avi', 'mkv'])) {
      return 'video';
    }

    return 'image';
  }

  protected function getProductImageUrls(Product $product): array
  {
    $urls = [];

    // Основное фото — position 0
    $mainPath = $product->img?->path;
    if ($mainPath) {
      $urls[] = PathNormalizer::url($mainPath);
    }

    // Слайдер (доп. фото) — position 1, 2, 3...
    foreach ($product->images as $image) {
      $path = $image->path;
      if ($path) {
        $urls[] = PathNormalizer::url($path);
      }
    }

    return $urls;
  }

  // Методы getShopCode и resolveShopId перенесены в трейт ResolvesShopCode

  /**
   * Найти категорию БЕЗ учёта shop_code
   *
   * Категории — это справочники, общие для всех магазинов.
   * Поэтому ищем только по (source, external_id), игнорируя shop_code.
   *
   * @param string $source Источник события
   * @param string $externalId Внешний ID категории
   * @return int|null Локальный ID категории или null
   */
  protected function resolveCategoryWithoutShopCode(string $source, string $externalId): ?int
  {
    $metadata = EventSyncMetadata::where('entity_type', 'categories')
      ->where('source', $source)
      ->where('external_id', $externalId)
      ->first();

    if ($metadata && $metadata->entity_id) {
      Log::debug('Category resolved without shop_code scope', [
        'external_id' => $externalId,
        'source' => $source,
        'local_id' => $metadata->entity_id,
        'metadata_shop_code' => $metadata->shop_code,
      ]);
      return $metadata->entity_id;
    }

    return null;
  }

  /**
   * Разрешить external_id произвольного product по его локальному id.
   * Используется для товарных ссылок SupplementValue (product_external_id).
   * Если metadata не найдена — возвращает null (товар-добавка не синхронизирован).
   */
  protected function resolveProductExternalId(int $productId): ?string
  {
    $metadata = EventSyncMetadata::where([
      'entity_type' => 'products',
      'entity_id'   => $productId,
    ])->first();

    return $metadata?->external_id;
  }

  /**
   * Разрешить external_id parent'а для события variant'а.
   */
  protected function resolveParentExternalId(Product $product): ?string
  {
    if (!$product->parent_id) {
      return null;
    }

    $metadata = EventSyncMetadata::where([
      'entity_type' => 'products',
      'entity_id' => $product->parent_id,
    ])->first();

    return $metadata ? $metadata->external_id : (string) $product->parent_id;
  }

  /**
   * Разрешить источник parent'а.
   */
  protected function resolveParentSource(Product $product): string
  {
    if (!$product->parent_id) {
      return (string) config('rabbitmq-events.source');
    }

    $metadata = EventSyncMetadata::where([
      'entity_type' => 'products',
      'entity_id' => $product->parent_id,
    ])->first();

    return $metadata ? $metadata->source : (string) config('rabbitmq-events.source');
  }

  /**
   * Формирует список вариативных атрибутов семейства parent'а.
   *
   * @return array<int, array{code: string, label: string, position: int}>
   */
  protected function buildConfigurableAttributes(Product $product): array
  {
    if (!$product->is_configurable) {
      return [];
    }

    $category = $product->categories->first();
    if (!$category || !$category->attribute_family_id) {
      return [];
    }

    $rows = \App\Models\AttributeFamilyAttribute::query()
      ->where('attribute_family_id', $category->attribute_family_id)
      ->where('is_configurable', true)
      ->orderBy('position')
      ->with('attribute')
      ->get();

    return $rows
      ->map(function (\App\Models\AttributeFamilyAttribute $row) {
        $attribute = $row->attribute;
        if (!$attribute) {
          return null;
        }

        return [
          'code' => $attribute->code ?? $this->generateAttributeCode($attribute->title),
          'label' => $attribute->title,
          'position' => (int) $row->position,
        ];
      })
      ->filter()
      ->values()
      ->toArray();
  }

  /**
   * Трансформировать атрибуты товара в формат события
   * Возвращает массив объектов [{code, name, value, description, group}]
   */
  protected function transformAttributes(Product $product): array
  {
    if (!$product->relationLoaded('attributes')) {
      return [];
    }

    // Исторические гонки консьюмеров оставили дубли пар (product_id,
    // attribute_id) — в payload идёт одна строка на атрибут, побеждает
    // самая свежая (updated_at, tie-break id).
    $deduped = [];
    foreach ($product->attributes as $productAttribute) {
      $key = (int) $productAttribute->attribute_id;
      $current = $deduped[$key] ?? null;
      if ($current === null || $this->isFresherAttributeRow($productAttribute, $current)) {
        $deduped[$key] = $productAttribute;
      }
    }

    $result = [];
    foreach ($deduped as $productAttribute) {
      if (!$productAttribute->attribute) {
        continue;
      }

      $attribute = $productAttribute->attribute;

      // Получить значение в зависимости от типа
      $value = $productAttribute->string_value
        ?? $productAttribute->text_value
        ?? $productAttribute->int_value
        ?? $productAttribute->float_value;

      // Для вариативных атрибутов заполняется через attribute_value_id
      $attributeValueId = $productAttribute->attribute_value_id ?? null;
      $attributeValueCode = null;
      if ($attributeValueId !== null) {
        $attributeValue = $productAttribute->attributeValue()->first()
          ?? \App\Models\AttributeValue::find($attributeValueId);
        if ($attributeValue) {
          $attributeValueCode = $attributeValue->code ?? null;
          if ($value === null) {
            $value = $attributeValue->title;
          }
        }
      }

      if ($value !== null || $attributeValueId !== null) {
        $entry = [
          'code' => $attribute->code ?? $this->generateAttributeCode($attribute->title),
          'name' => $attribute->title,
          'value' => $value,
          'description' => $attribute->description,
          'group' => $this->resolveAttributeGroup($attribute),
        ];

        if ($attributeValueId !== null) {
          $entry['attribute_value_id'] = (int) $attributeValueId;
          $entry['attribute_value_code'] = $attributeValueCode;
        }

        $result[] = $entry;
      }
    }

    return $result;
  }

  /**
   * Семантическая группа атрибута для payload (вкладки экрана «Характеристики»
   * в мобилке). Cross-DB контракт — строка, не внутренний category_type_id.
   */
  private function resolveAttributeGroup(Attribute $attribute): string
  {
    return match ((int) $attribute->category_type_id) {
      Type::ATTRIBUTE_COMPOSITION => 'composition',
      Type::ATTRIBUTE_PACKAGING => 'packaging',
      default => 'general',
    };
  }

  /**
   * true, если строка $candidate свежее строки $current
   * (по updated_at, при равенстве — по id).
   */
  private function isFresherAttributeRow(ProductAttribute $candidate, ProductAttribute $current): bool
  {
    $candidateTime = $candidate->updated_at?->getTimestamp() ?? 0;
    $currentTime = $current->updated_at?->getTimestamp() ?? 0;

    if ($candidateTime !== $currentTime) {
      return $candidateTime > $currentTime;
    }

    return (int) $candidate->id > (int) $current->id;
  }

  /**
   * Маппинг русских названий КБЖУ на английские коды.
   */
  protected const NUTRITION_NAME_MAP = [
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
   * Генерировать code из названия атрибута.
   * Для известных КБЖУ-названий возвращает английский код.
   */
  protected function generateAttributeCode(string $title): string
  {
    $normalized = mb_strtolower(trim($title));

    if (isset(self::NUTRITION_NAME_MAP[$normalized])) {
      return self::NUTRITION_NAME_MAP[$normalized];
    }

    return Str::slug($title, '_');
  }

  /**
   * Маппинг unit_type_id → внешний enum
   */
  protected function mapUnitTypeToExternal(?int $unitTypeId): string
  {
    $mapping = array_flip($this->unitTypeMapping);
    return $mapping[$unitTypeId ?? Type::UNIT_PIECE] ?? 'piece';
  }

  /**
   * unit_type события — единица остатка (quantity) по базовой единице 1С: кг → kg,
   * литр → l, грамм → g, мл → ml, штука → piece. Витрина переводит остаток ×1000
   * только для kg/l. Раньше для любого весового (unit_type_id = UNIT_FOOD)
   * уходило 'kg', и остаток товара с базой «граммы» раздувался ×1000.
   * Товар без единиц — прежний маппинг по unit_type_id.
   */
  protected function stockUnitTypeToExternal(Product $product): string
  {
    if ($product->base_unit_type_id === null && $product->info_weight_type_id === null) {
      return $this->mapUnitTypeToExternal($product->unit_type_id);
    }

    return match (StockUnits::stockUnit($product)) {
      Type::INFO_WEIGHT_KILOGRAM => 'kg',
      Type::INFO_WEIGHT_LITER => 'l',
      Type::INFO_WEIGHT_GRAM => 'g',
      Type::INFO_WEIGHT_M_LITER => 'ml',
      default => 'piece',
    };
  }

  /**
   * Маппинг внешнего enum → unit_type_id
   */
  protected function mapUnitTypeFromExternal(string $unitType): int
  {
    // Весовые/объёмные (kg/g/l/ml) → UNIT_FOOD; штучные → UNIT_PIECE.
    return match ($unitType) {
      'kg', 'g', 'l', 'ml' => Type::UNIT_FOOD,
      'piece' => Type::UNIT_PIECE,
      default => Type::UNIT_PIECE,
    };
  }

  /**
   * Маппинг внешнего unit_type (kg/g/l/ml/piece) → info_weight_type_id
   * (сырая единица 1С). Используется для base_unit_type_id, когда событие
   * не несёт measurement_unit.
   */
  protected function mapUnitTypeToInfoWeightType(string $unitType): int
  {
    return match ($unitType) {
      'kg' => Type::INFO_WEIGHT_KILOGRAM,
      'g' => Type::INFO_WEIGHT_GRAM,
      'l' => Type::INFO_WEIGHT_LITER,
      'ml' => Type::INFO_WEIGHT_M_LITER,
      default => Type::INFO_WEIGHT_PIECE,
    };
  }

  /**
   * Получить шаг количества из Price товара.
   */
  protected function getProductStep(Product $product, ?object $relatedModel = null): int
  {
    $shopId = null;

    if ($relatedModel && isset($relatedModel->shop_id)) {
      $shopId = $relatedModel->shop_id;
    } elseif ($relatedModel instanceof Shop) {
      $shopId = $relatedModel->id;
    } elseif ($product->relationLoaded('prices') && $product->prices->isNotEmpty()) {
      return (int) ($product->prices->first()->step ?? 1);
    }

    $price = Price::where('product_id', $product->id)
      ->where('type_id', Type::PRICE_DEFAULT)
      ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
      ->first();

    return (int) ($price->step ?? 1);
  }

  /**
   * Маппинг info_weight_type_id → measurement_unit string для RabbitMQ.
   */
  protected function mapInfoWeightTypeToMeasurementUnit(?int $infoWeightTypeId): string
  {
    return match ($infoWeightTypeId) {
      Type::INFO_WEIGHT_GRAM => 'gram',
      Type::INFO_WEIGHT_KILOGRAM => 'kilogram',
      Type::INFO_WEIGHT_LITER => 'liter',
      Type::INFO_WEIGHT_M_LITER => 'milliliter',
      default => 'piece',
    };
  }

  /**
   * Обратный маппинг measurement_unit string → info_weight_type_id.
   */
  public function mapMeasurementUnitToInfoWeightType(string $measurementUnit): int
  {
    return match ($measurementUnit) {
      'gram' => Type::INFO_WEIGHT_GRAM,
      'kilogram' => Type::INFO_WEIGHT_KILOGRAM,
      'liter' => Type::INFO_WEIGHT_LITER,
      'milliliter' => Type::INFO_WEIGHT_M_LITER,
      default => Type::INFO_WEIGHT_PIECE,
    };
  }

  // =========================================================================
  // CUSTOM EVENT HANDLERS
  // =========================================================================

  /**
   * Обработчик события price_updated
   *
   * Вызывается автоматически при получении события с event_type="price_updated"
   * Обновляет только цену товара, не затрагивая остальные поля
   *
   * @param Model $product Модель товара
   * @param array $eventData Данные события
   * @return void
   */
  public function processPriceUpdatedEvent(Model $product, array $eventData): void
  {
    $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

    // Сетевой каталог: цена по природе пер-магазинная, и событию без shop_code
    // некуда лечь — fallback-магазин из конфига приписал бы её случайной точке.
    // Отбрасываем с логом, а не бросаем исключение: ретрай тут не поможет,
    // событие бы просто ушло в DLQ. Трактовка «магазин не указан» та же, что в
    // processRelatedData: непустая строка (пустой код "0" остаётся валидным).
    if (($eventData['shop_code'] ?? '') === '' && app(NetworkCatalogMode::class)->isEnabled()) {
      Log::warning('price_updated without shop_code dropped (network_catalog)', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);

      return;
    }

    if (!isset($eventData['price'])) {
      Log::warning('Price not provided in price_updated event', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);
      return;
    }

    // Само-исцеление сырой единицы 1С (base_unit_type_id). price_updated теперь
    // несёт measurement_unit, поэтому залипшая единица товара (например кг-товар
    // с base_unit_type_id=грамм) корректируется при КАЖДОМ обновлении цены, а не
    // только при редком полном product.updated. Без этого нормализация цены к
    // наименьшей единице (processPrice/CategoryQuantumService) делит на неверный
    // baseSize и раздувает price_per_kg/value в 1000 раз (LASTOCHKI-JAMI-11200).
    // info_weight_type_id (единица кванта) НЕ трогаем — её ведёт квант-пересчёт.
    if (isset($eventData['measurement_unit'])) {
      $mappedBaseUnit = $this->mapMeasurementUnitToInfoWeightType((string) $eventData['measurement_unit']);

      if ((int) $product->base_unit_type_id !== $mappedBaseUnit) {
        Product::withoutEvents(function () use ($product, $mappedBaseUnit) {
          $product->forceFill(['base_unit_type_id' => $mappedBaseUnit])->save();
        });
      }
    }

    $this->processPrice(
      $product,
      (float) $eventData['price'],
      $shopCode,
      isset($eventData['discount_price']) ? (float) $eventData['discount_price'] : null,
      $eventData['unit_type'] ?? null
    );

    // Цены от количества (MID-308): лесенка того же магазина, что и цена.
    // До квант-пересчёта ниже: он может опубликовать ProductUpdated, и в
    // карточном событии должна уехать уже новая лесенка.
    $this->processPriceTiers($product, $eventData, $this->resolveShopId($shopCode));

    // Закупочная цена для аналитики (products.cost_price)
    if (array_key_exists('cost_price', $eventData)) {
      $newCost = $eventData['cost_price'] !== null ? (float) $eventData['cost_price'] : null;
      if ($product->cost_price === null || (float) $product->cost_price !== $newCost) {
        Product::withoutEvents(fn () => $product->forceFill(['cost_price' => $newCost])->save());
      }
    }

    // Пересчёт цены за квант по витринным категориям товара
    // (LASTOCHKI-JAMI-11200 v2): сырая цена за кг из события → цена кванта.
    // Эффективная (акционная, если есть) цена — иначе applyToProduct() затрёт
    // акционное value, только что записанное processPrice() выше сырой базовой ценой.
    app(\App\Services\Quantum\CategoryQuantumService::class)
      ->applyToProduct($product->fresh(), $this->resolveShopId($shopCode), $this->resolveEffectivePrice(
        (float) $eventData['price'],
        $eventData['discount_price'] ?? null
      ));

    Log::info('Price updated from custom event', [
      'product_id' => $product->id,
      'price' => $eventData['price'],
      'discount_price' => $eventData['discount_price'] ?? null,
      'shop_code' => $shopCode,
    ]);

    // Пересчёт % заполненности: цена участвует в формуле (LASTOCHKI-JAMI-11653)
    app(\App\Services\ProductFillPercentService::class)->recalculate($product);
  }

  /**
   * Обработчик события stock_updated (quantity_updated)
   *
   * Вызывается автоматически при получении события с event_type="stock_updated"
   * Обновляет только остатки товара, не затрагивая остальные поля
   *
   * @param Model $product Модель товара
   * @param array $eventData Данные события
   * @return void
   */
  public function processStockUpdatedEvent(Model $product, array $eventData): void
  {
    $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

    // Сетевой каталог: остаток пер-магазинный ровно как цена — событие без
    // shop_code отбрасываем с логом (см. processPriceUpdatedEvent).
    if (($eventData['shop_code'] ?? '') === '' && app(NetworkCatalogMode::class)->isEnabled()) {
      Log::warning('stock_updated without shop_code dropped (network_catalog)', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);

      return;
    }

    if (!isset($eventData['quantity'])) {
      Log::warning('Quantity not provided in stock_updated event', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);
      return;
    }

    $this->processQuantity($product, (float) $eventData['quantity'], $shopCode);

    Log::info('Stock updated from custom event', [
      'product_id' => $product->id,
      'quantity' => $eventData['quantity'],
      'shop_code' => $shopCode,
    ]);
  }

  /**
   * Обработчик события quantity_updated (алиас для stock_updated)
   *
   * @param Model $product Модель товара
   * @param array $eventData Данные события
   * @return void
   */
  public function processQuantityUpdatedEvent(Model $product, array $eventData): void
  {
    $this->processStockUpdatedEvent($product, $eventData);
  }

  /**
   * Обработчик события category_changed
   *
   * Вызывается автоматически при получении события с event_type="category_changed"
   * Обновляет только привязку категории товара
   *
   * @param Model $product Модель товара
   * @param array $eventData Данные события
   * @return void
   */
  public function processCategoryChangedEvent(Model $product, array $eventData): void
  {
    $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

    if (empty($eventData['category_id']) || empty($eventData['category_source'])) {
      Log::warning('Category data not provided in category_changed event', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);
      return;
    }

    $this->processCategory(
      $product,
      $eventData['category_id'],
      $eventData['category_source'],
      $shopCode
    );

    Log::info('Category changed from custom event', [
      'product_id' => $product->id,
      'category_id' => $eventData['category_id'],
      'category_source' => $eventData['category_source'],
      'shop_code' => $shopCode,
    ]);

    // Пересчёт % заполненности: категория участвует в формуле (LASTOCHKI-JAMI-11653)
    app(\App\Services\ProductFillPercentService::class)->recalculate($product);
  }
}
