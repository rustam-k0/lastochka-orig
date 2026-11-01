<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperShop
 */
class Shop extends Model
{
  use  ModelTools, SoftDeletes, HasImage;

  protected $attributes = [
    'slot_time' => 60,
  ];

  protected $fillable = [
    'code',
    'city_id',
    'is_default',
    'title',
    'phone',
    'email',
    'slot_time',
    'slot_max_orders',
    'longitude',
    'latitude',
    'address',
    'sphere_id',
    'schedule',
    'commission',
    'type_id',
    'status_id',
    'deposit',
    'description',
    'payment_type_id',
    'is_popular',
    'is_b2b',
    'ignore_stock',
    'is_bonus_enabled',
    'bonus_earn_percent',
    'bonus_spend_percent',
    'is_pickup_enabled',
    'min_pickup_order_amount',
    'heavy_order_threshold',
    'assembly_price',
    'area_sqm',
    'pickup_payment_methods',
    'pickup_auto_cancel_enabled',
    'pickup_wait_perishable_hours',
    'pickup_wait_regular_hours',
    'pickup_grace_period_hours',
    'custom_checkboxes',
  ];

  protected static function booted(): void
  {
    static::creating(function (Shop $shop) {
      if (empty($shop->code) && !empty($shop->title)) {
        $shop->code = static::generateCodeFromTitle($shop->title);
      }
    });

    // Дочерние сущности удалённого магазина должны удаляться вместе с ним,
    // иначе non-null поле shop в GraphQL отдаёт null и роняет целые списки
    // (форма сотрудника, страница товарных групп и т.д.).
    static::deleting(function (Shop $shop) {
      if ($shop->isForceDeleting()) {
        $shop->workers()->withTrashed()->forceDelete();
      } else {
        $shop->workers()->delete();
      }

      // Merchant-сущности синхронизируются в storefront — удаляем поштучно
      // через Eloquent, чтобы observer'ы положили deleted-события в outbox.
      foreach (['productGroups', 'banners', 'sales', 'reviews', 'promocodes'] as $relation) {
        if ($shop->isForceDeleting()) {
          $shop->{$relation}()->withTrashed()->get()->each->forceDelete();
        } else {
          $shop->{$relation}()->get()->each->delete();
        }
      }
    });
  }

  protected function casts(): array
  {
    return [
      'ignore_stock' => 'boolean',
      'is_default' => 'boolean',
      'is_bonus_enabled' => 'boolean',
      'bonus_earn_percent' => 'integer',
      'bonus_spend_percent' => 'integer',
      'is_pickup_enabled' => 'boolean',
      'pickup_payment_methods' => 'array',
      'pickup_auto_cancel_enabled' => 'boolean',
      'pickup_wait_perishable_hours' => 'integer',
      'pickup_wait_regular_hours' => 'integer',
      'pickup_grace_period_hours' => 'integer',
    ];
  }

  /**
   * Генерирует уникальный code из title (транслитерация + slug)
   */
  public static function generateCodeFromTitle(string $title): string
  {
    $slug = \Str::slug($title);

    // Если slug пустой (например, только кириллица без транслита), делаем транслитерацию
    if (empty($slug)) {
      $slug = \Str::slug(static::transliterate($title));
    }

    // Обеспечиваем уникальность
    $originalSlug = $slug;
    $counter = 1;

    while (static::where('code', $slug)->exists()) {
      $slug = $originalSlug . '-' . $counter;
      $counter++;
    }

    return $slug;
  }

  /**
   * Транслитерация кириллицы в латиницу
   */
  protected static function transliterate(string $string): string
  {
    $translitMap = [
      'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
      'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
      'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
      'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
      'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
      'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
      'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
      'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
      'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
      'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
      'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
      'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
      'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
      'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
    ];

    return strtr($string, $translitMap);
  }

  public function workingHours(): HasMany
  {
    return $this->hasMany(ShopWorkingHour::class)->orderBy('day_of_week');
  }

  public function workers(): HasMany
  {
    return $this->hasMany(UserShop::class);
  }

  public function zones(): HasMany
  {
    return $this->hasMany(ShopZone::class);
  }

  public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
  {
    return $this->belongsTo(City::class);
  }

  public function getWorkingDaysAsArray()
  {
    return $this->workingHours->pluck('day_of_week');
  }

  public function getGetOpeningTime()
  {
    return $this->workingHours?->first()?->opening_time;
  }

  public function getGetClosingTime()
  {
    return $this->workingHours?->first()?->closing_time;
  }

  public function getWorkType()
  {
    return $this->workingHours?->first()?->type_id;
  }

  public function joinLogoImg(): ?BelongsTo
  {
    return $this->belongsTo(JoinImg::class, "id", "item_id")
      ->where([
        ["head_id", Status::CODE_LOGO_IMG],
        ["table_name", $this->getTable()]
      ]);
  }
  public function logoImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_LOGO_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function joinBackgroundImg(): ?BelongsTo
  {
    return $this->belongsTo(JoinImg::class, "id", "item_id")
      ->where([
        ["head_id", Status::CODE_BACKGROUND_IMG],
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

  public function categories(): BelongsToMany
  {
    return $this->belongsToMany(Category::class, 'shops_categories');
  }

  public function scopeUserShop($query): void
  {
    $shop_id = \Auth::user()?->shops()->first()?->shop_id;

    $query->whereId($shop_id);
  }

  /**
   * Ограничивает список магазинов доступными текущему пользователю.
   * Глобальный админ — все магазины; партнёр (shop-scope) — только свои.
   */
  public function scopeAccessible($query): void
  {
    $authUser = \Auth::user();

    if (!$authUser || $authUser->isGlobalScope()) {
      return;
    }

    $query->whereIn('id', \App\Support\PartnerStaffAccess::ownShopIds($authUser));
  }

  /**
   * Получение отзывов магазина (MerchantReview)
   */
  public function reviews(): HasMany
  {
    return $this->hasMany(MerchantReview::class);
  }

  /**
   * Получение отзывов на товары магазина (ProductReview)
   */
  public function productReviews(): HasMany
  {
    return $this->hasMany(ProductReview::class);
  }

  /**
   * Получение промокодов магазина
   */
  public function promocodes(): HasMany
  {
    return $this->hasMany(MerchantPromocode::class);
  }

  /**
   * Получение товарных групп магазина
   */
  public function productGroups(): HasMany
  {
    return $this->hasMany(MerchantProductGroup::class);
  }

  /**
   * Получение баннеров магазина
   */
  public function banners(): HasMany
  {
    return $this->hasMany(MerchantBanner::class);
  }

  /**
   * Получение акций магазина
   */
  public function sales(): HasMany
  {
    return $this->hasMany(MerchantSale::class);
  }

}
