<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssemblyZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'color',
        'is_active',
        'description',
        'position',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Сотрудники, привязанные к этой зоне (через user_assembly_zones).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_assembly_zones')
            ->withPivot(['shop_id', 'date'])
            ->withTimestamps();
    }

    public function userAssemblyZones(): HasMany
    {
        return $this->hasMany(UserAssemblyZone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
