<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'price_type',
        'value',
        'discount_value',
        'currency',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'discount_value' => 'decimal:2',
    ];

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
