<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only доступ к таблице delivery_orders (владелец таблицы — delivery_back, общая БД).
 * Связывает заказ с рейсом курьера (Delivery). Используется для резолва
 * назначенного курьера/курьеров в списке заказов админки.
 */
class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
