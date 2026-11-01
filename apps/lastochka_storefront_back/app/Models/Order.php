<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Models\OrderBonus;
use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'store_id',
        'client_order_id',
        'external_id',
        'external_source',
        'source',
        'status',
        'payment_type',
        'is_paid',
        'subtotal',
        'discount',
        'eligible_amount',
        'delivery_cost',
        'delivery_price_snapshot',
        'assembly_cost',
        'total',
        'items_count',
        'is_heavy',
        'couriers_count',
        'delivery_slot_start',
        'delivery_slot_end',
        'delivery_slot_display',
        'shipping_method',
        'pickup_timer_expires_at',
        'tracking_number',
        'comment',
        'checkout_options',
        'idempotency_key',
        'analytics_session_id',
        'analytics_device_client_id',
        'bonus_earned',
        'bonus_spent',
        'held_amount',
        'payment_held_at',
        'delivered_at',
        'captured_amount',
        'refunded_amount',
        'surcharge_amount',
        'surcharge_expires_at',
        'awaiting_payment_until',
        'draft_cart_item_ids',
        'client_order_finalized_at',
        'payment_card_id',
        'shipping_tariff_code',
        'pickup_external_id',
        'pickup_address_data',
        'cdek_number',
        'cdek_status',
        'rp_barcode',
        'rp_status',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_type' => PaymentType::class,
            'shipping_method' => ShippingMethod::class,
            'is_paid' => 'boolean',
            'subtotal' => 'float',
            'discount' => 'float',
            'eligible_amount' => 'float',
            'delivery_cost' => 'float',
            'delivery_price_snapshot' => 'array',
            'assembly_cost' => 'integer',
            'total' => 'float',
            'items_count' => 'integer',
            'is_heavy' => 'boolean',
            'checkout_options' => 'array',
            'couriers_count' => 'integer',
            'bonus_earned' => 'integer',
            'bonus_spent' => 'integer',
            'delivery_slot_start' => 'datetime',
            'delivery_slot_end' => 'datetime',
            'pickup_timer_expires_at' => 'datetime',
            'held_amount' => 'decimal:2',
            'payment_held_at' => 'datetime',
            'delivered_at' => 'datetime',
            'captured_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'surcharge_expires_at' => 'datetime',
            'awaiting_payment_until' => 'datetime',
            'draft_cart_item_ids' => 'array',
            'client_order_finalized_at' => 'datetime',
            'pickup_address_data' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Код магазина для публикации событий заказа.
     *
     * Магазин мог быть удалён (soft delete) уже после создания заказа: связь
     * store() такой магазин не отдаёт, и обращение к его code роняло отмену
     * заказа целиком (ALADDIN: 500 на /orders/{id}/cancel, магазин 69).
     * Поэтому код берём с withTrashed, а при полном отсутствии магазина
     * отдаём сентинел 'default' — потребители шины ждут его, а не пустую
     * строку ({@see docs rabbitmq shop_code convention}).
     */
    public function shopCodeForEvents(): string
    {
        $loaded = $this->relationLoaded('store') ? $this->getRelation('store') : null;

        return $loaded?->code
            ?? $this->store()->withTrashed()->value('code')
            ?? 'default';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Фактический состав заказа: без отменённых и заменённых при сборке позиций.
     * Именно по нему считается `subtotal`/`total` — и именно он должен попадать
     * в фискальный чек, иначе в чеке окажутся не отданные клиенту товары.
     */
    public function activeItems(): HasMany
    {
        return $this->items()->active();
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function promocode(): HasOne
    {
        return $this->hasOne(OrderPromocode::class);
    }

    public function bonus(): HasOne
    {
        return $this->hasOne(OrderBonus::class);
    }

    public function paymentCard(): BelongsTo
    {
        return $this->belongsTo(PaymentCard::class)->withTrashed();
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Последняя отменённая hold-транзакция — источник причины отказа оплаты
     * (decline_reason) для клиента в асинхронном (webhook) флоу WebView ЮKassa.
     */
    public function declinedHoldTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class)
            ->where('type', PaymentTransactionType::Hold)
            ->where('status', PaymentTransactionStatus::Canceled)
            ->latestOfMany();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function yandexClaim(): HasOne
    {
        return $this->hasOne(YandexDeliveryClaim::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(OrderReview::class);
    }

    // ==================== Scopes ====================

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::Pending);
    }

    /**
     * Черновики — заказы с неподтверждённой онлайн-оплатой
     * (модуль draft_orders_until_payment).
     */
    public function scopeDraft($query)
    {
        return $query->where('status', OrderStatus::Draft);
    }

    /**
     * Заказы, которые клиент действительно оформил.
     *
     * Прячет и сам черновик, и черновик, умерший неоплаченным: последний
     * опознаётся по незакрытому awaiting_payment_until (финализация его
     * обнуляет, отмена черновика — намеренно оставляет как маркер).
     * Клиент бросил страницу оплаты — заказа для него не было, и в истории
     * ему нечего делать.
     */
    public function scopeVisibleToClient($query)
    {
        return $query
            ->where('status', '!=', OrderStatus::Draft)
            ->whereNull('awaiting_payment_until');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', OrderStatus::Delivered);
    }

    /**
     * Слот доставки не задан либо его время ещё не истекло.
     *
     * Заказы без слота (delivery_slot_end === null — например СДЭК или Почта
     * России, где слотов нет) под ограничение не подпадают, симметрично
     * {@see isDeliverySlotExpired()}.
     */
    public function scopeDeliverySlotNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('delivery_slot_end')
                ->orWhere('delivery_slot_end', '>', now());
        });
    }

    // ==================== Accessors ====================

    public function getCanCancelAttribute(): bool
    {
        return $this->status->canCancel();
    }

    /**
     * Истекло ли время слота доставки заказа.
     *
     * Заказ без слота (delivery_slot_end === null — доставка СДЭК/Почтой России,
     * где слотов нет) под это правило не подпадает — считаем, что слот не истёк.
     * Порог — конец слота (delivery_slot_end), а не начало: сборщик мог взять
     * заказ в работу в середине слота, дополнение должно оставаться доступным
     * до конца заявленного окна доставки, а не с первой же минуты слота.
     */
    public function isDeliverySlotExpired(): bool
    {
        return $this->delivery_slot_end !== null && $this->delivery_slot_end->isPast();
    }
}
