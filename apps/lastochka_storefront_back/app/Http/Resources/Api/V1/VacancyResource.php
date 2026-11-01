<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка вакансии раздела «Работа в компании».
 *
 * @mixin \App\Models\Vacancy
 */
final class VacancyResource extends JsonResource
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
            'workSchedule' => $this->work_schedule,
            'salary' => $this->salary,
            'shortDescription' => $this->short_description,
            'description' => $this->description,
            'responsibilities' => $this->responsibilities,
            'requirements' => $this->requirements,
            'conditions' => $this->conditions,
            'linkTitle' => $this->link_title,
            'linkUrl' => $this->link_url,
        ];
    }
}
