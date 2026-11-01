<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only доступ к таблице deliveries (владелец таблицы — delivery_back, общая БД).
 * Используется для резолва назначенного курьера в списке заказов админки.
 */
class Delivery extends Model
{
    use SoftDeletes;

    protected $fillable = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'user_id');
    }
}
