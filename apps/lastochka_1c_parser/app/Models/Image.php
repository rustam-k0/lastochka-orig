<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'path',
        'external_id',
        'sort',
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * URL изображения
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }
}
