<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeasurementUnit;
use App\Enums\UnitType;
use App\Services\Settings\ModuleConfigService;
use App\Support\Barcode;
use App\Support\WeightedBarcode;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Единицы весового/объёмного товара ({@see isMeasuredByWeight}). */
    public const WEIGHT_MEASUREMENT_UNITS = [
        MeasurementUnit::Gram,
        MeasurementUnit::Kilogram,
        MeasurementUnit::Liter,
        MeasurementUnit::Milliliter,
    ];

    /**
     * Автоматически подгружаем активные акции для расчёта цен.
     *
     * @var array<string>
     */
    protected $with = ['activePromotions', 'images'];

    protected $fillable = [
        'store_id',
        'source',
        'brand_id',
        'video_path',
        'video_source_url',
        'sku',
        'code',
        'plu',
        'barcodes',
        'title',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'price',
        'price_old',
        'currency',
        'quantity',
        'unit_type',
        'quantity_step',
        'measurement_unit',
        'composition',
        'additional_info',
        'proteins',
        'fats',
        'carbohydrates',
        'calories',
        'weight',
        'vat_rate',
        'parent_id',
        'is_configurable',
        'is_default_variant',
        'is_active',
        'is_culinary',
        'sugar_free',
        'on_search_page',
        'served_warm',
        'pickup_only',
        'adult_only',
        'is_marked',
        'is_new',
        'is_hurry_up',
        'rating',
        'reviews_count',
        'stock_mode',
        'fixed_stock',
        'ready_fixed_time',
        'custom_checkboxes',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'barcodes' => 'array',
            'price' => 'float',
            'price_old' => 'float',
            'quantity' => 'integer',
            'unit_type' => UnitType::class,
            'quantity_step' => 'integer',
            'measurement_unit' => MeasurementUnit::class,
            'proteins' => 'decimal:2',
            'fats' => 'decimal:2',
            'carbohydrates' => 'decimal:2',
            'calories' => 'decimal:2',
            'weight' => 'integer',
            'vat_rate' => 'integer',
            'is_configurable' => 'boolean',
            'is_default_variant' => 'boolean',
            'is_active' => 'boolean',
            'is_culinary' => 'boolean',
            'sugar_free' => 'boolean',
            'on_search_page' => 'boolean',
            'served_warm' => 'boolean',
            'pickup_only' => 'boolean',
            'adult_only' => 'boolean',
            'is_marked' => 'boolean',
            'is_new' => 'boolean',
            'is_hurry_up' => 'boolean',
            'rating' => 'decimal:1',
            'reviews_count' => 'integer',
            'fixed_stock' => 'integer',
            'custom_checkboxes' => 'array',
        ];
    }

    /**
     * Слаг генерируется при создании и больше не меняется (стабильность ссылок).
     * Товары из RabbitMQ создаются через withoutEvents() — для них слаг ставит
     * ProductEventTransformer::ensureSlug().
     */
    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (empty($product->slug)) {
                $product->slug = app(\App\Services\Product\ProductSlugGenerator::class)
                    ->generate($product->title);
            }
        });
    }

    // ==================== Relations ====================

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function configurableAttributes(): HasMany
    {
        return $this->hasMany(ConfigurableAttribute::class)->orderBy('position');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function storeProducts(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    /**
     * Строка store_products магазина, загруженная constrained-eager-load'ом:
     * ->with(['storeProducts' => fn ($q) => $q->where('store_id', $storeId)]).
     * Ресурсы читают цену/остаток контекста магазина без знания о флаге:
     * в легаси-режиме связь не загружается и ресурс падает обратно на
     * колонки products.
     */
    public function storeContext(): ?StoreProduct
    {
        return $this->relationLoaded('storeProducts') ? $this->storeProducts->first() : null;
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot('position')
            ->orderBy('category_product.position');
    }

    public function getCategoryAttribute(): ?Category
    {
        return $this->relationLoaded('categories')
            ? $this->categories->first()
            : $this->categories()->first();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(Supplement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class)->orderBy('position');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'promotion_products')
            ->withTimestamps();
    }

    /**
     * Активные акции для eager loading.
     * Отсортированы по убыванию скидки для выбора лучшей.
     */
    public function activePromotions(): BelongsToMany
    {
        return $this->promotions()
            ->where('promotions.is_active', true)
            ->where('promotions.starts_at', '<=', now())
            ->where('promotions.ends_at', '>=', now())
            ->orderByDesc('promotions.discount_percent');
    }

    // ==================== Scopes ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Поиск по отсканированному штрихкоду (REST /products/search/barcode/{barcode}).
     *
     * Точное совпадение с сохранённым products.barcodes приоритетнее разбора
     * «весового» штрихкода: 1С генерирует для собственной номенклатуры
     * (выпечка/супы «Ласточка», торты, игрушки) СТАТИЧНЫЕ внутренние EAN-13
     * с префиксом 20–29 (напр. 2100100058357 = префикс 21 + сквозной счётчик),
     * и цифры после префикса у них НЕ код товара. Поэтому:
     *  1) штрихкод сохранён у какого-то товара → ищем по точному совпадению;
     *  2) иначе, если это весовой штрихкод 20–29 (весы: префикс + 5 цифр PLU
     *     + вес) → ищем по products.plu, а затем по products.code (пока 1С
     *     кладёт PLU весовых в «Код»), толерантно к ведущим нулям («00296»
     *     матчит «296»). Если код есть у весового товара, штучные с тем же
     *     кодом не находятся: короткие коды 1С у весовых (PLU) и у собственного
     *     производства «Ласточка» пересекаются (код 23: «Огурцы Осетия» и «Люля
     *     Куриные На Мангале»), а этикетку с весом печатают только на весовой
     *     товар. Весовой недоступен → 404, а не чужой штучный;
     *  3) иначе — обычный поиск по штрихкодам.
     *
     * Совпадение по штрихкоду толерантно к ведущим нулям в обе стороны
     * ({@see Barcode::storedCandidates}): 1С отдаёт EAN-8/UPC-A дополненными
     * нулями до 13 («0000080768258»), а сканер возвращает «80768258».
     */
    public function scopeScannedBarcode(Builder $query, string $barcode): Builder
    {
        $codeCandidates = WeightedBarcode::codeCandidates($barcode);

        if (! empty($codeCandidates) && ! self::hasStoredBarcode($barcode)) {
            $table = $this->getTable();
            $placeholders = implode(', ', array_fill(0, count($codeCandidates), '?'));
            $weightUnits = array_map(
                static fn (MeasurementUnit $unit): string => $unit->value,
                self::WEIGHT_MEASUREMENT_UNITS,
            );

            $query->whereScaleCode($codeCandidates);

            // Штучные с тем же кодом отсекаем, только если код есть у весового товара
            // (в любом наличии): иначе весовой без остатка уступал бы чужой штучный.
            $hasWeighted = self::query()
                ->whereScaleCode($codeCandidates)
                ->whereIn('measurement_unit', $weightUnits)
                ->exists();

            if ($hasWeighted) {
                $query->whereIn("{$table}.measurement_unit", $weightUnits);
            }

            // Весы печатают PLU, поэтому совпадение по plu приоритетнее чужого кода.
            return $query->orderByRaw("CASE WHEN {$table}.plu IN ({$placeholders}) THEN 0 ELSE 1 END", $codeCandidates);
        }

        return $query->whereStoredBarcode($barcode);
    }

    /**
     * Совпадение с кодом из весового штрихкода: по plu или по code.
     *
     * @param  string[]  $codeCandidates  {@see WeightedBarcode::codeCandidates}
     */
    public function scopeWhereScaleCode(Builder $query, array $codeCandidates): Builder
    {
        $table = $this->getTable();

        return $query->where(fn (Builder $q) => $q
            ->whereIn("{$table}.plu", $codeCandidates)
            ->orWhereIn("{$table}.code", $codeCandidates));
    }

    /**
     * Товар, у которого штрихкод сохранён в products.barcodes (JSON-массив),
     * с точностью до ведущих нулей ({@see Barcode::storedCandidates}).
     * Каждый кандидат ищем и строкой, и числом — штрихкод мог попасть
     * в массив в любом из этих видов.
     */
    public function scopeWhereStoredBarcode(Builder $query, string $barcode): Builder
    {
        $table = $this->getTable();
        $candidates = Barcode::storedCandidates($barcode);

        return $query->where(function (Builder $q) use ($table, $candidates): void {
            foreach ($candidates as $candidate) {
                $q->orWhereJsonContains("{$table}.barcodes", $candidate);
                if (ctype_digit($candidate)) {
                    $q->orWhereJsonContains("{$table}.barcodes", (int) $candidate);
                }
            }
        });
    }

    /**
     * Есть ли товар с таким сохранённым штрихкодом (без учёта активности,
     * остатка и магазина) — признак статичного штрихкода, а не динамического весового.
     */
    public static function hasStoredBarcode(string $barcode): bool
    {
        return self::query()->whereStoredBarcode($barcode)->exists();
    }

    /**
     * Фильтрация 18+ товаров: без подтверждения совершеннолетия adult_only
     * товары скрываются из выдачи. Решение «показывать или нет» принимает
     * {@see \App\Services\Catalog\AdultContentPolicy}.
     */
    public function scopeAdultVisible($query, bool $showAdult)
    {
        return $showAdult ? $query : $query->where('adult_only', false);
    }

    /**
     * Товар «в наличии»: остаток покрывает шаг покупки ЛИБО магазин работает
     * в режиме «не учитывать остатки» ({@see Store::$ignore_stock}) — тогда
     * товар доступен независимо от quantity.
     *
     * Сетевой каталог (network_catalog): остаток карточки всегда 0 — он живёт
     * в store_products, поэтому проверяем «есть остаток хоть в одном магазине».
     * Для выдачи конкретного магазина нужен {@see self::scopePurchasableInStore()}.
     */
    public function scopeInStock($query)
    {
        if (app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return $query->where(function ($q) {
                $q->whereHas('storeProducts', function ($sq) {
                    $sq->where('store_products.is_active', true)
                        ->whereRaw(self::minSaleStockSql('store_products.quantity'));
                })
                    ->orWhere('stock_mode', 'ignore')
                    ->orWhereHas('storeProducts.store', fn ($s) => $s->where('ignore_stock', true));
            });
        }

        return $query->where(function ($q) {
            $q->whereRaw(self::minSaleStockSql('quantity', null))
                ->orWhere('stock_mode', 'ignore')
                ->orWhereHas('store', fn ($s) => $s->where('ignore_stock', true));
        });
    }

    /**
     * Товары магазина. В сетевом каталоге карточка глобальна (принадлежит
     * якорному магазину), а «товар есть в магазине» = есть активная строка
     * store_products с ценой. Остаток проверяют inStock()/purchasableInStore().
     */
    public function scopeForStore($query, int $storeId)
    {
        if (app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return $query->whereHas('storeProducts', function ($q) use ($storeId) {
                $q->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true)
                    ->where('store_products.price', '>', 0);
            });
        }

        return $query->where('store_id', $storeId);
    }

    /**
     * Присоединить оффер магазина (store_products) к запросу листинга.
     *
     * UNIQUE(store_id, product_id) делает join строго 1:1 — выдача не
     * дублируется, distinct не нужен, счётчик пагинации остаётся верным.
     * После join'а цена/старая цена/остаток магазина доступны как обычные
     * колонки `store_products.*` — фильтры и сортировки считаются по ним,
     * без коррелированного подзапроса на каждую строку.
     *
     * Сам факт наличия строки (активной, с ценой) и есть видимость товара
     * в магазине, поэтому INNER JOIN заменяет и forStore().
     */
    public function scopeJoinStoreOffer(Builder $query, int $storeId): Builder
    {
        $table = $this->getTable();

        return $query
            ->join('store_products', function ($join) use ($storeId, $table) {
                $join->on('store_products.product_id', '=', "{$table}.id")
                    ->where('store_products.store_id', '=', $storeId)
                    ->where('store_products.is_active', '=', true)
                    ->where('store_products.price', '>', 0);
            })
            ->select("{$table}.*");
    }

    /**
     * Товар покупабелен В МАГАЗИНЕ: активная строка store_products с ценой
     * и остатком (либо stock_mode=ignore / stores.ignore_stock).
     *
     * Легаси-режим — ровно прежняя цепочка вызывающих листингов
     * (forStore + purchasable), чтобы поведение при выключенном модуле
     * осталось байт-в-байт прежним.
     */
    public function scopePurchasableInStore(Builder $query, int $storeId): Builder
    {
        $config = app(ModuleConfigService::class);

        if (! $config->isNetworkCatalogEnabled()) {
            return $query->forStore($storeId)->purchasable();
        }

        $table = $this->getTable();

        // Базовые условия — общие с purchasable(); проверки цены и остатка
        // здесь делает сам оффер, поэтому purchasable() целиком не зовём
        // (иначе к join'у добавились бы дублирующие EXISTS по store_products).
        $this->applyPurchasableBase($query, true);

        $query->joinStoreOffer($storeId);

        // Предзаказы включены — out-of-stock товары остаются в выдаче
        // (симметрично легаси-ветке purchasable()).
        if (! $config->isPreordersEnabled()) {
            $query->where(function (Builder $q) use ($table, $storeId) {
                $q->whereRaw(self::minSaleStockSql('store_products.quantity', $table))
                    ->orWhere("{$table}.is_configurable", true)
                    ->orWhere("{$table}.stock_mode", 'ignore')
                    // Подзапрос по константному store_id — некоррелированный,
                    // вычисляется один раз на запрос.
                    ->orWhereExists(function ($e) use ($storeId) {
                        $e->selectRaw('1')->from('stores')
                            ->where('stores.id', $storeId)
                            ->where('stores.ignore_stock', true);
                    });
            });
        }

        return $query;
    }

    /**
     * EXISTS-версия {@see self::scopePurchasableInStore()}: та же видимость
     * товара в магазине, но без join'а и без `select('products.*')`.
     *
     * Нужна там, где join сломал бы запрос или его результат: подзапросы
     * withCount (счётчики категорий и товарных групп), constrained eager load
     * связей, выборки со своим списком колонок.
     *
     * Легаси-режим — ровно прежняя цепочка вызывающих (forStore + purchasable).
     */
    public function scopePurchasableInStoreExists(Builder $query, int $storeId): Builder
    {
        if (! app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return $query->forStore($storeId)->purchasable();
        }

        return $query->purchasableInNetworkStore($storeId);
    }

    /**
     * Сетевая ветка «покупаемости в магазине» целиком на подзапросах.
     *
     * $store — id магазина либо SQL-выражение, вычисляющее его из уже
     * присоединённой таблицы. Выражение нужно связям, где магазин берётся
     * у родительской сущности: Laravel строит связь на ПУСТОЙ модели
     * (eager load, withCount), поэтому `$this->store_id` там недоступен,
     * а pivot в запросе есть всегда.
     *
     * Вызывать только при включённом network_catalog: легаси-ветку делает
     * {@see self::scopePurchasableInStoreExists()}.
     */
    public function scopePurchasableInNetworkStore(Builder $query, int|Expression $store): Builder
    {
        $table = $this->getTable();

        $this->applyPurchasableBase($query, true);

        $query->whereExists(fn ($q) => $this->storeOfferSubquery($q, $store));

        // Предзаказы включены — out-of-stock товары остаются в выдаче
        // (симметрично scopePurchasable()/scopePurchasableInStore()).
        if (! app(ModuleConfigService::class)->isPreordersEnabled()) {
            $query->where(function (Builder $q) use ($table, $store): void {
                $q->whereExists(fn ($e) => $this->storeOfferSubquery($e, $store)
                    ->whereRaw(self::minSaleStockSql('store_products.quantity', $table)))
                    ->orWhere("{$table}.is_configurable", true)
                    ->orWhere("{$table}.stock_mode", 'ignore')
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')
                        ->from('stores')
                        ->where('stores.id', $store)
                        ->where('stores.ignore_stock', true));
            });
        }

        return $query;
    }

    /**
     * Товар предлагается в магазине: активный оффер с ценой и остатком
     * (либо stock_mode=ignore / stores.ignore_stock у магазина оффера).
     *
     * Store-scoped аналог связки {@see self::scopeHasPrice()} +
     * {@see self::scopeInStock()} — без проверок самой карточки (активность,
     * вариации, модули), их вызывающий делает отдельно. Вызывать только при
     * включённом network_catalog.
     */
    public function scopeOfferedInStore(Builder $query, int $storeId): Builder
    {
        $table = $this->getTable();

        $query->whereExists(fn ($q) => $this->storeOfferSubquery($q, $storeId));

        return $query->where(function (Builder $q) use ($table, $storeId): void {
            $q->whereExists(fn ($e) => $this->storeOfferSubquery($e, $storeId)
                ->whereRaw(self::minSaleStockSql('store_products.quantity', $table)))
                ->orWhere("{$table}.stock_mode", 'ignore')
                ->orWhereExists(fn ($e) => $e->selectRaw('1')
                    ->from('stores')
                    ->where('stores.id', $storeId)
                    ->where('stores.ignore_stock', true));
        });
    }

    /**
     * Подзапрос «у товара есть валидный оффер магазина»: строка store_products
     * активна и с ценой (price=0 оффером не считается — такую строку создаёт
     * stock-событие, пришедшее раньше цены).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function storeOfferSubquery($query, int|Expression $store)
    {
        return $query->selectRaw('1')
            ->from('store_products')
            ->whereColumn('store_products.product_id', $this->getTable().'.id')
            ->where('store_products.store_id', $store)
            ->where('store_products.is_active', true)
            ->where('store_products.price', '>', 0);
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->whereHas('categories', fn ($q) => $q->where('categories.id', $categoryId));
    }

    /**
     * Товары всего поддерева категории: сама категория + вложенные
     * подкатегории на любую глубину. Через EXISTS — товар, привязанный
     * к нескольким категориям поддерева, не дублируется.
     */
    public function scopeForCategorySubtree($query, Category $category)
    {
        return $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $category->subtreeIds()));
    }

    /**
     * Порядок «по названию» с обязательным tie-break по id: сортировка по
     * одному неуникальному ключу + LIMIT/OFFSET в MySQL отдаёт разные окна
     * на соседних страницах (товар дважды, соседний — ни разу). Колонки
     * квалифицируем: в сетевом режиме к запросу присоединён store_products
     * со своим id.
     */
    public function scopeOrdered($query)
    {
        $table = $query->getModel()->getTable();

        return $query->orderBy("{$table}.title")->orderBy("{$table}.id");
    }

    public function scopeConfigurable($query)
    {
        return $query->where('is_configurable', true);
    }

    public function scopeExcludeChildren($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * У товара есть цена. В сетевом каталоге цена карточки всегда 0 — она
     * живёт в store_products, поэтому проверяем «есть цена хоть в одном
     * магазине».
     */
    public function scopeHasPrice($query)
    {
        if (app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return $query->whereHas('storeProducts', function ($q) {
                $q->where('store_products.is_active', true)
                    ->where('store_products.price', '>', 0);
            });
        }

        return $query->where('price', '>', 0);
    }

    public function scopeSugarFree($query)
    {
        return $query->where('sugar_free', true);
    }

    /**
     * Товар, доступный к покупке в листингах: активен, имеет цену, не вариация
     * (parent_id IS NULL), и проходит проверку модулей.
     *
     * Логика синхронна с {@see \App\Http\Controllers\Api\V1\ProductController::show()}
     * — то, что выводится в листингах, должно открываться без 404.
     *
     * Quirk: квалифицируем колонки таблицей, чтобы scope корректно работал
     * внутри `BelongsToMany` (pivot product_group_items, category_product) —
     * там без префикса колонка может быть неоднозначной.
     */
    public function scopePurchasable(Builder $query): Builder
    {
        $config = app(ModuleConfigService::class);
        $table = $this->getTable();

        // Сетевой каталог: цена и остаток карточки всегда нулевые — они живут
        // в store_products. Поэтому проверки цены/остатка здесь берут данные
        // из офферов (без привязки к конкретному магазину), а точная выдача
        // магазина строится через purchasableInStore().
        $network = $config->isNetworkCatalogEnabled();

        $this->applyPurchasableBase($query, $network);

        if (! $config->isPreordersEnabled()) {
            $query->where(function (Builder $q) use ($table, $network): void {
                if ($network) {
                    $q->whereHas('storeProducts', function ($sq) use ($table) {
                        $sq->where('store_products.is_active', true)
                            ->whereRaw(self::minSaleStockSql('store_products.quantity', $table));
                    })
                        ->orWhere("{$table}.is_configurable", true)
                        ->orWhere("{$table}.stock_mode", 'ignore')
                        ->orWhereHas('storeProducts.store', fn ($s) => $s->where('ignore_stock', true));

                    return;
                }

                $q->whereRaw(self::minSaleStockSql("{$table}.quantity", $table))
                    ->orWhere("{$table}.is_configurable", true)
                    ->orWhere("{$table}.stock_mode", 'ignore')
                    ->orWhereHas('store', fn ($s) => $s->where('ignore_stock', true));
            });
        }

        return $query;
    }

    /**
     * Базовые условия покупаемости, общие для {@see self::scopePurchasable()}
     * и {@see self::scopePurchasableInStore()}: активность, не вариация,
     * ограничения модулей. Проверку цены в сетевом режиме делает оффер
     * (store_products), поэтому на карточке она не применяется.
     */
    private function applyPurchasableBase(Builder $query, bool $network): void
    {
        $config = app(ModuleConfigService::class);
        $table = $this->getTable();

        $query->where("{$table}.is_active", true)
            ->whereNull("{$table}.parent_id");

        if (! $network) {
            $query->where("{$table}.price", '>', 0);
        }

        if (! $config->isConfigurableProductsEnabled()) {
            $query->where("{$table}.is_configurable", false);
        }
    }

    /**
     * Магазин товара работает в режиме «не учитывать остатки»
     * ({@see Store::$ignore_stock}). В этом режиме товар доступен к поиску,
     * просмотру и покупке независимо от quantity.
     *
     * Сетевой каталог: остатки игнорирует магазин КОНТЕКСТА (владелец оффера),
     * а не якорный магазин-владелец карточки. Контекст берётся из загруженной
     * связи storeProducts ({@see self::storeContext()}); без него — прежнее
     * поведение по products.store_id.
     */
    public function isStockIgnored(): bool
    {
        if ($this->stock_mode === 'ignore') {
            return true;
        }

        $context = $this->storeContext();

        if ($context !== null) {
            return (bool) ($context->store?->ignore_stock ?? false);
        }

        return (bool) ($this->store?->ignore_stock ?? false);
    }

    /**
     * Остаток товара: в сетевом каталоге — остаток оффера загруженного
     * магазина ({@see self::storeContext()}), иначе колонка products.quantity.
     * Без загруженного контекста поведение прежнее, поэтому метод безопасен
     * и в легаси-режиме.
     */
    public function stockQuantity(): int
    {
        $context = $this->storeContext();

        return (int) ($context !== null ? $context->quantity : $this->quantity);
    }

    /**
     * Момент готовности товара (модуль product_ready_time).
     * null — товар готов: время не задано, модуль выключен
     * или время уже наступило.
     *
     * Готовность ЕЖЕДНЕВНАЯ: для слотов на будущую дату передаётся $onDate —
     * время готовности резолвится в этот день (утро будущего дня тоже
     * режется). Без $onDate — «сегодня» (тег «Доступно с»).
     */
    public function readyAt(?Carbon $onDate = null): ?Carbon
    {
        if (! $this->ready_fixed_time) {
            return null;
        }

        if (! app(ModuleConfigService::class)->isProductReadyTimeEnabled()) {
            return null;
        }

        $readyAt = $onDate !== null
            ? $onDate->copy()
                ->setTimezone(config('app.timezone'))
                ->setTimeFromTimeString((string) $this->ready_fixed_time)
            : Carbon::createFromTimeString(
                (string) $this->ready_fixed_time,
                config('app.timezone'),
            );

        return $readyAt->isFuture() ? $readyAt : null;
    }

    // ==================== Methods ====================

    public function isFavorite(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->favorites()->where('user_id', $user->id)->exists();
    }

    /**
     * Получить активную акцию с максимальной скидкой.
     * Использует eager loaded данные если доступны.
     */
    public function getActivePromotion(): ?Promotion
    {
        // Если activePromotions уже загружены — используем их
        if ($this->relationLoaded('activePromotions')) {
            return $this->activePromotions->first();
        }

        return $this->promotions()
            ->active()
            ->orderByDesc('discount_percent')
            ->first();
    }

    /**
     * Рассчитать эффективную цену с учётом ручной скидки и акции.
     * Применяется большая скидка из двух.
     * У варианта без собственной цены цена наследуется от parent'а.
     *
     * Сетевой каталог: цена берётся из оффера магазина, если он загружен
     * ({@see self::storeContext()}) — так считают сравнение и сметы. Там, где
     * оффер не подгружен (снапшот цены корзины/заказа), поведение прежнее:
     * колонки карточки.
     */
    public function getEffectivePrice(): float
    {
        $context = $this->priceContext();

        $ownPrice = $context !== null
            ? (float) $context->price
            : ($this->price ?? $this->parent?->price);

        if ($ownPrice === null) {
            return 0.0;
        }

        $ownPriceOld = $context !== null
            ? ($context->price_old !== null ? (float) $context->price_old : null)
            : ($this->price_old ?? $this->parent?->price_old);

        $basePrice = $ownPriceOld ?? $ownPrice;
        $manualDiscount = $ownPriceOld ? ($ownPriceOld - $ownPrice) : 0;

        $promotion = $this->getActivePromotion();
        if (! $promotion) {
            return (float) $ownPrice;
        }

        $promoDiscount = round($basePrice * $promotion->discount_percent / 100, 2);

        // Берём большую скидку
        $effectiveDiscount = max($manualDiscount, $promoDiscount);

        return round($basePrice - $effectiveDiscount, 2);
    }

    /**
     * Оригинальная цена ДО скидки (для зачёркнутой цены).
     * Возвращает базовую цену, если применена скидка (ручная или акционная),
     * иначе null. Зеркало логики getEffectivePrice() и
     * ProductResource::calculatePriceData()['priceOld'].
     * Снапшотится в cart_items.price_old / order_items.price_old.
     */
    public function getOriginalPrice(): ?float
    {
        $context = $this->priceContext();

        $ownPrice = $context !== null
            ? (float) $context->price
            : ($this->price ?? $this->parent?->price);

        if ($ownPrice === null) {
            return null;
        }

        $ownPriceOld = $context !== null
            ? ($context->price_old !== null ? (float) $context->price_old : null)
            : ($this->price_old ?? $this->parent?->price_old);

        $basePrice = (float) ($ownPriceOld ?? $ownPrice);

        // Скидка есть только когда итоговая цена ниже базовой —
        // тогда показываем зачёркнутую базовую цену, иначе скидки нет.
        return $this->getEffectivePrice() < $basePrice ? round($basePrice, 2) : null;
    }

    /**
     * Оффер магазина как источник цены для getEffectivePrice()/getOriginalPrice().
     *
     * Собственный контекст товара, а для вариации без своего оффера —
     * контекст родителя (зеркало наследования цены от parent'а). Родителя
     * трогаем только если связь уже загружена: иначе на каждой вариации
     * в легаси-режиме появлялся бы лишний запрос.
     */
    private function priceContext(): ?StoreProduct
    {
        return $this->storeContext()
            ?? ($this->relationLoaded('parent') ? $this->parent?->storeContext() : null);
    }

    /**
     * Итоговая галерея с fallback на parent'а (для вариативных товаров).
     */
    public function getDisplayImagesAttribute()
    {
        $own = $this->images ?? collect();
        if ($own->isNotEmpty()) {
            return $own;
        }

        return $this->parent?->images ?? collect();
    }

    /**
     * Вернуть дефолтный вариант для parent'а.
     * Приоритет: явный is_default_variant, затем первый по id.
     */
    public function getDefaultVariant(): ?self
    {
        if (!$this->is_configurable) {
            return null;
        }

        $explicit = $this->children()
            ->where('is_default_variant', true)
            ->where('is_active', true)
            ->first();

        if ($explicit) {
            return $explicit;
        }

        return $this->children()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Доступен ли товар покупателю (активен и не удалён).
     * Для soft-deleted записей всегда false.
     */
    /**
     * Сколько единиц остатка (г/мл/шт) в одной единице корзины.
     *
     * Остаток products.quantity хранится в наименьших единицах (12806 г), а
     * корзина товара «по кг/л» без кванта (measurement_unit kilogram/liter,
     * quantity_step ≤ 1) — в кг/л. Сравнивать их без перевода нельзя: остаток
     * 400 г был «в наличии» для продажи по 1 кг и пускал в корзину до 400 кг.
     */
    public function stockUnitsPerCartUnit(): int
    {
        $unit = $this->measurement_unit;
        if (! $unit instanceof MeasurementUnit) {
            $unit = MeasurementUnit::tryFrom((string) $unit);
        }

        return (int) ($this->quantity_step ?? 1) <= 1
            && in_array($unit, [MeasurementUnit::Kilogram, MeasurementUnit::Liter], true)
            ? 1000
            : 1;
    }

    /** Количество корзины → единицы остатка: 2 кг → 2000 г; граммы и штуки как есть. */
    public function cartQuantityInStockUnits(int $cartQuantity): int
    {
        return $cartQuantity * $this->stockUnitsPerCartUnit();
    }

    /** Остаток, с которого товар в наличии: одна порция корзины в единицах остатка. */
    public function minSaleStock(): int
    {
        return (int) ($this->quantity_step ?? 1) * $this->stockUnitsPerCartUnit();
    }

    /**
     * Тот же порог {@see self::minSaleStock()} для SQL: «остаток ≥ шаг × множитель».
     *
     * @param  string  $stockColumn  колонка остатка (products.quantity / store_products.quantity)
     * @param  string|null  $table  таблица товара для quantity_step/measurement_unit (null — без префикса)
     */
    public static function minSaleStockSql(string $stockColumn, ?string $table = 'products'): string
    {
        $p = $table !== null ? "{$table}." : '';

        return "{$stockColumn} >= {$p}quantity_step * (CASE WHEN {$p}quantity_step <= 1"
            . " AND {$p}measurement_unit IN ('kilogram', 'liter') THEN 1000 ELSE 1 END)";
    }

    /**
     * Весовой/объёмный товар: единица измерения gram/kilogram/liter/milliliter.
     * У таких товаров quantity позиции хранится в наименьших единицах (граммы/мл),
     * а product.weight не заполняется — вес позиции равен её quantity (MID-259).
     */
    public function isMeasuredByWeight(): bool
    {
        $unit = $this->measurement_unit;
        if (! $unit instanceof MeasurementUnit) {
            $unit = MeasurementUnit::tryFrom((string) $unit);
        }

        return in_array($unit, self::WEIGHT_MEASUREMENT_UNITS, true);
    }

    public function isAvailable(): bool
    {
        if ($this->trashed()) {
            return false;
        }

        return (bool) $this->is_active;
    }

    /**
     * Товар доступен только для самовывоза (поле карточки `pickup_only`;
     * per-shop смысл обеспечивает сама модель данных — товары per store).
     */
    public function isPickupOnly(): bool
    {
        return (bool) $this->pickup_only;
    }

    /**
     * Проверить, есть ли у товара какая-либо скидка (ручная или акционная).
     */
    public function hasDiscount(): bool
    {
        if ($this->price_old !== null) {
            return true;
        }

        return $this->hasActivePromotion();
    }

    /**
     * Участвует ли карточка в активной акции — «акционная» половина
     * hasDiscount(). Вынесена отдельно, потому что в сетевом каталоге ручную
     * скидку даёт оффер магазина (store_products.price_old), а не карточка:
     * там {@see \App\Services\Discount\ItemDiscountResolver} склеивает акцию
     * карточки со снапшотом скидки позиции.
     */
    public function hasActivePromotion(): bool
    {
        return $this->promotions()->active()->exists();
    }
}
