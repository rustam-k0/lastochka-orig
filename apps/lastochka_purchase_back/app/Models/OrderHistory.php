<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderHistory extends Model
{
    public const ACTION_CREATED = 'created';
    public const ACTION_STATUS_CHANGED = 'status_changed';
    public const ACTION_COLLECTOR_ASSIGNED = 'collector_assigned';
    public const ACTION_COURIER_ASSIGNED = 'courier_assigned';
    public const ACTION_CANCELED = 'canceled';
    public const ACTION_ITEM_REPLACED = 'item_replaced';
    public const ACTION_ITEM_CONFIRMED = 'item_confirmed';
    public const ACTION_ASSEMBLY_PAUSED = 'assembly_paused';
    public const ACTION_PAYMENT_FIXED = 'payment_fixed';
    public const ACTION_DELIVERY_COMPLETED = 'delivery_completed';

    public const ACTOR_STAFF = 'staff';
    public const ACTOR_CLIENT = 'client';
    public const ACTOR_SYSTEM = 'system';

    protected $table = 'order_history';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'action',
        'actor_type',
        'actor_id',
        'actor_name',
        'old_value',
        'new_value',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function log(
        int $orderId,
        string $action,
        string $actorType,
        ?int $actorId,
        ?string $actorName,
        ?string $oldValue,
        ?string $newValue,
        ?string $comment = null
    ): void {
        $history = self::create([
            'order_id' => $orderId,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'comment' => $comment,
            'created_at' => now(),
        ]);

        // Сквозной «путь заказа» для аналитики (LASTOCHKI-JAMI-11659):
        // каждая запись истории отражается в шину как order_stage.changed.
        \App\Services\AnalyticsEvents::orderStageChanged($history);
    }
}
