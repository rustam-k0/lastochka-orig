<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $comparison_id
 * @property int $product_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ComparisonItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'comparison_id',
        'product_id',
    ];

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(Comparison::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
