<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneCExchangeStatus extends Model
{
    protected $table = 'onec_exchange_statuses';

    protected $fillable = [
        'data_type',
        'success',
        'error',
        'records_count',
        'duration_seconds',
        'counters',
        'warnings',
        'last_exchange_at',
        'shop_id',
    ];

    protected $casts = [
        'success' => 'boolean',
        'counters' => 'json',
        'warnings' => 'json',
        'last_exchange_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Пороги устаревания (в часах) по типу данных.
     */
    public const STALE_THRESHOLDS = [
        'catalog_import' => 48,
        'offers_import' => 2,
        'rests_import' => 2,
        'orders_import' => 2,
        'orders_export' => 2,
    ];

    public function isStale(): bool
    {
        if (!$this->last_exchange_at) {
            return false;
        }

        $threshold = self::STALE_THRESHOLDS[$this->data_type] ?? 2;

        return $this->last_exchange_at->diffInHours(now()) >= $threshold;
    }

    public function getStatusLabelAttribute(): string
    {
        if (!$this->last_exchange_at) {
            return 'no_data';
        }

        if (!$this->success) {
            return 'error';
        }

        if ($this->isStale()) {
            return 'stale';
        }

        if (!empty($this->warnings)) {
            return 'warnings';
        }

        return 'ok';
    }
}
