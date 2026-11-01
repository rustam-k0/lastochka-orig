<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopAlertRule extends Model
{
    use SoftDeletes;

    public const COMPARISONS = ['lt', 'lte', 'gt', 'gte'];

    protected $fillable = [
        'name', 'metric_key', 'comparison', 'threshold_value',
        'recommended_action_text', 'shop_id', 'is_active', 'position',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
