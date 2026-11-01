<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeFamilyAttribute extends Model
{
    protected $table = 'attribute_family_attributes';

    protected $fillable = [
        'attribute_family_id',
        'attribute_id',
        'position',
        'is_required',
        'is_configurable',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_required' => 'boolean',
        'is_configurable' => 'boolean',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(AttributeFamily::class, 'attribute_family_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
