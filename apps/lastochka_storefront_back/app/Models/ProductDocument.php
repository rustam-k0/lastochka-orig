<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'title',
        'file_path',
        'source_url',
        'original_name',
        'mime_type',
        'size',
        'group',
        'position',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
