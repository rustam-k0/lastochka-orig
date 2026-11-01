<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperCategory
 */
class Category extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  protected $fillable = [
    'title',
    'parent_id',
    'important',
    'min_quantity',
    'quantum_step',
    'quantum_unit_type_id',
    'is_cooking',
    'without_blocking_price',
    'is_perishable',
    'show_on_main',
    'is_active',
    'attribute_family_id',
    'assembly_zone_id',
    'stock_mode',
    'fixed_stock_quantity',
    'fixed_stock_unit_type_id',
    'custom_checkboxes',
    'tag_text',
    'tag_color',
  ];

  protected $casts = [
    'quantum_step' => 'integer',
    'quantum_unit_type_id' => 'integer',
    'fixed_stock_quantity' => 'integer',
    'fixed_stock_unit_type_id' => 'integer',
  ];

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model) {
      $model->link = self::getUniqueLink($model->title);
      if (!$model->is_cooking) {
        $model->is_cooking = 0;
      }
    });
    static::updating(function ($model) {
      $model->link = self::getUniqueLink($model->title, $model->id);
      if (!$model->is_cooking) {
        $model->is_cooking = 0;
      }
    });
  }

  public function childCategories(): ?HasMany
  {
    return $this->hasMany(Category::class,'parent_id','id');
  }

  public function products(): BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

  public function parent(): ?HasOne
  {
    return $this->hasOne(self::class,'id','parent_id');
  }


  public function siteCatalogImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_SITE_CATALOG_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinSiteCatalogImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_SITE_CATALOG_IMG],
      ["table_name", $this->getTable()]
    ]);
  }


  public function siteSquareImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_SITE_SQUARE_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinSiteSquareImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_SITE_SQUARE_IMG],
      ["table_name", $this->getTable()]
    ]);
  }


  public function siteRectImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_SITE_RECT_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinSiteRectImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_SITE_RECT_IMG],
      ["table_name", $this->getTable()]
    ]);
  }


  public function mobileColumnImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_MOB_COLUMN_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinMobileColumnImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_MOB_COLUMN_IMG],
      ["table_name", $this->getTable()]
    ]);
  }


  public function mobileSquareImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_MOB_SQUARE_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinMobileSquareImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_MOB_SQUARE_IMG],
      ["table_name", $this->getTable()]
    ]);
  }


  public function mobileRectImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id",Status::CODE_MOB_RECT_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinMobileRectImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_MOB_RECT_IMG],
      ["table_name", $this->getTable()]
    ]);
  }

  public function backgroundImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_BACKGROUND_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinBackgroundImg(): ?HasOne
  {
    return $this->hasOne(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_BACKGROUND_IMG],
      ["table_name", $this->getTable()]
    ]);
  }

  public function shopCategory(): HasOne
  {
    return $this->hasOne(ShopCategory::class);
  }

  public function shopCategories(): HasMany
  {
    $relation = $this->hasMany(ShopCategory::class);

    // Скоуп публикации: см. publishingOnlyForShops(). Ограничивает набор связей,
    // по которым ModelEventObserver::publishMultiEntity разошлёт category.*.
    if (self::$publishShopScope !== null) {
      $relation->whereIn('shop_id', self::$publishShopScope);
    }

    return $relation;
  }

  /**
   * Магазины, которым Observer разошлёт category.* внутри текущего замыкания.
   * null — ограничения нет (штатный фан-аут по всем связям).
   *
   * @var list<int>|null
   */
  private static ?array $publishShopScope = null;

  /**
   * Выполнить $callback так, чтобы outbox-события категорий ушли ТОЛЬКО по
   * указанным магазинам.
   *
   * Зачем: publishMultiEntity пишет в outbox по событию на КАЖДУЮ связь
   * shopCategories. Для изменения самой категории (title, картинка) это верно —
   * знать должны все магазины. Но привязка категории к одному новому магазину
   * тоже рассылалась всем, и заведение магазинов по одному давало
   * 1+2+...+N = N(N+1)/2 событий на категорию: 654 магазина за час дали ~29k
   * событий на две категории, забили диск логами и binlog'ами и уронили
   * RabbitMQ по disk alarm (инцидент 2026-08-10).
   *
   * Пустой $shopIds — сообщать некому, callback не выполняется вовсе: это
   * штатный случай (привязка оказалась идемпотентной, новых пар нет), а не
   * ошибка. Без раннего выхода whereIn по пустому списку оставил бы модель
   * без связей, и Observer сделал бы fallback publishSingleEntity — событие
   * без shop_code, которого потребители не ждут.
   *
   * @param  list<int>  $shopIds
   */
  public static function publishingOnlyForShops(array $shopIds, callable $callback): mixed
  {
    $shopIds = array_values(array_unique(array_map('intval', $shopIds)));

    if ($shopIds === []) {
      return null;
    }

    $previous = self::$publishShopScope;
    self::$publishShopScope = $shopIds;

    try {
      return $callback();
    } finally {
      self::$publishShopScope = $previous;
    }
  }

  public function assemblyZone(): BelongsTo
  {
    return $this->belongsTo(AssemblyZone::class);
  }

  public function attributes(): BelongsToMany
  {
    return $this->belongsToMany(Attribute::class,'category_attributes');
  }

  public function attributeFamily(): ?\Illuminate\Database\Eloquent\Relations\BelongsTo
  {
    return $this->belongsTo(AttributeFamily::class);
  }

  public function category1cMappings(): BelongsToMany
  {
    return $this->belongsToMany(
      Category1c::class,
      'category_1c_category',
      'category_id',
      'categories_1c_id'
    );
  }

  public function scopeParent($query): void
  {
    $query->whereNull('parent_id');
  }

  public function scopeOnlyChild($query): void
  {
    $query->whereDoesntHave('childCategories');
  }

  /**
   * Собрать ID всех предков для данного набора категорий.
   * Возвращает только те ID, которых нет в исходном массиве.
   */
  public static function collectAncestorIds(array $categoryIds): array
  {
    $ancestors = [];

    $parentIdsToResolve = static::whereIn('id', $categoryIds)
        ->whereNotNull('parent_id')
        ->pluck('parent_id')
        ->unique()
        ->diff($categoryIds)
        ->toArray();

    while (!empty($parentIdsToResolve)) {
      $ancestors = array_merge($ancestors, $parentIdsToResolve);

      $parentIdsToResolve = static::whereIn('id', $parentIdsToResolve)
          ->whereNotNull('parent_id')
          ->pluck('parent_id')
          ->unique()
          ->diff(array_merge($categoryIds, $ancestors))
          ->toArray();
    }

    return array_unique($ancestors);
  }

}
