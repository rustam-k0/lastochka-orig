<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductAttribute extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'code',
        'name',
        'value',
        'description',
        'group_code',
        'attribute_value_id',
        'attribute_value_code',
        'position',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    // ==================== Relations ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
