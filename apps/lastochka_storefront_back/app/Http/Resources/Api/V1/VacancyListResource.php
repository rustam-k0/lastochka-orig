<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Вакансия в списке раздела «Работа в компании».
 *
 * @mixin \App\Models\Vacancy
 */
final class VacancyListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'city' => $this->city,
            /** @var VacancyWorkFormat|null Формат работы: office, remote, hybrid, field */
            'workFormat' => $this->work_format?->value,
            'workFormatLabel' => $this->work_format?->label(),
            /** @var VacancyEmploymentType|null Тип занятости: full, part, temporary, other */
            'employmentType' => $this->employment_type?->value,
            'employmentTypeLabel' => $this->employment_type?->label(),
            'salary' => $this->salary,
            'shortDescription' => $this->short_description,
        ];
    }
}
