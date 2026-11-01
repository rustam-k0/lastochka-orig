<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class City extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Магазин по умолчанию в городе — не более одного (инвариант держит курьерка).
     * Неактивные магазины дефолтом не считаются: витрине их всё равно нечего показать.
     */
    public function defaultStore(): HasOne
    {
        return $this->hasOne(Store::class)
            ->where('is_default', true)
            ->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
