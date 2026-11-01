<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperOrder
 */
class Order extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'uuid',
    'client_id',
    'shop_id',
    'type_id',
    'status_id',
    'amount',
    'origin_amount',
    'slot_start_date',
    'slot_end_date',
    'warning_time',
    'payment_type_id',
    'on_change_action_id',
    'paid',
    'payment_status',
    'client_comment',
    'card_id',
    'paid_at',
    'payment_held_at',
    'finished_at',
    'payment_id',
    'persons_count',
    'delivery_price',
    'assembly_cost',
    'discount',
    'discount_percent',
    'promocode_code',
    'promocode_type',
    'promocode_value',
    'bonus_spent',
    'items_subtotal',
    'bonus_earned',
    'has_card',
    'card_number',
    'yandex_claim_id',
    'yandex_delivery_status',
    'yandex_courier_name',
    'yandex_courier_phone',
    'yandex_delivery_price',
    'parent_order_id',
    'sub_number',
    'assembly_zone_id',
    'split_strategy',
    'shipping_method',
    'shipping_tariff_code',
    'pickup_external_id',
    'pickup_address_data',
    'cdek_order_uuid',
    'cdek_number',
    'cdek_status',
    'cdek_sent_at',
    'cdek_response',
    'rp_shipment_id',
    'rp_barcode',
    'rp_status',
    'rp_sent_at',
    'rp_response',
    'total_weight',
    'is_weight_partial',
    'is_heavy',
    'couriers_count',
    'checkout_options',
    'delivery_price_snapshot',
  ];

  protected $casts = [
    'pickup_address_data' => 'array',
    'cdek_response' => 'array',
    'rp_response' => 'array',
    'cdek_sent_at' => 'datetime',
    'rp_sent_at' => 'datetime',
    'slot_start_date' => 'datetime',
    'slot_end_date' => 'datetime',
    'warning_time' => 'datetime',
    'payment_held_at' => 'datetime',
    'finished_at' => 'datetime',
    'is_weight_partial' => 'boolean',
    'is_heavy' => 'boolean',
    'has_card' => 'boolean',
    'checkout_options' => 'array',
    'delivery_price_snapshot' => 'array',
  ];

  /**
   * Проставить finished_at при переходе заказа в статус «Доставлен»
   * (ORDER_FINISHED). finished_at — момент фактического завершения заказа
   * (доставлен/выдан); нужен вкладке «Статистика» карточки магазина, где
   * выручка за период считается по дате завершения, а не по created_at.
   *
   * Гейт по null (??=-семантика): значение проставляется ОДИН раз и не
   * перезаписывается. Покрывает повторную обработку (echo-consume race
   * RabbitMQ-трансформера, ретрай CDEK-webhook'а, дозагрузку истории).
   *
   * Запись через whereNull-update без Eloquent-событий (чтобы не триггерить
   * RabbitMQ-паблиш) + синхронизация in-memory модели.
   *
   * @return bool true, если отметка была проставлена именно этим вызовом
   */
  public static function setFinishedAtIfNeeded(self $order): bool
  {
    if ((int) $order->status_id !== Status::ORDER_FINISHED || $order->finished_at !== null) {
      return false;
    }

    $now = now();

    $affected = static::where('id', $order->id)
      ->whereNull('finished_at')
      ->update(['finished_at' => $now]);

    if ($affected === 0) {
      return false;
    }

    // Синхронизируем in-memory модель, чтобы вызывающий код видел значение
    // без повторной загрузки из БД.
    $order->finished_at = $now;
    $order->syncOriginalAttribute('finished_at');

    return true;
  }

  public function orderItems(): ?HasMany
  {
    return $this->hasMany(OrderItem::class);
  }

  public function childOrders(): HasMany
  {
    return $this->hasMany(Order::class, 'parent_order_id');
  }

  public function parentOrder(): BelongsTo
  {
    return $this->belongsTo(Order::class, 'parent_order_id');
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function client(): BelongsTo
  {
    return $this->belongsTo(Client::class);
  }

  public function clientInfo(): HasOne
  {
    return $this->hasOne(ClientOrderInfo::class);
  }

  public function card(): BelongsTo
  {
    return $this->belongsTo(ClientCard::class);
  }

  public function scopeAuth($query)
  {
    $query->where('client_id',\Auth::id());
  }

  public function scopeFinished($query)
  {
    $query->where(function ($q) {
      $q->whereStatusId(Status::ORDER_FINISHED)
        ->orWhere('paid', true);
    });
  }

  public function scopeAdmin($query)
  {
    $user = auth()->guard('api')->user();
    if($user->isGlobalScope()) {
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
}
