<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StoreSchedule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'day_of_week',
        'opens_at',
        'closes_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'opens_at' => 'datetime:H:i',
            'closes_at' => 'datetime:H:i',
        ];
    }

    // ==================== Relations ====================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
