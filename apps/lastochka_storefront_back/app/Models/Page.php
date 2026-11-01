<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Page extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'slug',
        'title',
        'content',
        'meta_title',
        'meta_description',
        'position',
        'is_published',
        'published_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // ==================== Scopes ====================

    /**
     * Только опубликованные страницы.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Порядок отображения: по позиции, записи без позиции в конце.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('position is null, position')->orderBy('id');
    }

    // ==================== Helpers ====================

    /**
     * Содержимое страницы как EditorJS-документ.
     * Битое или пустое содержимое отдаётся пустым документом,
     * чтобы клиент получил валидную структуру, а не 500.
     *
     * @return array<string, mixed>
     */
    public function contentDocument(): array
    {
        $decoded = json_decode((string) $this->content, true);

        if (! is_array($decoded) || ! isset($decoded['blocks']) || ! is_array($decoded['blocks'])) {
            return ['blocks' => []];
        }

        return $decoded;
    }
}
