<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка сотрудника-сборщика к зоне сборки в рамках магазина.
 *
 * date = null  → постоянная привязка.
 * date = дата  → привязка на конкретную дату (приоритет над постоянной).
 */
class UserAssemblyZone extends Model
{
    protected $fillable = [
        'user_id',
        'shop_id',
        'assembly_zone_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function assemblyZone(): BelongsTo
    {
        return $this->belongsTo(AssemblyZone::class);
    }
}
