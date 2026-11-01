<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Вакансия раздела «Работа в компании».
 * Наполняется из админки событиями vacancy.*, витрина только читает.
 */
final class Vacancy extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'city',
        'work_format',
        'employment_type',
        'work_schedule',
        'salary',
        'short_description',
        'description',
        'responsibilities',
        'requirements',
        'conditions',
        'link_title',
        'link_url',
        'position',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'work_format' => VacancyWorkFormat::class,
            'employment_type' => VacancyEmploymentType::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Вакансии, доступные пользователю: только опубликованные.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Порядок отображения: по позиции, записи без позиции — в конце.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('position is null, position')->orderBy('id');
    }
}
