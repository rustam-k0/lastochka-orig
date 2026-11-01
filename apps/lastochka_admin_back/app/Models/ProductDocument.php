<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductDocument extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    protected $fillable = [
        'product_id',
        'title',
        'file_path',
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
