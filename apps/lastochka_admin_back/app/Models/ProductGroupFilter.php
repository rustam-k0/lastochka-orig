<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductGroupFilter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductGroupFilterValue::class)->orderBy('position');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
