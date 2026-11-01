<?php

namespace App\Models;

use App\Services\PhoneFormatter;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;


class Client extends Authenticatable
{

  use
    ModelTools,
    SoftDeletes;

  protected $fillable = [
    "name",
    "phone",
    "surname",
    "patronymic",
    "newsletter_subscribed",
    "email",
    "password",
    "is_authorized",
    "google_id",
    "vkontakte_id",
    "apple_id",
    "birthday",
    "is_b2b",
    "gender_type_id",
    "admin_note",
    "loyalty_id",
    "card_number",
    "bonus_balance",
    "bonus_reserved",
  ];

  protected $hidden = ["password"];


  protected $casts = [
    "email_verified_at" => "datetime",
  ];

  protected $appends = ["available_bonus_balance"];

  protected static function booted(): void
  {
    static::addGlobalScope('onlyAuthorized', function ($builder) {
      $builder->where('is_authorized', true);
    });
  }


  public function getTitleAttribute(): string
  {
    return trim("{$this->surname} {$this->name} {$this->patronymic}");
  }

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model) {
      if (!$model->password) {
        $model->password = bcrypt(Str::random());
      }
    });

    static::created(function ($model) {
      if ($model->is_authorized) {
        $model->forceFill([
          'uuid' => 'U-' . Str::random(3) . '-' . Str::uuid()
        ]);
        $model->save();
      }
    });
  }

  public function phone(): Attribute
  {
    return Attribute::set(fn($value) => PhoneFormatter::format($value));
  }

  public function orders(): HasMany
  {
    return $this->hasMany(Order::class);
  }

  public function cards(): HasMany
  {
    return $this->hasMany(ClientCard::class);
  }

  public function ordersAmount()
  {
    return (int)$this->orders()->finished()->sum('amount');
  }
  public function ordersAvg()
  {
    return (int)$this->orders()->finished()->avg('amount');
  }

  public function bonusTransactions(): HasMany
  {
    // occurred_at пуст у pending-записей (факт из шины ещё не пришёл) и у
    // навсегда отклонённых (payload bonus_operation.rejected времени операции
    // не несёт). Сортировка по голому occurred_at топит такие записи в конец
    // при DESC (MySQL кладёт NULL последним) — COALESCE с created_at держит
    // их на своём месте по времени фактического появления записи в журнале.
    return $this->hasMany(ClientBonusTransaction::class)
      ->orderByRaw("COALESCE(occurred_at, created_at) DESC")
      ->orderByDesc("id");
  }

  /**
   * Доступно к списанию: зарезервированные под заказы баллы трогать нельзя,
   * иначе на сборке заказа списание клиента срежется.
   */
  public function getAvailableBonusBalanceAttribute(): int
  {
    return max(0, (int) $this->bonus_balance - (int) $this->bonus_reserved);
  }

}
