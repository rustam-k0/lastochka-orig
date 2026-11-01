<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeChangesLog extends Model
{
    public const OPERATION_ADD_VARIANT = 'add_variant';
    public const OPERATION_DELETE_VARIANT = 'delete_variant';
    public const OPERATION_UPDATE_VARIANT = 'update_variant';
    public const OPERATION_SYNC_VARIANTS = 'sync_variants';
    public const OPERATION_REMOVE_ATTRIBUTE_VALUE = 'remove_attribute_value';
    public const OPERATION_GENERATE_VARIANTS = 'generate_variants';
    public const OPERATION_DETACH_VARIANT = 'detach_variant';

    protected $table = 'product_attribute_changes_log';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'operation',
        'payload',
        'admin_id',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
