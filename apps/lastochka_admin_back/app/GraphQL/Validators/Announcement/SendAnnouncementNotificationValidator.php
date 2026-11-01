<?php

namespace App\GraphQL\Validators\Announcement;

use Nuwave\Lighthouse\Validation\Validator;

class SendAnnouncementNotificationValidator extends Validator
{
    public function rules(): array
    {
        return [
            'announcement_id' => ['required', 'exists:announcements,id'],
            'target' => ['required', 'in:ALL_USERS,ACTIVE_USERS,SEGMENT'],
            'segment_criteria' => ['sometimes', 'array'],
            'segment_criteria.gender' => ['sometimes', 'in:male,female'],
            'segment_criteria.age_from' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'segment_criteria.age_to' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'segment_criteria.city' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'announcement_id.required' => 'ID уведомления обязательно',
            'announcement_id.exists' => 'Уведомление не найдено',
            'target.required' => 'Цель отправки обязательна',
            'target.in' => 'Недопустимая цель отправки',
        ];
    }
}