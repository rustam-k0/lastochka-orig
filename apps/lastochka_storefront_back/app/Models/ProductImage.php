<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductImage extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'path',
        'source_url',
        'type',
        'mime_type',
        'position',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
