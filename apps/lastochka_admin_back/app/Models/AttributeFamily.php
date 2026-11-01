<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttributeFamily extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'code',
        'is_system',
        'position',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_family_attributes')
            ->withPivot(['id', 'position', 'is_required', 'is_configurable'])
            ->orderByPivot('position');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
