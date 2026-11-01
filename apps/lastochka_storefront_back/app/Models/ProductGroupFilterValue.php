<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductGroupFilterValue extends Model
{
    use SoftDeletes;

    protected $fillable = ['product_group_filter_id', 'value', 'image_path', 'position', 'is_active'];

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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
