<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка сырого журнала analytics_events.
 *
 * Таблица append-only и партиционирована по occurred_at; вставка идёт через
 * DB::table()->insertOrIgnore() в AnalyticsEventHandler (ON CONFLICT DO NOTHING
 * по (event_id, occurred_at)). Модель — для чтения и тестов.
 */
final class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event_type',
        'occurred_at',
        'source',
        'correlation_id',
        'session_id',
        'store_id',
        'employee_id',
        'role',
        'order_id',
        'client_id',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];
}
