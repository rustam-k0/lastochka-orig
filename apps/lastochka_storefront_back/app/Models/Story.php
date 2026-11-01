<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Story extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'thumbnail_path',
        'link_type',
        'link_product_id',
        'link_store_id',
        'link_external_url',
        'expires_at',
        'is_active',
        'is_quick_category',
        'position',
        'custom_checkboxes',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'link_type' => LinkType::class,
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'is_quick_category' => 'boolean',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function images(): HasMany
    {
        return $this->hasMany(StoryImage::class)->orderBy('position');
    }

    // ==================== Scopes ====================

    /**
     * Активные и не истёкшие истории.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where('expires_at', '>', now());
    }
}
