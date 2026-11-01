<?php

namespace App\Transformers;

use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\Status;
use App\Models\Type;
use App\Support\NetworkCatalogMode;
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
    'updated' => ['event_id', 'id', 'source'],
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
  ];

  /**
   * Маппинг типов единиц измерения exchange.md → Type константы
   */
  protected array $unitTypeMapping = [
    'kg' => Type::UNIT_GRAM,      // весовой
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
   * Конвертировать Product в событие для публикации
   */
  public function toEvent(Model $product, ?object $relatedModel = null, ?string $eventId = null): array
  {
    // DEBUG: log what relatedModel actually is
    Log::info('ProductEventTransformer::toEvent called', [
      'product_id' => $product->getKey(),
      'relatedModel_class' => $relatedModel ? get_class($relatedModel) : null,
      'instanceof_check' => $relatedModel instanceof \App\Events\ProductStockChanged,
      'newQuantity' => ($relatedModel instanceof \App\Events\ProductStockChanged) ? $relatedModel->newQuantity : 'N/A',
      'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1] ?? null,
    ]);

    // Если relatedModel является событием ProductStockChanged, используем специальную логику
    if ($relatedModel instanceof \App\Events\ProductStockChanged) {
      return $this->toStockChangedEvent($product, $relatedModel, $eventId);
    }

    // Fallback: если instanceof не сработал, но объект имеет свойства ProductStockChanged
    // (защита от потенциальных проблем с autoloader/opcache при HTTP-вызовах)
    if ($relatedModel && property_exists($relatedModel, 'newQuantity') && property_exists($relatedModel, 'oldQuantity')) {
      Log::warning('ProductEventTransformer: instanceof failed but properties match ProductStockChanged', [
        'product_id' => $product->getKey(),
        'relatedModel_class' => get_class($relatedModel),
        'expected_class' => \App\Events\ProductStockChanged::class,
      ]);
      return $this->toStockChangedEvent($product, $relatedModel, $eventId);
    }

    // Стандартная логика для других событий (created, deleted)
    // Получить цену товара для магазина
    $priceData = $this->getProductPriceData($product, $relatedModel);
    $price = $priceData['price'];
    $discountPrice = $priceData['discount_price'];

    // Получить остатки товара для магазина
    $quantity = $this->getProductQuantity($product, $relatedModel);

    // Получить metadata для товара (если товар пришёл из внешнего источника, например 1C)
    $productMetadata = EventSyncMetadata::where([
        'entity_type' => 'products',
        'entity_id' => $product->id,
    ])->first();

    // Если есть metadata — товар пришёл из внешнего источника
    $productId = $productMetadata ? $productMetadata->external_id : (string)$product->id;
    $productSource = $productMetadata ? $productMetadata->source : config('rabbitmq-events.source');

    $eventData = [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => ($product->deleted_at ?? $product->updated_at) ?? now(),
      'id' => $productId,
      'shop_code' => $this->resolveShopCode($relatedModel, $product),
      'source' => $productSource,

      // Обязательные поля
      'sku' => $product->articul ?? (string)$product->id,
      'title' => $product->title,
      'price' => (float)$price,
      'unit_type' => $this->mapUnitTypeToExternal($product->unit_type_id),

      // Опциональные поля
      'barcodes' => $product->barcode ? [$product->barcode] : [],
      'discount_price' => $discountPrice,
      'image_url' => $this->getProductImageUrl($product),
      // Остаток весового — кг с дробной частью (0.828); int усекал до 0.
      'quantity' => round($quantity, 3),
      'description' => $product->description,
      'weight' => $product->weight,
      'active' => $product->status_id === Status::CODE_ACTIVE,

      // Timestamps
      'created_at' => $product->created_at,
      'updated_at' => $product->updated_at,
      'deleted_at' => $product->deleted_at,
    ];

    // В delivery_back у Product нет связи categories, поэтому пропускаем эту логику

    return $eventData;
  }

  /**
   * Конвертировать ProductStockChanged в событие для публикации
   */
  protected function toStockChangedEvent(Product $product, \App\Events\ProductStockChanged $event, ?string $eventId = null): array
  {
    // Получить metadata для товара
    $productMetadata = EventSyncMetadata::where([
        'entity_type' => 'products',
        'entity_id' => $product->id,
    ])->first();

    $productId = $productMetadata ? $productMetadata->external_id : (string)$product->id;
    $productSource = $productMetadata ? $productMetadata->source : config('rabbitmq-events.source');

    $eventData = [
      'event_id' => $eventId ?? Str::uuid()->toString(),
      'event_timestamp' => now(),
      'event_type' => 'stock_updated',
      'id' => $productId,
      'source' => $productSource,
      'shop_code' => $event->shopCode ?? config('rabbitmq-events.shop_code'),

      // Обязательные поля для stock_updated
      'quantity' => $event->newQuantity,
      'old_quantity' => $event->oldQuantity,

      // Дополнительные поля для идентификации
      'sku' => $product->articul ?? (string)$product->id,
      'title' => $product->title,
    ];

    return $eventData;
  }

  /**
   * Конвертировать событие в атрибуты Product для create/update
   */
  public function fromEvent(array $data): array
  {
    return [
      'articul' => $data['sku'],
      'barcode' => $data['barcodes'][0] ?? null, // Основной штрихкод
      'title' => $data['title'],
      'description' => $data['description'] ?? null,
      'meta_title' => $data['meta_title'] ?? null,
      'meta_description' => $data['meta_description'] ?? null,
      'meta_keywords' => $data['meta_keywords'] ?? null,
      'weight' => $data['weight'] ?? null,
      // 'nds' - поле не существует в таблице products
      'unit_type_id' => $this->mapUnitTypeFromExternal($data['unit_type'] ?? 'piece'),
      'status_id' => ($data['active'] ?? true) ? Status::CODE_ACTIVE : Status::CODE_NOT_PUBLISHED,
      'is_culinary' => (bool) ($data['is_culinary'] ?? false),
      'sugar_free' => (bool) ($data['sugar_free'] ?? false),
      'on_search_page' => (bool) ($data['on_search_page'] ?? false),
    ];
  }

  /**
   * Найти существующий товар по бизнес-ключу (SKU/articul)
   *
   * Вызывается Consumer'ом перед созданием новой сущности.
   * Если товар найден — он будет привязан к metadata вместо создания дубликата.
   *
   * ВАЖНО: Ищем включая soft-deleted записи (withTrashed), чтобы:
   * 1. Не создавать дубликаты удалённых товаров
   * 2. При необходимости "воскресить" удалённый товар
   *
   * @param array $eventData Данные события
   * @param array $transformedData Трансформированные данные
   * @return Model|null Найденный товар или null
   */
  public function findExistingEntity(array $eventData, array $transformedData): ?Model
  {
    $sku = $eventData['sku'] ?? null;

    if (!$sku) {
      return null;
    }

    // withTrashed() — ищем включая soft-deleted записи
    $existing = Product::withTrashed()->where('articul', $sku)->first();

    if ($existing) {
      // Если товар был удалён — восстанавливаем его
      if ($existing->trashed()) {
        $existing->restore();
        Log::info('Restored soft-deleted product by SKU', [
          'sku' => $sku,
          'product_id' => $existing->id,
          'external_id' => $eventData['id'] ?? 'unknown',
        ]);
      } else {
        Log::info('Found existing product by SKU for deduplication', [
          'sku' => $sku,
          'product_id' => $existing->id,
          'external_id' => $eventData['id'] ?? 'unknown',
        ]);
      }
    }

    return $existing;
  }

  /**
   * Хук: ПЕРЕД созданием товара
   * Разрешаем category_id если указан
   */
  public function beforeCreate(array $eventData, array $transformedData): ?array
  {
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

    return $transformedData;
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

    $this->processRelatedData($product, $eventData, true);

    Log::info('✅ ProductEventTransformer::afterCreate DONE', [
      'product_id' => $product->id,
      'title' => $product->title,
    ]);
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
    $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');
    $shopId = $this->resolveShopId($shopCode);

    // Сетевой каталог: карточка товара глобальная, её владелец — якорный магазин,
    // и ведёт products.shop_id только admin_back. Событие магазина не должно
    // перебрасывать карточку из якоря в магазин-источник события.
    if (!NetworkCatalogMode::isEnabled()) {
      // Обновить shop_id на товаре (всегда при создании, при изменении — при обновлении)
      if ($isCreate || $product->shop_id !== $shopId) {
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
    }

    // 1. Обработать цену (с учётом скидки, step, price_per_kg)
    if (isset($eventData['price'])) {
      $this->processPrice(
        $product,
        $eventData['price'],
        $shopCode,
        $eventData['discount_price'] ?? null,
        $eventData['unit_type'] ?? null
      );
    }

    // 2. Обработать остатки
    if (isset($eventData['quantity'])) {
      $this->processQuantity($product, $eventData['quantity'], $shopCode);
    }

    // 3. Обработать штрихкоды
    if (!empty($eventData['barcodes']) && is_array($eventData['barcodes'])) {
      $this->processBarcodes($product, $eventData['barcodes']);
    }

    // 4. Обработать категорию
    if (!empty($eventData['category_id']) && !empty($eventData['category_source'])) {
      $this->processCategory($product, $eventData['category_id'], $eventData['category_source'], $shopCode);
    }

    // 5. Обработать атрибуты
    if (!empty($eventData['attributes']) && is_array($eventData['attributes'])) {
      $this->processAttributes($product, $eventData['attributes']);
    }

    // 6. Скачать изображение (если есть)
    if (!empty($eventData['image_url']) && !$product->img) {
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
  protected function processPrice(Product $product, float $price, string $shopCode, ?float $discountPrice = null, ?string $unitType = null): void
  {
    $shopId = $this->resolveShopId($shopCode);

    Price::withoutEvents(function () use ($product, $shopId, $price, $discountPrice, $unitType) {
      \DB::transaction(function () use ($product, $shopId, $price, $discountPrice, $unitType) {
        // ✅ Атомарный upsert с блокировкой (теперь учитываем shop_id)
        $priceModel = Price::lockForUpdate()
          ->where('product_id', $product->id)
          ->where('shop_id', $shopId)
          ->where('type_id', Type::PRICE_DEFAULT)
          ->first();

        // Определить step и price_per_kg
        $step = ($unitType === 'kg') ? 1000 : 1;
        $pricePerKg = null;

        if ($unitType === 'kg' && $discountPrice > 0) {
          // Цена за кг = скидочная цена * 1000 / 1000 = скидочная цена
          $pricePerKg = $discountPrice;
        } elseif ($unitType === 'kg') {
          $pricePerKg = $price;
        }

        // Данные для сохранения (включая shop_id)
        $priceData = [
          'value' => $discountPrice > 0 ? $discountPrice : $price,
          'price_without_discount' => $discountPrice > 0 ? $price : null,
          'price_per_kg' => $pricePerKg,
          'step' => $step,
          'shop_id' => $shopId,
        ];

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
   * Сохраняет первый штрихкод в поле barcode модели Product
   */
  protected function processBarcodes(Product $product, array $barcodes): void
  {
    $primaryBarcode = $barcodes[0] ?? null;

    if ($primaryBarcode) {
      $product->withoutEvents(function () use ($product, $primaryBarcode) {
        $product->update(['barcode' => $primaryBarcode]);
      });
    }

    Log::debug('Barcode processed', [
      'product_id' => $product->id,
      'barcode' => $primaryBarcode,
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
      $priceModel = Price::where('product_id', $product->id)
        ->where('type_id', Type::PRICE_DEFAULT)
        ->first();
    } else {
      // Fallback: цена по умолчанию
      $priceModel = $product->defaultPrice;
    }

    if (!$priceModel) {
      return ['price' => 0, 'discount_price' => null];
    }

    // Если есть price_without_discount - значит есть скидка
    if ($priceModel->price_without_discount > 0) {
      return [
        'price' => (float)$priceModel->price_without_discount, // Оригинальная цена
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
   * Получить остатки товара для магазина
   */
  protected function getProductQuantity(Product $product, ?Model $shop = null): float
  {
    if ($shop instanceof Shop) {
      $remain = Remain::where('product_id', $product->id)
        ->where('shop_id', $shop->id)  // Добавляем фильтр по shop_id
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
   * Трансформировать атрибуты товара в формат события
   * Возвращает массив объектов [{code, name, value}]
   */
  protected function transformAttributes(Product $product): array
  {
    if (!$product->relationLoaded('attributes')) {
      return [];
    }

    $result = [];
    foreach ($product->attributes as $productAttribute) {
      if (!$productAttribute->attribute) {
        continue;
      }

      $attribute = $productAttribute->attribute;

      // Получить значение в зависимости от типа
      $value = $productAttribute->string_value
        ?? $productAttribute->text_value
        ?? $productAttribute->int_value
        ?? $productAttribute->float_value;

      if ($value !== null) {
        $result[] = [
          'code' => $attribute->code ?? $this->generateAttributeCode($attribute->title),
          'name' => $attribute->title,
          'value' => $value,
        ];
      }
    }

    return $result;
  }

  /**
   * Генерировать code из названия атрибута
   */
  protected function generateAttributeCode(string $title): string
  {
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
   * Маппинг внешнего enum → unit_type_id
   */
  protected function mapUnitTypeFromExternal(string $unitType): int
  {
    return $this->unitTypeMapping[$unitType] ?? Type::UNIT_PIECE;
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

    if (!isset($eventData['price'])) {
      Log::warning('Price not provided in price_updated event', [
        'product_id' => $product->id,
        'event_id' => $eventData['event_id'] ?? 'unknown',
      ]);
      return;
    }

    $this->processPrice(
      $product,
      (float) $eventData['price'],
      $shopCode,
      isset($eventData['discount_price']) ? (float) $eventData['discount_price'] : null,
      $eventData['unit_type'] ?? null
    );

    Log::info('Price updated from custom event', [
      'product_id' => $product->id,
      'price' => $eventData['price'],
      'discount_price' => $eventData['discount_price'] ?? null,
      'shop_code' => $shopCode,
    ]);
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
  }
}
