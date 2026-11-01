<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only доступ к таблице assemblies (владелец таблицы — delivery_back, общая БД).
 * Используется для резолва ответственного сборщика в списке заказов админки.
 */
class Assembly extends Model
{
    use SoftDeletes;

    protected $fillable = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'user_id');
    }
}
