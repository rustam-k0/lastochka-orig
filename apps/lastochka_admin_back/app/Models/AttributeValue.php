<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class AttributeValue extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'code',
        'attribute_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $value) {
            if ($value->isDirty('code') && $value->wasCodeUsedOnProducts()) {
                throw new \DomainException(
                    "Нельзя изменить code у attribute_value #{$value->id} — уже используется на товарах"
                );
            }
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function wasCodeUsedOnProducts(): bool
    {
        return ProductAttribute::query()
            ->where('attribute_value_id', $this->id)
            ->exists();
    }
}
