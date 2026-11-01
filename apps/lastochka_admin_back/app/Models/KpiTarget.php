<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiTarget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'metric_key', 'shop_id', 'category_id',
        'period_start', 'period_end', 'target_value', 'comment', 'is_active',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('period_start')->orderBy('metric_key')->orderBy('id');
    }
}
