<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;


/**
 * @mixin IdeHelperOrder
 */
class Order extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  protected $fillable = [
    'uuid',
    'client_id',
    'shop_id',
    'type_id',
    'status_id',
    'amount',
    'origin_amount',
    'items_subtotal',
    'slot_start_date',
    'slot_end_date',
    'warning_time',
    'payment_type_id',
    'on_change_action_id',
    'paid',
    'client_comment',
    'card_id',
    'paid_at',
    'payment_held_at',
    'payment_id',
    'payment_status',
    'persons_count',
    'delivery_price',
    'yandex_claim_id',
    'yandex_delivery_status',
    'yandex_courier_name',
    'yandex_courier_phone',
    'yandex_delivery_price',
    'pickup_ready_at',
    'picked_up_at',
    'picked_up_by_user_id',
    'finished_at',
  ];

  protected $casts = [
    'slot_start_date' => 'datetime',
    'slot_end_date' => 'datetime',
    'warning_time' => 'datetime',
    'finished_at' => 'datetime',
    'paid_at' => 'datetime',
    'payment_held_at' => 'datetime',
    'is_weight_partial' => 'boolean',
    'is_heavy' => 'boolean',
    // MID-280: снимок условий доставки из события заказа витрины ({base_price, ranges, checkout_price})
    'delivery_price_snapshot' => 'array',
  ];

  public function orderItems(): ?HasMany
  {
    return $this->hasMany(OrderItem::class);
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function client(): BelongsTo
  {
    return $this->belongsTo(Client::class);
  }

  /**
   * Сотрудник, подтвердивший выдачу самовывоза (OrderPickupConfirm).
   * Staff — read-модель без глобального scope onlyAdmin (см. OrderCollectorResolver).
   */
  public function pickedUpBy(): BelongsTo
  {
    return $this->belongsTo(Staff::class, 'picked_up_by_user_id');
  }

  public function clientInfo(): HasOne
  {
    return $this->hasOne(ClientOrderInfo::class);
  }

  public function scopeAuth($query)
  {
    $query->where('client_id',\Auth::id());
  }

  /**
   * Включён ли модуль «Назначение курьера сборщиком» (courier_assignment).
   * Читает project_modules из admin_settings (общая БД, зеркало delivery_back).
   */
  public static function isCourierAssignmentModuleEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['courier_assignment']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Цены от количества» (price_tiers, MID-308). Флаг гейтит
   * только построение НОВОГО снапшота лесенки при смене магазина заказа (и чтение
   * таблицы price_tiers). Сохранение снапшота из события, base_price в блоке цены
   * товара и чтение снапшота позиции от флага не зависят.
   * Без мемоизации, как соседние флаги. Любая ошибка чтения = выключено
   * (fail-safe на прежние формулы).
   */
  public static function isPriceTiersModuleEnabled(): bool
  {
    try {
      $raw = AdminSetting::getValue('project_modules');
      $modules = $raw ? (json_decode($raw, true) ?: []) : [];

      return (bool) ($modules['price_tiers']['enabled'] ?? false);
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * Включён ли модуль «Списание оплаты сборщиком» (collector_payment).
   */
  public static function isCollectorPaymentModuleEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['collector_payment']['enabled'] ?? false);
  }

  /**
   * Включён ли модуль «Оформление заказа без слотов» (checkout_without_slots,
   * MID-256): заказы с витрины приходят без слота, в экспорте слот не печатаем.
   */
  public static function isCheckoutWithoutSlotsModuleEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['checkout_without_slots']['enabled'] ?? false);
  }

  /**
   * Включён ли флоу «Тяжёлые заказы». Совместная доставка входит в
   * «Назначение курьера сборщиком», поэтому любой из двух модулей включает
   * признак — зеркало heavyOrdersModuleEnabled в admin_front.
   */
  public static function isHeavyOrdersFlowEnabled(): bool
  {
    $raw = AdminSetting::getValue('project_modules');
    $modules = $raw ? (json_decode($raw, true) ?: []) : [];

    return (bool) ($modules['heavy_orders']['enabled'] ?? false)
      || (bool) ($modules['courier_assignment']['enabled'] ?? false);
  }

  public function scopeAdmin($query)
  {
    $query->whereNull('parent_order_id');

    // Eager-load для полей карточки заказа (collector/couriers резолверы),
    // чтобы не было N+1 по assemblies/delivery_orders на каждый заказ списка.
    // Агрегация идёт по self + child заказам, поэтому грузим и childOrders.
    $query->with([
      'assemblies.user',
      'childOrders.assemblies.user',
      'deliveryOrders.delivery.user',
      'childOrders.deliveryOrders.delivery.user',
    ]);

    // Глобальные роли (admin, а при включённой матрице — superadmin и
    // кастомные вроде «Товаровед») видят все заказы; shop-scope — свой магазин.
    $user = auth()->guard('api')->user();
    if($user->seesAllShopsOrders()) {
      return $query;
    }

    return $query->where('shop_id',$user->shops()->first()->shop_id);
  }

  public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
    return $this->morphMany(Review::class,'reviewable');
  }

  public function review(): \Illuminate\Database\Eloquent\Relations\MorphOne
  {
    return $this->morphOne(Review::class,'reviewable');
  }

  public function childOrders(): HasMany
  {
    return $this->hasMany(Order::class, 'parent_order_id');
  }

  public function assemblies(): HasMany
  {
    return $this->hasMany(Assembly::class);
  }

  public function deliveryOrders(): HasMany
  {
    return $this->hasMany(DeliveryOrder::class);
  }

  public function parentOrder(): BelongsTo
  {
    return $this->belongsTo(Order::class, 'parent_order_id');
  }

  public function assemblyZone(): BelongsTo
  {
    return $this->belongsTo(AssemblyZone::class);
  }

  /**
   * ID самого заказа и его дочерних подзаказов (без учёта родителя, если
   * вызвано на дочернем). При расщеплении по зонам сборки (модуль "Зоны
   * сборки" в delivery_back) сборка и доставка фактически привязаны к
   * дочернему order_id, а не к родительскому — поэтому резолверы
   * collector/couriers ищут среди этого набора id, а не только по своему.
   * См. аналогичный паттерн в delivery_back OrderPackagesResolver.
   */
  public function selfAndChildOrderIds(): array
  {
    if ($this->parent_order_id !== null) {
      return [$this->id];
    }

    $childIds = $this->childOrders()->pluck('id')->all();

    return array_merge([$this->id], $childIds);
  }

  /**
   * Сквозной номер заказа = uuid (id заказа в сторфронте;
   * у подзаказов с суффиксом '-N'). Fallback на внутренний id,
   * если uuid отсутствует (не должен встречаться: колонка NOT NULL).
   */
  public function getNumberAttribute(): string
  {
    if ($this->uuid !== null && $this->uuid !== '') {
      return (string) $this->uuid;
    }

    $parentId = $this->parent_order_id ?? $this->id;

    if ($this->sub_number !== null) {
      return $parentId . '-' . $this->sub_number;
    }

    return (string) $parentId;
  }

  /**
   * Метка заказа — сквозной номер (например '5' или '5-1').
   * Сохранена как поле для обратной совместимости (мобилка сотрудников).
   */
  public function getLabelAttribute(): string
  {
    return $this->number;
  }

  /**
   * Признак оплаты для отображения: true — деньги получены и не возвращены.
   * `paid` монотонен и не сбрасывается при возврате (см. admin_back
   * OrderEventTransformer::processRefundedEvent) — поэтому комбинируем
   * с payment_status. Только `refunded` аннулирует is_paid: это единственный
   * статус, представляющий реальный возврат уже полученных денег.
   * `failed` НЕ исключается: это может быть неудачная ПОВТОРНАЯ попытка
   * оплаты уже оплаченного заказа (retryPayment() в storefront_back не
   * проверяет, оплачен ли заказ, — см. OrderController::pay()), и такая
   * неудача не отменяет уже полученную оплату.
   */
  public function getIsPaidAttribute(): bool
  {
    return (bool) $this->paid && $this->payment_status !== 'refunded';
  }

}


