<?php

namespace App\Models;

use App\Models\AdminSetting;
use App\Services\PhoneFormatter;
use App\Services\Sms\SmsTextBuilder;
use App\Services\SmsAero;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;


/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable implements MustVerifyEmail
{
  use HasApiTokens,
    HasFactory,
    Notifiable,
    HasRoles,
    ModelTools,
    HasImage,
    SoftDeletes;

  protected $table = self::table;
  const table = "users";

  protected $fillable = ["name", "phone", "surname", "patronymic", "email", "password", "external_user_id"];

  protected $hidden = ["password", "two_factor_secret"];

  protected $casts = [
    "email_verified_at" => "datetime",
    "two_factor_confirmed_at" => "datetime",
    "two_factor_secret" => "encrypted",
    "two_factor_last_otp_timestamp" => "integer",
  ];

  public function getTitleAttribute(): string
  {
    return trim("{$this->surname} {$this->name} {$this->patronymic}");
  }

  public function getTwoFactorEnabledAttribute(): bool
  {
    return !is_null($this->two_factor_confirmed_at);
  }

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model) {
      if (!$model->password) {
        $model->password = bcrypt(Str::random());
      }
      $model->external_user_id = sha1(\Str::random(300));
    });
  }

  public function phone(): Attribute
  {
    return Attribute::set(fn($value) => PhoneFormatter::format($value));
  }

  public function shops(): HasMany
  {
    return $this->hasMany(UserShop::class);
  }

  public function userAssemblyZones(): HasMany
  {
    return $this->hasMany(UserAssemblyZone::class);
  }

  public function assemblyZones(): BelongsToMany
  {
    return $this->belongsToMany(AssemblyZone::class, 'user_assembly_zones')
      ->withPivot(['shop_id', 'date'])
      ->withTimestamps();
  }

  /**
   * Резолв зоны сборки сотрудника для магазина на дату.
   * Приоритет: привязка на конкретную дату → постоянная (date = null) → null.
   *
   * null означает «зона не назначена» — сборщик видит все заказы магазина.
   */
  public function resolveAssemblyZoneId(int $shopId, ?\Illuminate\Support\Carbon $date = null): ?int
  {
    $date = $date ?? \Illuminate\Support\Carbon::today();

    $byDate = $this->userAssemblyZones()
      ->where('shop_id', $shopId)
      ->whereDate('date', $date->toDateString())
      ->value('assembly_zone_id');

    if ($byDate !== null) {
      return (int) $byDate;
    }

    $permanent = $this->userAssemblyZones()
      ->where('shop_id', $shopId)
      ->whereNull('date')
      ->value('assembly_zone_id');

    return $permanent !== null ? (int) $permanent : null;
  }

  public function sendCredentials(string $password): void
  {
    $message = SmsTextBuilder::password($password);

    $sms_service = new SmsAero();
    $sms_service->send($message, $this->phone);
  }

  /**
   * Переопределяем стандартное уведомление брокера паролей,
   * чтобы отправлять брендированное письмо на русском со ссылкой на /admin/restore.
   */
  public function sendPasswordResetNotification($token): void
  {
    $this->notify(new \App\Notifications\ResetPasswordNotification($token));
  }

  public function scopeNotMe($query)
  {
    $query->whereNot('id', \Auth::id());
  }

  public function scopeSearch($query, ?array $args)
  {
    $search = $args['search'] ?? null;
    if ($search) {
      $query->where(function ($q) use ($search) {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('surname', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%");
      });
    }
  }

  public function scopeCollector($query)
  {
    $query->whereRelation('roles', 'name', Role::COLLECTOR);
  }

  public function scopeCourier($query)
  {
    $query->whereRelation('roles', 'name', Role::COURIER);
  }

  /**
   * Ограничивает список сотрудников теми, кого вправе видеть текущий пользователь.
   * Глобальный админ — без ограничений; партнёр (shop-scope) — только сборщики
   * и курьеры своих магазинов.
   */
  public function scopeAccessible($query)
  {
    $authUser = \Auth::user();

    if (!$authUser || $authUser->isGlobalScope()) {
      return;
    }

    $shopIds = \App\Support\PartnerStaffAccess::ownShopIds($authUser);

    $query
      ->whereHas('shops', fn($q) => $q->whereIn('shop_id', $shopIds))
      ->whereHas('roles', fn($q) => $q->whereIn('name', \App\Support\PartnerStaffAccess::ALLOWED_ROLES));
  }

  public function isGlobalScope(): bool
  {
    if (AdminSetting::getValue('role_access_matrix_enabled') !== '1') {
      return $this->hasRole(Role::ADMIN);
    }

    return $this->roles->contains('scope_type', Role::SCOPE_GLOBAL);
  }

  public function isShopScope(): bool
  {
    if (AdminSetting::getValue('role_access_matrix_enabled') !== '1') {
      return $this->hasRole(Role::SHOP);
    }

    return $this->roles->contains('scope_type', Role::SCOPE_SHOP);
  }

  public function isSuperAdmin(): bool
  {
    return $this->hasRole(Role::SUPERADMIN);
  }

}
