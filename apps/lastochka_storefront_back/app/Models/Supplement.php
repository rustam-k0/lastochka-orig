<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Supplement extends Model
{
    use SoftDeletes;

    public const TYPE_ONE = 'one';

    public const TYPE_MANY = 'many';

    protected $fillable = [
        'title',
        'product_id',
        'type',
        'is_required',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    // ==================== Relations ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(SupplementValue::class);
    }
}
