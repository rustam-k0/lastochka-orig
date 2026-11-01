<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'warehouse_name',
        'quantity',
        'synced_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'synced_at' => 'datetime',
    ];

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope: только в наличии
     */
    public function scopeAvailable($query)
    {
        return $query->where('quantity', '>', 0);
    }
}
