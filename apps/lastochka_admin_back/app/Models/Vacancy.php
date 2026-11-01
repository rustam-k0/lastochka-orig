<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Вакансия раздела «Работа в компании».
 *
 * @mixin IdeHelperVacancy
 */
class Vacancy extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    const table = "vacancies";
    protected $table = self::table;

    /** Формат работы */
    const WORK_FORMAT_OFFICE = 'office';
    const WORK_FORMAT_REMOTE = 'remote';
    const WORK_FORMAT_HYBRID = 'hybrid';
    const WORK_FORMAT_FIELD = 'field';

    /** Тип занятости */
    const EMPLOYMENT_TYPE_FULL = 'full';
    const EMPLOYMENT_TYPE_PART = 'part';
    const EMPLOYMENT_TYPE_TEMPORARY = 'temporary';
    const EMPLOYMENT_TYPE_OTHER = 'other';

    protected $fillable = [
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

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'position' => 'integer',
    ];

    protected $attributes = [
        'is_published' => false,
    ];

    /**
     * Допустимые форматы работы
     *
     * @return array<string>
     */
    public static function workFormats(): array
    {
        return [
            self::WORK_FORMAT_OFFICE,
            self::WORK_FORMAT_REMOTE,
            self::WORK_FORMAT_HYBRID,
            self::WORK_FORMAT_FIELD,
        ];
    }

    /**
     * Допустимые типы занятости
     *
     * @return array<string>
     */
    public static function employmentTypes(): array
    {
        return [
            self::EMPLOYMENT_TYPE_FULL,
            self::EMPLOYMENT_TYPE_PART,
            self::EMPLOYMENT_TYPE_TEMPORARY,
            self::EMPLOYMENT_TYPE_OTHER,
        ];
    }

    /**
     * Scope: только опубликованные вакансии
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope: фильтрация списка вакансий
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $search = $args['search'] ?? request()->input('search');
        $isPublished = $args['is_published'] ?? request()->input('is_published');
        $city = $args['city'] ?? request()->input('city');
        $workFormat = $args['work_format'] ?? request()->input('work_format');
        $employmentType = $args['employment_type'] ?? request()->input('employment_type');

        // Поиск по названию вакансии и городу
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%');
            });
        }

        if ($isPublished !== null) {
            $query->where('is_published', filter_var($isPublished, FILTER_VALIDATE_BOOLEAN));
        }

        if ($city !== null && $city !== '') {
            $query->where('city', $city);
        }

        if ($workFormat !== null && $workFormat !== '') {
            $query->where('work_format', $workFormat);
        }

        if ($employmentType !== null && $employmentType !== '') {
            $query->where('employment_type', $employmentType);
        }

        return $query;
    }

    /**
     * Scope: сортировка. По умолчанию — порядок отображения,
     * записи без позиции уходят в конец списка.
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        if (!empty($ordering)) {
            foreach ($ordering as $order) {
                $query->orderBy($order['orderColumn'] ?? $order['column'], $order['orderBy'] ?? $order['order'] ?? 'asc');
            }

            return $query;
        }

        return $query->orderByRaw('position is null, position')->orderByDesc('created_at');
    }
}
