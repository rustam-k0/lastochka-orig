<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Announcement extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'short_description',
        'description',
        'image_path',
        'promocode_id',
        'is_active',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ==================== Relations ====================

    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    // ==================== Scopes ====================

    /**
     * Только активные уведомления.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Фильтрация по источнику.
     */
    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Поиск по заголовку и описанию.
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', '%' . $search . '%')
              ->orWhere('short_description', 'like', '%' . $search . '%');
        });
    }

    /**
     * Сортировка по умолчанию (created_at DESC).
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }
}