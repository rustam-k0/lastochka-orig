<?php

namespace App\Models;

use App\Services\ModuleConfigService;
use App\Support\Barcode;
use App\Support\NetworkCatalogMode;
use App\Support\ProductCode;
use App\Support\WeightedBarcode;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
  use  ModelTools, HasImage,SoftDeletes;


  /** Режимы effective_stock_mode, при которых остаток товара не блокирует курьерку. */
  public const STOCK_IGNORING_MODES = ['ignore', 'fixed'];

  /** Единицы info_weight_type_id весового/объёмного товара ({@see isWeightBased}). */
  public const WEIGHT_BASED_INFO_WEIGHT_TYPES = [
    Type::INFO_WEIGHT_GRAM,
    Type::INFO_WEIGHT_KILOGRAM,
    Type::INFO_WEIGHT_LITER,
    Type::INFO_WEIGHT_M_LITER,
  ];

  /**
   * Режим, при котором остаток НЕ блокирует сборку/выдачу: 'ignore' или 'fixed'.
   *
   * Колонка products.effective_stock_mode материализуется admin_back'ом в общей БД
   * (LASTOCHKI-JAMI-11207). И ignore, и fixed для курьерки означают одно: не
   * блокировать сборку, не списывать остаток, не показывать лимит — дневным лимитом
   * режима fixed рулит витрина, курьерке достаточно «не учитывать остаток».
   */
  public function effectiveStockIgnored(): bool
  {
    return in_array($this->effective_stock_mode, self::STOCK_IGNORING_MODES, true);
  }

  /**
   * Весовой/объёмный товар — собранное количество задаётся фактическим весом
   * (actual_weight), а цена считается по кванту getStepGrams().
   *
   * Штучный (INFO_WEIGHT_PIECE) и незаполненный (NULL, товары 1С без единицы)
   * весом не подтверждаются: для них getStepGrams() возвращает 1, и расчёт
   * «цена × вес / шаг» превращал цену за штуку в цену за грамм
   * (MID-246: кофе 1900 ₽ × 200 гр = 380 000 ₽).
   */
  public function isWeightBased(): bool
  {
    return in_array((int) $this->info_weight_type_id, self::WEIGHT_BASED_INFO_WEIGHT_TYPES, true);
  }

  public function categories(): BelongsToMany
  {
    return $this->belongsToMany(Category::class, 'category_product');
  }

  public function remain(): ?HasOne
  {
    return $this->hasOne(Remain::class);
  }

  /**
   * Поиск по отсканированному штрихкоду (сборщик: product_search,
   * product_global_search, product_search_by_shop).
   *
   * Точное совпадение с сохранённым products.barcode приоритетнее разбора
   * «весового» штрихкода: 1С генерирует для собственной номенклатуры
   * (выпечка/супы «Ласточка», торты, игрушки) СТАТИЧНЫЕ внутренние EAN-13
   * с префиксом 20–29 (напр. 2100100058357 = префикс 21 + сквозной счётчик),
   * и цифры после префикса у них НЕ код товара. Поэтому:
   *  1) штрихкод сохранён у какого-то товара → ищем по точному совпадению
   *     (плюс легаси-совпадение по первым 7 цифрам);
   *  2) иначе, если это весовой штрихкод 20–29 (весы: префикс + 5 цифр PLU
   *     + вес) → ищем по products.plu, а затем по products.code (пока 1С
   *     кладёт PLU весовых в «Код»), толерантно к ведущим нулям («00296»
   *     матчит «296»). Если код есть у весового товара, штучные с тем же
   *     кодом не находятся: короткие коды 1С у весовых (PLU) и у собственного
   *     производства «Ласточка» пересекаются (код 23: «Огурцы Осетия» и «Люля
   *     Куриные На Мангале»), а этикетку с весом печатают только на весовой
   *     товар. Весовой без остатка → «не найден», а не чужой штучный;
   *  3) иначе — обычный поиск по штрихкоду.
   *
   * Совпадение по штрихкоду толерантно к ведущим нулям в обе стороны
   * ({@see Barcode::storedCandidates}): 1С отдаёт EAN-8/UPC-A дополненными
   * нулями до 13 («0000080768258»), а сканер возвращает «80768258».
   */
  public function scopeScannedBarcode($query, string $barcode)
  {
    $codeCandidates = WeightedBarcode::codeCandidates($barcode);

    if ($codeCandidates && ! self::hasStoredBarcode($barcode)) {
      $query->whereScaleCode($codeCandidates);

      // Штучные с тем же кодом отсекаем, только если код есть у весового товара
      // (в любом наличии): иначе весовой без остатка уступал бы чужой штучный.
      $hasWeightBased = static::query()
        ->whereScaleCode($codeCandidates)
        ->whereIn('info_weight_type_id', self::WEIGHT_BASED_INFO_WEIGHT_TYPES)
        ->exists();

      if ($hasWeightBased) {
        $query->whereIn($query->getModel()->qualifyColumn('info_weight_type_id'), self::WEIGHT_BASED_INFO_WEIGHT_TYPES);
      }

      // Весы печатают PLU, поэтому совпадение по plu приоритетнее чужого кода.
      $plu = ProductCode::normalizedColumnSql($query->getModel()->qualifyColumn('plu'));
      $placeholders = implode(', ', array_fill(0, count($codeCandidates), '?'));

      return $query->orderByRaw("CASE WHEN $plu IN ($placeholders) THEN 0 ELSE 1 END", $codeCandidates);
    }

    return $query->whereStoredBarcode($barcode);
  }

  /**
   * Совпадение с кодом из весового штрихкода: по plu или по code.
   *
   * Код из штрихкода — всегда слитные цифры, а в products.code 1С кладёт
   * разряды через неразрывный пробел («172 484»): сравниваем нормализованно.
   *
   * @param string[] $codeCandidates {@see WeightedBarcode::codeCandidates}
   */
  public function scopeWhereScaleCode($query, array $codeCandidates)
  {
    return $query->where(function ($q) use ($codeCandidates) {
      $q->whereNormalizedCode('plu', $codeCandidates)
        ->orWhere(fn ($inner) => $inner->whereNormalizedCode('code', $codeCandidates));
    });
  }

  /**
   * Совпадение кода/артикула без учёта разделителей разрядов от 1С: и колонка,
   * и введённое значение приводятся к слитным цифрам ({@see ProductCode}).
   *
   * Сборщик вводит цифры так, как они напечатаны на ценнике («172 484» или
   * «172484»), — оба варианта обязаны находить один и тот же товар.
   *
   * @param string|string[] $values
   */
  public function scopeWhereNormalizedCode($query, string $column, $values)
  {
    $normalized = array_values(array_filter(array_unique(array_map(
      static fn ($value) => ProductCode::normalize((string) $value),
      (array) $values
    )), static fn ($value) => $value !== null && $value !== ''));

    if (! $normalized) {
      return $query->whereRaw('1 = 0');
    }

    $qualified = $query->getModel()->qualifyColumn($column);
    $placeholders = implode(', ', array_fill(0, count($normalized), '?'));

    return $query->whereRaw(
      ProductCode::normalizedColumnSql($qualified) . " IN ($placeholders)",
      $normalized
    );
  }

  /**
   * Поиск товара по цифрам с ценника (поле «Артикул» в мобилке сборщика).
   *
   * На ценнике 1С печатает «Код» и «PLU», артикула там нет, поэтому ищем по
   * code, plu и articul. Сравнение нормализованное — 1С форматирует числовые
   * коды неразрывным пробелом между разрядами («172 484»), сборщик вводит те же
   * цифры слитно. У code и plu дополнительно не важны ведущие нули: PLU на
   * ценнике дополнен до 5 знаков («00030»), а в базе лежит «30»; у артикула
   * нули значимы («000000001»).
   *
   * Приоритет результата детерминирован, без него ->first() отдавал бы
   * произвольный товар (на проде у 536 товаров артикул равен коду другого, PLU
   * весовых совпадают с короткими кодами штучных):
   *  0) code — то, что напечатано в поле «Код»;
   *  1) plu;
   *  2) articul как введён, 3) articul нормализованный — цифры из карточки
   *     товара в приложении;
   *  4) plu без учёта ведущих нулей — ввод в формате ценника «00030»;
   *  5) code без учёта ведущих нулей — пока 1С кладёт PLU весовых в «Код».
   */
  public function scopeMatchingCode($query, string $value)
  {
    $normalized = (string) ProductCode::normalize($value);
    $zeroPadded = ProductCode::zeroPaddedCandidates($value);

    $query->where(function ($q) use ($value, $zeroPadded) {
      $q->whereNormalizedCode('code', $value)
        ->orWhere(fn ($inner) => $inner->whereNormalizedCode('plu', $value))
        ->orWhere(fn ($inner) => $inner->whereNormalizedCode('articul', $value));

      if ($zeroPadded) {
        $q->orWhere(fn ($inner) => $inner->whereNormalizedCode('plu', $zeroPadded))
          ->orWhere(fn ($inner) => $inner->whereNormalizedCode('code', $zeroPadded));
      }
    });

    $model = $query->getModel();
    $code = ProductCode::normalizedColumnSql($model->qualifyColumn('code'));
    $plu = ProductCode::normalizedColumnSql($model->qualifyColumn('plu'));
    $articul = $model->qualifyColumn('articul');

    $sql = "CASE WHEN $code = ? THEN 0"
      . " WHEN $plu = ? THEN 1"
      . " WHEN $articul = ? THEN 2"
      . ' WHEN ' . ProductCode::normalizedColumnSql($articul) . ' = ? THEN 3';
    $bindings = [$normalized, $normalized, $value, $normalized];

    if ($zeroPadded) {
      $placeholders = implode(', ', array_fill(0, count($zeroPadded), '?'));
      $sql .= " WHEN $plu IN ($placeholders) THEN 4 WHEN $code IN ($placeholders) THEN 5";
      $bindings = array_merge($bindings, $zeroPadded, $zeroPadded);
    }

    return $query->orderByRaw($sql . ' ELSE 6 END', $bindings);
  }

  /**
   * Совпадение с любым сохранённым штрихкодом товара: и с основным
   * (products.barcode), и с остальными из JSON-списка products.barcodes.
   *
   * У одного товара штатно бывает несколько штрихкодов (разные партии,
   * поставщики, фасовки) — 1С присылает их списком, admin_back кладёт первый
   * в barcode, а весь список в barcodes. Сканировать нужно любой из них.
   *
   * Совпадение толерантно к ведущим нулям в обе стороны
   * ({@see Barcode::storedCandidates}); каждый кандидат в JSON-списке ищем
   * и строкой, и числом — штрихкод мог попасть в массив в любом из этих видов.
   */
  public function scopeWhereBarcodeCandidates($query, string $barcode)
  {
    $table = $this->getTable();
    $candidates = Barcode::storedCandidates($barcode);

    return $query->where(function ($q) use ($table, $candidates) {
      $q->whereIn("{$table}.barcode", $candidates);

      foreach ($candidates as $candidate) {
        $q->orWhereJsonContains("{$table}.barcodes", $candidate);

        if (ctype_digit($candidate)) {
          $q->orWhereJsonContains("{$table}.barcodes", (int) $candidate);
        }
      }
    });
  }

  /**
   * Товар с сохранённым штрихкодом, равным отсканированному (с точностью до
   * ведущих нулей), либо — легаси — его первым 7 цифрам.
   */
  public function scopeWhereStoredBarcode($query, string $barcode)
  {
    $table = $this->getTable();

    return $query->where(function ($q) use ($table, $barcode) {
      $q->whereBarcodeCandidates($barcode)
        ->orWhere("{$table}.barcode", mb_substr($barcode, 0, 7));
    });
  }

  /**
   * Есть ли товар с таким сохранённым штрихкодом (в любом магазине, без учёта
   * остатка) — признак статичного штрихкода, а не динамического весового.
   *
   * Легаси-совпадение по первым 7 цифрам здесь НЕ учитывается: оно слишком
   * широкое, чтобы признавать штрихкод статичным и отключать весовой разбор.
   */
  public static function hasStoredBarcode(string $barcode): bool
  {
    return static::query()->whereBarcodeCandidates($barcode)->exists();
  }

  /**
   * Наличие товара. Легаси: остаток магазина-владельца карточки
   * (remains.shop_id = products.shop_id). Сетевой каталог: остаток
   * конкретного магазина ($shopId), без привязки к products.shop_id;
   * без $shopId — наличие хоть в одном магазине.
   *
   * ВНИМАНИЕ: скоуп вызывается ещё и Lighthouse (@first(scopes: ["inStock"])
   * в product_global_search) — директива передаёт вторым аргументом МАССИВ
   * аргументов запроса, а не id магазина. Поэтому всё, что не целое число,
   * трактуем как «магазин не указан».
   *
   * Товары в режиме ignore/fixed (effective_stock_mode) остаток не блокирует:
   * это собственное производство (хлеб, выпечка, супы), которое стоит на полке,
   * но приходит из 1С с нулевым остатком. Вся цепочка сборки их пропускает
   * (ProductAddToOrder, ProductAddToOrderValidator, ProductHasStockRule,
   * AssemblyFinish) — поиск обязан вести себя так же, иначе сборщик не может
   * найти товар, который система разрешает добавить в заказ.
   */
  public function scopeInStock($query, $shopId = null)
  {
    $shopId = is_numeric($shopId) ? (int) $shopId : null;

    if (NetworkCatalogMode::isEnabled()) {
      return $query->where(function ($outer) use ($shopId) {
        $outer->whereHas('remain', function ($q) use ($shopId) {
          $q->withoutGlobalScopes()->where('quantity', '>', 0);
          if ($shopId !== null) {
            $q->where('remains.shop_id', $shopId);
          }
        })
          // Карточка в сетевом режиме глобальная, поэтому одного режима ignore/fixed мало:
          // принадлежность товара магазину заказа определяет цена (как в
          // ProductWithPriceExistsRule), иначе товар «протекал» бы из чужих магазинов.
          ->orWhere(function ($q) use ($shopId) {
            $q->whereIn('products.effective_stock_mode', self::STOCK_IGNORING_MODES)
              ->whereHas('price', function ($p) use ($shopId) {
                if ($shopId !== null) {
                  $p->where('prices.shop_id', $shopId);
                }
              });
          });
      });
    }

    return $query->where(function ($outer) {
      $outer->whereHas('remain', function ($q) {
        $q->withoutGlobalScopes()
          ->whereColumn('remains.shop_id', 'products.shop_id')
          ->where('quantity', '>', 0);
      })
        // Легаси: принадлежность магазину задаёт сама колонка products.shop_id,
        // которую вызывающий уже отфильтровал, — достаточно режима.
        ->orWhereIn('products.effective_stock_mode', self::STOCK_IGNORING_MODES);
    });
  }

  public function orderItem(): ?HasOne
  {
    return $this->hasOne(OrderItem::class,'product_id','id');
  }

  public function prices(): ?HasMany
  {
    return $this->hasMany(Price::class)->where('type_id',Type::PRICE_DEFAULT);
  }

  public function price(): ?HasOne
  {
    return $this->hasOne(Price::class)->where('type_id',Type::PRICE_DEFAULT);
  }

  /**
   * Базовая цена товара в конкретном магазине (сетевой каталог: цены пер-магазин).
   */
  public function priceForShop(int $shopId): ?Price
  {
    return $this->prices()->where('shop_id', $shopId)->first();
  }

  /**
   * Цена, по которой товар продаётся в рамках конкретного заказа.
   *
   * Сетевой каталог: строго цена магазина заказа (prices.shop_id) — связь price()
   * это hasOne без фильтра по магазину и при сетевых ценах вернула бы строку с
   * минимальным id, то есть цену произвольного (чужого) магазина.
   * Легаси: прежнее поведение — единственная цена карточки.
   *
   * null — цены для магазина нет; вызывающий обязан явно обработать этот случай,
   * молча считать 0 при замене товара нельзя (это деньги клиента).
   */
  public function effectivePriceForShop(?int $shopId): ?Price
  {
    if (NetworkCatalogMode::isEnabled() && $shopId !== null) {
      return $this->priceForShop($shopId);
    }

    return $this->price;
  }

  /**
   * Остаток товара в конкретном магазине. Глобальный скоуп onlyAuth снимаем
   * намеренно: спрашиваем про явно заданный магазин, а не про магазины юзера.
   */
  public function remainForShop(int $shopId): ?Remain
  {
    return Remain::withoutGlobalScopes()
      ->where('product_id', $this->id)
      ->where('shop_id', $shopId)
      ->first();
  }

  /**
   * Время готовности товара в формате HH:MM (модуль product_ready_time).
   *
   * Колонка products.ready_fixed_time заполняется в админке (admin_back) и лежит
   * в общей БД курьерки — как effective_stock_mode. Готовность ЕЖЕДНЕВНАЯ:
   * «готов начиная с 14:30» каждый день, поэтому в отличие от витрины
   * (availableFrom обнуляется после наступления времени) курьерка отдаёт время
   * весь день — для сборщика это постоянный признак товара, а не временный тег.
   *
   * null — время не задано или модуль выключен.
   */
  public function getReadyFixedTimeFormatted(): ?string
  {
    $readyAt = $this->readyAt();

    return $readyAt ? $readyAt->format('H:i') : null;
  }

  /**
   * Код товара из 1С слитными цифрами («119360») — для показа сборщику рядом с
   * артикулом. У части товаров в products.code ещё лежит значение с
   * неразрывным пробелом между разрядами («119 360»), форматирование под
   * ценник остаётся за приложением. null — кода нет.
   */
  public function getCodeNormalized(): ?string
  {
    $code = ProductCode::normalize($this->code);

    return $code === '' ? null : $code;
  }

  /**
   * Момент готовности товара на сегодня (Europe/Moscow).
   * null — время не задано или модуль выключен.
   */
  public function readyAt(): ?Carbon
  {
    if (!$this->ready_fixed_time) {
      return null;
    }

    if (!ModuleConfigService::isProductReadyTimeEnabled()) {
      return null;
    }

    return Carbon::createFromTimeString(
      (string) $this->ready_fixed_time,
      config('app.timezone')
    );
  }

  /**
   * Готов ли товар прямо сейчас.
   *
   * Считается по серверному времени намеренно: часы на устройстве сборщика могут
   * врать, а решение показывать предупреждение о ранней сборке от них зависеть
   * не должно. true — время готовности не задано, модуль выключен или уже наступило.
   */
  public function isReadyNow(): bool
  {
    $readyAt = $this->readyAt();

    return $readyAt === null || $readyAt->isPast();
  }

}
