<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSyncLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';
    public const STATUS_PENDING_RETRY = 'pending_retry';
    public const STATUS_SUCCESS = 'success';

    protected $table = 'order_1c_sync_logs';

    protected $fillable = [
        'order_id',
        'shop_order_id',
        'status',
        'attempt_number',
        'message',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'shop_order_id' => 'integer',
        'attempt_number' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Создать запись журнала по заказу.
     * attempt_number = MAX(attempt_number)+1 по order_id.
     * shop_order_id денормализуется из orders для чтения из admin_back.
     */
    public static function createForOrder(int $orderId, string $status, ?string $message = null): self
    {
        $shopOrderId = Order::whereKey($orderId)->value('shop_order_id');
        $nextAttempt = (int) static::where('order_id', $orderId)->max('attempt_number') + 1;

        return static::create([
            'order_id' => $orderId,
            'shop_order_id' => $shopOrderId,
            'status' => $status,
            'attempt_number' => $nextAttempt,
            'message' => $message,
        ]);
    }

    /**
     * Хелпер для статуса «ожидает повторной синхронизации».
     * Используется будущей задачей «Ручной повтор синхронизации».
     */
    public static function pendingRetry(int $orderId): self
    {
        return static::createForOrder($orderId, self::STATUS_PENDING_RETRY);
    }
}
