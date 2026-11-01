<?php

namespace App\GraphQL\Mutations\Product;

use App\Models\Attribute;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Remain;
use App\Models\StockModeChange;
use App\Models\Type;
use App\NetworkCatalog\Support\NetworkCatalogMode;
use App\Services\Stock\StockMode;
use App\Services\Stock\StockOverrideService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class ProductUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $args = $this->resolveNetworkCatalogShop($args);

    // Цена карточки. В сетевом каталоге цену ведёт 1С пер-магазин, у карточки
    // нет «своей» цены — админка сохраняет её без price, и тогда строки prices
    // не трогаем вовсе. Вне сетевого режима price обязателен (валидатор).
    $priceGiven = array_key_exists('price', $args) && $args['price'] !== null;

    // Цены от количества (MID-308): лесенка магазина товара. null — аргумент
    // не передан или равен null, ступени не трогаем. Разбор и проверка «лесенка
    // требует цены» идут до любых записей: отказ не должен оставить карточку
    // полусохранённой (для ProductCreate товар при отказе не создаётся).
    $tierService = app(\App\Services\ProductShopPrice\ProductShopPriceService::class);
    $priceTiers = $tierService->ladderFromArgs($args);

    // MID-308: без резолвленного магазина лесенку писать некуда. Раньше мутация
    // молча проглатывала аргумент (syncPriceTiers() ниже просто не вызывался) и
    // отвечала 200 OK — например, у сотрудника-партнёра без привязки к магазину
    // (resolveNetworkCatalogShop() выше не находит shop_id). Явный отказ вместо
    // тихой потери, до любых записей.
    if ($priceTiers !== null && empty($args['shop_id'])) {
      throw ValidationException::withMessages([
        'price_tiers' => [\App\Rules\PriceTiersRule::MESSAGE_SHOP_REQUIRED],
      ]);
    }

    $tierService->assertLadderHasPrice(
      $priceTiers,
      $priceGiven,
      isset($args['id']) ? (int) $args['id'] : null,
      !empty($args['shop_id']) ? (int) $args['shop_id'] : null
    );

    // Прежняя отображаемая цена (prices.value до мутаций) — для защиты от
    // удвоения базовой цены 1С при смене шага кванта. Если присланная цена
    // совпадёт с ней, applyManualPrice не реконструирует price_per_small_unit,
    // а лишь пересчитает цену порции по новому шагу.
    $previousDisplayed = isset($args['id'], $args['shop_id'])
      ? Price::where('product_id', $args['id'])
        ->where('shop_id', $args['shop_id'])
        ->where('type_id', Type::PRICE_DEFAULT)
        ->value('value')
      : null;

    // Фиксированный лимит: единица из семейства товара — до любых записей.
    if (($args['stock_mode'] ?? null) === StockMode::FIXED) {
      $unitProduct = isset($args['id']) ? Product::find($args['id']) : null;
      $unitProduct ??= (new Product())->forceFill([
        'info_weight_type_id' => $args['info_weight_type_id'] ?? null,
      ]);
      app(StockOverrideService::class)->assertFixedUnitFits($unitProduct, $args['fixed_stock_unit_type_id'] ?? null);
    }

    $product = $this->updateOrCreateProduct($args);

    $this->applyStockMode($args, $product);

    if (array_key_exists('category_ids', $args)) {
      $this->syncCategories($product, $args['category_ids'] ?? []);
    }

    if ($priceGiven) {
      $this->updatePrices($args['price'], $args['shop_id'], $product);
    }

    // MID-308: лесенка пишется тому же магазину, что и цена, и до публикации
    // ProductUpdated ниже — карточное событие обязано нести уже новую лесенку.
    if ($priceTiers !== null && !empty($args['shop_id'])) {
      $tierService->syncPriceTiers($product->id, (int) $args['shop_id'], $priceTiers);
    }

    $this->updateQuantity(self::stockQuantityArg($args), $args['shop_id'] ?? null, $product);

    $this->updateAttributes($args['attributes'] ?? [], $product);

    $this->updateImg($args['img'] ?? null, $product);

    $this->updateSliderImages($args['images'] ?? null, $product);

    $this->updateCookTime($args, $product);

    $this->updateSupplements($args, $product);

    // Введённая в карточке цена — авторитетная: applyManualPrice восстанавливает
    // из неё price_per_small_unit / price_per_kg и цену кванта. Раньше тут стоял
    // applyToProduct(null), который пересчитывал цену из СОХРАНЁННОГО
    // price_per_small_unit и у товара без цены из 1С (perSmall=0) откатывал
    // только что введённую менеджером цену к нулю.
    if ($priceGiven) {
      app(\App\Services\Quantum\CategoryQuantumService::class)
        ->applyManualPrice(
          $product->fresh(),
          (int) $args['shop_id'],
          (float) $args['price'],
          dispatchEvent: false,
          previousDisplayed: $previousDisplayed !== null ? (float) $previousDisplayed : null,
        );
    }

    $product->refresh();
    $product->load('supplements.values');

    // Карточка вперёд офферов: витрина строит store_products только для уже
    // известного ей товара, а пер-магазинное price_updated/stock_updated на
    // неизвестный товар consumer отбрасывает с ack и без ретрая («Custom event
    // received for non-existent entity») — цена теряется навсегда. Раньше эта
    // публикация стояла в конце метода, и офферы обгоняли карточку.
    event(new \App\Events\ProductUpdated($product));

    // Сетевой каталог: карточное событие цену/остаток оффера не несёт —
    // витрина строит офферы только из пер-магазинных price_updated/
    // stock_updated. Без этой публикации товар, созданный в админке,
    // невидим в каталоге клиента (MID-226).
    $quantityGiven = self::stockQuantityArg($args) !== null;

    // MID-308: правка одних ступеней (без цены и остатка) тоже публикует оффер,
    // но только когда у пары есть цена: иначе ушло бы событие с price = 0.
    // Непустую лесенку без цены доводит сюда только уже существующая цена пары
    // (assertLadderHasPrice() выше пропускает её именно по этой причине) —
    // тогда оффер публикуется. Очистка ([]) цены не требует и сама по себе
    // ничего не публикует: pairHasPrice() ниже решает по факту наличия цены.
    $tiersGiven = $priceTiers !== null
      && !empty($args['shop_id'])
      && $tierService->pairHasPrice((int) $product->id, (int) $args['shop_id']);

    if (($priceGiven || $quantityGiven || $tiersGiven)
      && !empty($args['shop_id'])
      && app(NetworkCatalogMode::class)->isEnabled()
    ) {
      $offerShop = \App\Models\Shop::find($args['shop_id']);

      if ($offerShop !== null && !empty($offerShop->code)) {
        $freshProduct = $product->fresh();

        $offerPrice = $priceGiven
          ? (float) $args['price']
          : (float) (Price::where('product_id', $product->id)
            ->where('shop_id', $offerShop->id)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->value('value') ?? 0);

        $offerQuantity = $quantityGiven
          ? max(0, self::stockQuantityArg($args))
          : (float) (Remain::where('product_id', $product->id)
            ->where('shop_id', $offerShop->id)
            ->value('quantity') ?? 0);

        app(\App\Services\ProductShopPrice\ProductShopPriceService::class)
          ->publishOfferEvents($freshProduct, $offerShop, $offerPrice, $offerQuantity);
      }
    }

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * Магазин карточки, если он не пришёл в аргументах.
   *
   * - Не-глобальный админ: магазин из его привязки. Ровно то же значение потом
   *   проставляет updateOrCreateProduct(), но updatePrices/updateQuantity
   *   читают сырой аргумент и без этого падали на «Undefined array key».
   * - Глобальный админ в сетевом каталоге: якорный магазин сети — карточка одна
   *   на всю сеть, магазин в админке не выбирается.
   * - Глобальный админ вне сетевого режима: аргумент обязателен (валидатор),
   *   сюда мы не попадаем.
   *
   * Присланный shop_id не переопределяется никогда — все ранее работавшие
   * сценарии остаются байт-в-байт.
   */
  private function resolveNetworkCatalogShop(array $args): array
  {
    if (!empty($args['shop_id'])) {
      return $args;
    }

    $user = Auth::user();

    if ($user !== null && !$user->isGlobalScope()) {
      $shopId = $user->shops()->first()?->shop_id;

      if ($shopId !== null) {
        $args['shop_id'] = (int) $shopId;
      }

      return $args;
    }

    $networkCatalog = app(NetworkCatalogMode::class);

    if (!$networkCatalog->isEnabled()) {
      return $args;
    }

    $args['shop_id'] = $networkCatalog->anchorShopId();

    return $args;
  }

  /**
   * Режим остатков товара (исключение, переопределяет витринную категорию).
   * Допустимые значения: ignore | fixed | null («по категории», normal запрещён).
   * При смене режима пишет аудит в stock_mode_changes; после сохранения
   * материализует effective_stock_mode/effective_fixed_stock товара.
   */
  private function applyStockMode(array $args, Product $product): void
  {
    if (!array_key_exists('stock_mode', $args)) {
      return;
    }

    $newMode = $args['stock_mode']; // null | 'ignore' | 'fixed'

    if ($newMode !== null && !in_array($newMode, StockMode::productValues(), true)) {
      throw ValidationException::withMessages([
        'stock_mode' => ['Недопустимый режим остатков товара.'],
      ]);
    }

    $oldMode = $product->getOriginal('stock_mode');
    $oldFixed = $product->getOriginal('fixed_stock_quantity');

    $newFixedQty = $newMode === StockMode::FIXED ? ($args['fixed_stock_quantity'] ?? null) : null;
    $newFixedUnit = $newMode === StockMode::FIXED ? ($args['fixed_stock_unit_type_id'] ?? null) : null;

    Product::withoutEvents(function () use ($product, $newMode, $newFixedQty, $newFixedUnit) {
      $product->forceFill([
        'stock_mode' => $newMode,
        'fixed_stock_quantity' => $newFixedQty,
        'fixed_stock_unit_type_id' => $newFixedUnit,
      ])->save();
    });

    if ((string) $oldMode !== (string) $newMode) {
      StockModeChange::create([
        'entity_type' => 'product',
        'entity_id' => $product->id,
        'old_mode' => $oldMode,
        'new_mode' => $newMode,
        'old_fixed' => $oldFixed,
        'new_fixed' => $newFixedQty,
        'user_id' => Auth::id(),
      ]);
    }

    app(StockOverrideService::class)->applyToProduct($product->fresh(), dispatchEvent: false);
  }

  /**
   * Остаток из аргументов: stock_quantity (Float, база 1С с дробью) приоритетнее
   * устаревшего quantity (Int). null — остаток не передан.
   */
  private static function stockQuantityArg(array $args): ?float
  {
    $value = $args['stock_quantity'] ?? $args['quantity'] ?? null;

    return $value === null ? null : round((float) $value, 3);
  }

  private function updateQuantity(?float $quantity, ?int $shopId, Product $product): void
  {
    if ($quantity === null) {
      return;
    }

    if ($shopId === null) {
      return;
    }

    // Не updateOrCreate: строку могли мягко удалить («убрать товар из
    // магазина»), а UNIQUE-индекс её всё равно занимает — см. upsertQuantity().
    Remain::upsertQuantity($product->id, $shopId, max(0, $quantity));
  }

  private const ATTRIBUTE_VALUE_COLUMNS = [
    'string_value',
    'text_value',
    'int_value',
    'float_value',
    'attribute_value_id',
  ];

  private function updateAttributes(array $attributes, Product $product): void
  {
    $attributes_id = [];

    foreach ($attributes as $item) {
      $attribute = Attribute::find($item['attribute_id']);

      if (!$attribute) {
        continue;
      }

      // Протухший id (запись удалена параллельным сохранением) не должен
      // ронять мутацию — создаём запись заново.
      $product_attribute = (isset($item['id'])
        ? $product->attributes()->find($item['id'])
        : null) ?? new ProductAttribute(['product_id' => $product->id]);

      $product_attribute->attribute_id = $item['attribute_id'];

      $need_value = match ($attribute->type_id) {
        Type::ATTRIBUTE_STRING => 'string_value',
        Type::ATTRIBUTE_TEXT => 'text_value',
        Type::ATTRIBUTE_INT => 'int_value',
        Type::ATTRIBUTE_FLOAT => 'float_value',
        Type::ATTRIBUTE_SELECT_ONE => 'attribute_value_id',
      };

      $value = $this->resolveAttributeValue($item, $need_value);

      foreach (self::ATTRIBUTE_VALUE_COLUMNS as $column) {
        $product_attribute->$column = null;
      }

      $product_attribute->$need_value = $value;

      $product_attribute->save();

      $attributes_id[] = $product_attribute->id;
    }

    $product->attributes()
      ->whereNotIn((new ProductAttribute())->getTable() . '.id', $attributes_id)
      ->delete();
  }

  /**
   * Если клиент прислал значение не в колонке соответствующей `type_id`
   * (например `string_value` для TEXT-атрибута) — возвращаем первое
   * непустое скалярное значение. Для SELECT_ONE допустим только
   * `attribute_value_id`.
   */
  private function resolveAttributeValue(array $item, string $targetKey): mixed
  {
    if (array_key_exists($targetKey, $item) && $item[$targetKey] !== null && $item[$targetKey] !== '') {
      return $item[$targetKey];
    }

    if ($targetKey === 'attribute_value_id') {
      return null;
    }

    foreach (['string_value', 'text_value', 'int_value', 'float_value'] as $fallback) {
      if ($fallback === $targetKey) {
        continue;
      }

      if (!array_key_exists($fallback, $item)) {
        continue;
      }

      if ($item[$fallback] === null || $item[$fallback] === '') {
        continue;
      }

      return $item[$fallback];
    }

    return null;
  }

  /**
   * Редактирование главного изображения
   */
  protected function updateImg(?array $img, Product $product): void
  {
    if (isset($img) && !$product->joinImg) {
      if ($img != []) {
        $product->saveImg($img);
      }
    } else {
      $product->updateImg($product->joinImg, $img ?? null);
    }
  }

  /**
   * Редактирование слайдера изображений
   */
  protected function updateSliderImages(?array $images, Product $product): void
  {
    if (count($product->joinImages)) {
      $product->updateManyImages($product->joinImages(), $images ?? []);
    } elseif (isset($images)) {
      foreach ($images as $img) {
        if (!$img) {
          continue;
        }
        $product->saveImg($img, \App\Models\Status::CODE_SLIDER_IMG);
      }
    }
  }

  protected function updatePrices(float $price, int $shopId, Product $product): void
  {
    // Шаг (квант) сохраняем: ручное сохранение карточки не должно сбрасывать
    // квант весового товара в 1 (LASTOCHKI-JAMI-11200). Чтение и запись шага —
    // под блокировкой, чтобы не откатить шаг, который параллельно пишет
    // RabbitMQ-консьюмер (processPrice использует lockForUpdate).
    \DB::transaction(function () use ($price, $shopId, $product) {
      // withTrashed: строку могли мягко удалить через «убрать товар из
      // магазина», а UNIQUE-индекс её всё равно занимает, и вставка новой
      // падала с «Duplicate entry» — см. Price::findForUpsert().
      $existing = Price::findForUpsert($product->id, $shopId, Type::PRICE_DEFAULT);

      $existingStep = (int) ($existing?->step ?: 1);

      if ($existing !== null) {
        $existing->reviveWith([
          'value' => round($price, 2),
          'step' => $existingStep,
        ]);

        return;
      }

      // Новая строка цены. Магазин проставляем ТОЛЬКО в сетевом каталоге.
      //
      // Исторически shop_id не входил в $fillable модели Price, и при создании
      // строки из админки mass assignment его молча отбрасывал: цена оседала в
      // строке без магазина. Это баг (такую строку не находит ни следующий
      // upsert — каждое сохранение плодит дубль, — ни пер-магазинная выдача),
      // но чинить его в легаси нельзя: как только строка становится находимой
      // по (product_id, shop_id), начинает отрабатывать
      // CategoryQuantumService::applyToProduct() (сейчас молча возвращает
      // false) — он перезаписывает products.info_weight / info_weight_type_id
      // из base_unit_type_id, то есть откатывает смену единицы измерения у
      // товаров, заведённых вручную, и проставляет price_per_small_unit.
      // Легаси оставляем байт-в-байт; дубли строк цены — отдельная задача форков.
      $attributes = [
        'type_id' => Type::PRICE_DEFAULT,
        'value' => round($price, 2),
        'step' => $existingStep,
      ];

      if (app(NetworkCatalogMode::class)->isEnabled()) {
        $attributes['shop_id'] = $shopId;
      }

      $product->prices()->create($attributes);
    });
  }

  private function updateOrCreateProduct(array $args)
  {
    // Поля режима остатков обрабатываются отдельным блоком (валидация +
    // нормализация + аудит в applyStockMode). Исключаем их из общего
    // create/update, чтобы избежать рассинхрона с effective-полями и
    // записать аудит только при реальной смене режима.
    unset($args['stock_mode'], $args['fixed_stock_quantity'], $args['fixed_stock_unit_type_id']);

    // Лесенка «цены от количества» (MID-308) живёт в таблице price_tiers и
    // пишется отдельным шагом в __invoke, в атрибуты товара не попадает.
    unset($args['price_tiers']);

    // Пустая строка не должна попадать в БД: колонка участвует в
    // products_articul_shop_unique, и несколько товаров с articul=''
    // в одном магазине столкнутся на уникальном индексе (в отличие от NULL).
    if (array_key_exists('articul', $args) && $args['articul'] === '') {
      $args['articul'] = null;
    }

    // Динамические чекбоксы: неизвестные поля отбрасываются, значения
    // приводятся к bool; при отсутствии ключа значение в БД не меняется.
    $args = \App\Services\CustomCheckboxService::sanitizeArgs('Product', $args);

    $args['shop_id'] = \Auth::user()->isGlobalScope()
      ? $args['shop_id']
      : \Auth::user()->shops()->first()->shop_id;

    if (isset($args['id'])) {
      $product = Product::find($args['id']);

      // info_weight_type_id — единица ПОРЦИИ, её ведёт пересчёт фасовки
      // (CategoryQuantumService). Форма у товара с базовой единицей (1С или
      // зафиксированной у ручного) шлёт сюда базовую единицу из readonly-поля
      // «Ед. измерения»: без пересчёта по цене (сетевой каталог) порция
      // становилась «300 кг». Ручной товар без базы меняет единицу как раньше.
      if ($product?->base_unit_type_id !== null) {
        unset($args['info_weight_type_id']);
      }

      $product->update($args);
    } else {
      $product = Product::create($args);
    }

    // Сырая единица ручного (не-1С) товара фиксируется один раз из выбранной
    // менеджером ед. измерения. Квант перетирает info_weight_type_id, но не
    // base_unit_type_id — иначе при снятии кванта товар «забывает» свою единицу.
    if (empty($product->base_unit_type_id) && !empty($args['info_weight_type_id'])) {
      Product::withoutEvents(function () use ($product, $args) {
        $product->forceFill(['base_unit_type_id' => (int) $args['info_weight_type_id']])->save();
      });
    }

    return $product;
  }

  private function updateCookTime(array $args, Product $product): void
  {
    if (!isset($args['cook_start_time'], $args['cook_end_time'])) {
      $product->cookTime()->delete();
      return;
    }

    $cook_time = $product->cookTime()->first() ?? $product->cookTime()->make();

    $cook_time->start_time = $args['cook_start_time'];
    $cook_time->end_time = $args['cook_end_time'];

    $cook_time->save();
  }

  private function updateSupplements(array $args, Product $product): void
  {
    if (!isset($args['supplements'])) {
      $product->supplements()->delete();
      return;
    }

    $supplements_id = [];

    foreach ($args['supplements'] as $supplement_data) {
      $supplement_values_id = [];

      if (isset($supplement_data['id'])) {
        $supplement = $product->supplements()->find($supplement_data['id']);
        $supplement->update($supplement_data);
      } else {
        $supplement = $product->supplements()->create($supplement_data);
      }

      if (!isset($supplement_data['values'])) {
        $supplement->delete();
        continue;
      }

      foreach ($supplement_data['values'] as $value) {
        if (isset($value['id'])) {
          $supplement_value = $supplement->values()->find($value['id']);
          $supplement_value->update($value);
        } else {
          $supplement_value = $supplement->values()->create($value);
        }
        $supplement_values_id[] = $supplement_value->id;
      }
      $supplement->values()->whereNotIn('id', $supplement_values_id)->delete();

      $supplements_id[] = $supplement->id;
    }

    $product->supplements()->whereNotIn('id', $supplements_id)->delete();
  }

  /**
   * @param  list<int>  $categoryIds
   */
  private function syncCategories(\App\Models\Product $product, array $categoryIds): void
  {
    $unique = array_values(array_unique($categoryIds));

    // Валидируем только НОВО-добавляемые категории. Ранее привязанные к товару
    // нелистовые категории (узел получил дочерние уже после привязки) — допускаем,
    // иначе редактирование таких товаров блокируется полностью (даже правка остатков/
    // названия): форма всегда присылает текущие category_ids. UI (CategoryTreeSelect
    // leaf-only) не даёт выбрать новую нелистовую категорию — проверка ниже это
    // защита на уровне API.
    $currentIds = $product->categories()->pluck('categories.id')->all();
    $newlyAdded = array_values(array_diff($unique, $currentIds));

    if ($newlyAdded !== []) {
      $nonLeafTitles = \App\Models\Category::query()
        ->whereIn('id', $newlyAdded)
        ->whereHas('childCategories')
        ->pluck('title')
        ->all();

      if ($nonLeafTitles !== []) {
        $titles = implode('», «', $nonLeafTitles);
        $message = count($nonLeafTitles) === 1
          ? "Категория «{$titles}» содержит вложенные категории. Выберите конечную категорию (без подкатегорий)."
          : "Категории «{$titles}» содержат вложенные категории. Выберите конечные категории (без подкатегорий).";

        throw \Illuminate\Validation\ValidationException::withMessages([
          'category_ids' => [$message],
        ]);
      }
    }

    $payload = [];
    foreach ($unique as $i => $id) {
      $payload[$id] = ['position' => $i];
    }

    $product->categories()->sync($payload);
  }
}
