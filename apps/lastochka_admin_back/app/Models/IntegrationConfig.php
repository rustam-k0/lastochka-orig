<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    protected $fillable = [
        'integration_type',
        'driver',
        'credentials',
        'is_active',
        'sync_categories_enabled',
        'sync_images_enabled',
        'deactivate_missing_enabled',
        'tested_at',
        'test_error',
        'shop_id',
    ];

    protected $casts = [
        'credentials' => 'encrypted:json',
        'is_active' => 'boolean',
        'sync_categories_enabled' => 'boolean',
        'sync_images_enabled' => 'boolean',
        'deactivate_missing_enabled' => 'boolean',
        'tested_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        return $query->whereIn('shop_id', $shopIds);
    }
}
