<?php

namespace App\Models;

use App\Traits\HasImage;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\UserFcmToken;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Scopes\NotFinished;


/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable implements MustVerifyEmail
{
  use HasApiTokens,
    HasRoles,
    SoftDeletes, HasImage;

  protected $table = self::table;
  const table = "users";

  public function shift(): ?HasOne
  {
    return $this->hasOne(Shift::class);
  }

  public function assemblies(): HasMany
  {
    return $this->hasMany(Assembly::class);
  }

  public function delivery(): HasOne
  {
    return $this->hasOne(Delivery::class);
  }

  public function fcmTokens(): HasMany
  {
    return $this->hasMany(UserFcmToken::class);
  }

  public function shops(): BelongsToMany
  {
    // Открепление магазина в админке — soft delete строки user_shops;
    // BelongsToMany сам deleted_at пивота не учитывает.
    return $this->belongsToMany(Shop::class, 'user_shops')
      ->wherePivotNull('deleted_at');
  }

  public function userAssemblyZones(): HasMany
  {
    return $this->hasMany(UserAssemblyZone::class);
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

  public function workingHours()
  {
    $currentDayOfWeek = date('N'); // 1 (Monday) to 7 (Sunday)
    
    return $this->hasManyThrough(
      UserWorkingHour::class,
      UserShop::class,
      'user_id',
      'user_shop_id',
      'id',
      'id'
    )->where('day_of_week', $currentDayOfWeek);
  }

  public function getCompletedAssembliesCount(): int
  {
    if (!$this->hasRole('collector')) {
      return 0;
    }
    
    return $this->hasMany(Assembly::class)
      ->withoutGlobalScopes([new NotFinished()])
      ->whereNotNull('finished_at')
      ->count();
  }

  public function getCompletedDeliveryOrdersCount(): int
  {
    if (!$this->hasRole('courier')) {
      return 0;
    }

    return $this->deliveryOrders()
      ->whereNotNull('delivery_orders.finished_at')
      ->count();
  }

  /**
   * Все назначения на доставку курьера (через его рейсы).
   *
   * GlobalScope NotFinished промежуточной модели Delivery в hasManyThrough
   * не применяется — назначения завершённых рейсов тоже попадают в выборку;
   * они закрыты по своему status_id, поэтому в активные не проходят.
   */
  public function deliveryOrders(): HasManyThrough
  {
    return $this->hasManyThrough(
      DeliveryOrder::class,
      Delivery::class,
      'user_id',
      'delivery_id',
      'id',
      'id'
    );
  }

  /**
   * Число заказов «в работе» у курьера: назначен и ещё не доставлен/не отменён.
   *
   * Значение берётся из withCount-агрегата, если он уже посчитан выборкой
   * (ShiftCouriers) — иначе считается запросом. Роль не проверяется: у не-курьера
   * назначений нет по построению, а hasRole в списке курьеров дал бы N+1.
   */
  public function getActiveDeliveryOrdersCount(): int
  {
    if ($this->active_delivery_orders_count !== null) {
      return (int) $this->active_delivery_orders_count;
    }

    return $this->deliveryOrders()->active()->count();
  }

  /** Курьер занят: есть хотя бы одно незакрытое назначение на доставку. */
  public function isBusy(): bool
  {
    return $this->getActiveDeliveryOrdersCount() > 0;
  }

  public function detachOrders(): void
  {

    // Закрыть открытые смены ПЕРЕД удалением: иначе shift.ended не
    // публикуется и рабочая сессия в аналитике зависает открытой (#11599).
    $openShifts = Shift::whereUserId($this->id)->get();

    foreach ($openShifts as $shift) {
      $shift->finish();
    }

    // Удаление смен пользователя. Только NotFinished снимаем: после finish()
    // смены закрыты и штатный scope спрятал бы их от delete; SoftDeletes
    // оставляем — delete обязан остаться мягким.
    if ($openShifts->isNotEmpty()) {
      Shift::withoutGlobalScope(\App\Models\Scopes\NotFinished::class)
        ->whereIn('id', $openShifts->pluck('id'))
        ->delete();
    }

    //Удаление доставки
    if ($this->hasRole(Role::COURIER)) {

      $delivery = $this->delivery()->with('deliveryOrders.order')->first();

      $orders = $delivery ? $delivery->deliveryOrders : [];

      foreach ($orders as $order) {
        $order->order->update([
          'status_id' => Status::ORDER_ASSEMBLED
        ]);

        $order->delete();
      }

      $delivery?->delete();
    }

    //Удаление сборки
    if ($this->hasRole(Role::COLLECTOR)) {
      $assemblies = $this->assemblies()->with('order')->get();
      foreach ($assemblies as $assembly) {
        $assembly->order->update(['status_id' => Status::ORDER_CREATED]);
        $assembly->delete();
      }
    }

  }


}
