<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    protected $fillable = [
        'integration_type',
        'driver',
        'credentials',
        'is_active',
        'tested_at',
        'test_error',
    ];

    protected $casts = [
        'credentials' => 'encrypted:json',
        'is_active' => 'boolean',
        'tested_at' => 'datetime',
    ];

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('integration_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
