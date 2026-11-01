<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OperatingCost extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['rent', 'payroll', 'marketing', 'other'];
    public const RULE_TYPES = ['fixed_amount', 'pct_of_revenue'];

    protected $table = 'store_operating_costs';

    protected $fillable = [
        'shop_id', 'category', 'rule_type', 'value',
        'period_start', 'period_end', 'comment', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('period_start')->orderBy('id');
    }
}
