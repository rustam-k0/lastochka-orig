<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use SoftDeletes;

    /**
     * Назначения «в работе» у курьера — зеркало delivery_back
     * (App\Models\DeliveryOrder::ACTIVE_STATUSES), общая таблица.
     * delivery_back считает по ним занятость курьера (User::isBusy).
     */
    public const ACTIVE_STATUSES = [
        Status::DELIVERY_WAIT_TO_START,
        Status::DELIVERY_IN_PROGRESS,
        Status::DELIVERY_ARRIVED,
    ];

    protected $fillable = [
        'order_id',
        'status_id',
        'priority',
        'started_at',
        'arrived_at',
        'finished_at',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
