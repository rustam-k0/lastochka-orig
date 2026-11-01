<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductGroupFilterValue extends Model
{
    use SoftDeletes;

    protected $touches = ['filter'];

    protected $fillable = [
        'product_group_filter_id',
        'value',
        'image_path',
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

    public function filter(): BelongsTo
    {
        return $this->belongsTo(ProductGroupFilter::class, 'product_group_filter_id');
    }

    public function productGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            MerchantProductGroup::class,
            'merchant_product_group_filter_values',
            'product_group_filter_value_id',
            'merchant_product_group_id'
        );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
