<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Дневной агрегат сотрудника (#11596).
 *
 * Пишется командой analytics:aggregate-daily (upsert по
 * employee_id + role + stat_date), читается API /api/v1/analytics/*.
 */
final class EmployeeDailyStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stat_date' => 'date',
        'gmv_amount' => 'float',
        'rating_sum' => 'float',
    ];
}
