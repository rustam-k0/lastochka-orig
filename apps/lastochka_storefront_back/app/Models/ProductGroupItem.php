<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductGroupItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_group_id',
        'product_id',
        'position',
        'quantity',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function productGroup(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
