<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Address;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasLat = $this->has('latitude');
            $hasLng = $this->has('longitude');

            if ($hasLat !== $hasLng) {
                $v->errors()->add('latitude', 'Широта и долгота должны передаваться вместе');

                return;
            }

            if (! $hasLat || ! $hasLng) {
                return;
            }

            $lat = $this->input('latitude');
            $lng = $this->input('longitude');

            if ($lat !== null && $lng !== null && (float) $lat === 0.0 && (float) $lng === 0.0) {
                $v->errors()->add('latitude', 'Координаты не могут быть нулевыми — выберите адрес на карте или повторите геокодинг');
                $v->errors()->add('longitude', 'Координаты не могут быть нулевыми — выберите адрес на карте или повторите геокодинг');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (! app(\App\Services\Settings\ModuleConfigService::class)->isAddressObjectsEnabled()) {
            $this->merge(['isObject' => false, 'objectData' => null]);

            return;
        }

        $objectData = $this->input('objectData');

        if (! is_array($objectData)) {
            return;
        }

        if ((bool) ($objectData['is247'] ?? false)) {
            $objectData['workingHoursFrom'] = null;
            $objectData['workingHoursTo'] = null;
        }

        if ((bool) ($objectData['isDaily'] ?? false)) {
            $objectData['workingDays'] = [];
        }

        $this->merge(['objectData' => $objectData]);
    }

    public function rules(): array
    {
        return [
            /**
             * Название адреса (для быстрого выбора).
             *
             * @example Работа
             */
            'title' => ['nullable', 'string', 'max:50'],
            /**
             * Страна.
             *
             * @example Россия
             */
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            /**
             * Город.
             *
             * @example Москва
             */
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            /**
             * Улица.
             *
             * @example Тверская
             */
            'street' => ['sometimes', 'required', 'string', 'max:200'],
            /**
             * Номер дома.
             *
             * @example 15
             */
            'houseNumber' => ['sometimes', 'required', 'string', 'max:20'],
            /**
             * Подъезд.
             *
             * @example 1
             */
            'entrance' => ['nullable', 'string', 'max:10'],
            /**
             * Этаж.
             *
             * @example 3
             */
            'floor' => ['nullable', 'string', 'max:10'],
            /**
             * Номер квартиры/офиса.
             *
             * @example 301
             */
            'apartment' => ['nullable', 'string', 'max:20'],
            /**
             * Код домофона.
             *
             * @example 301#
             */
            'intercom' => ['nullable', 'string', 'max:20'],
            /**
             * Комментарий для курьера.
             *
             * @example Вход со двора
             */
            'comment' => ['nullable', 'string', 'max:500'],
            /**
             * Широта (от -90 до 90). При передаче обязательна вместе с долготой.
             *
             * @example 55.7558
             */
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            /**
             * Долгота (от -180 до 180). При передаче обязательна вместе с широтой.
             *
             * @example 37.6173
             */
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            /**
             * Является ли адрес коммерческим/строительным объектом. При переключении true→false фото удаляются и object_data обнуляется.
             *
             * @example true
             */
            'isObject' => ['nullable', 'boolean'],
            /**
             * Дополнительные данные объекта. Обязательно если isObject=true.
             */
            'objectData' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => (bool) $this->input('isObject')),
                'nullable',
                'array',
            ],
            /**
             * Название объекта. Обязательно при isObject=true.
             *
             * @example Многоэтажка
             */
            'objectData.name' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => (bool) $this->input('isObject')),
                'nullable',
                'string',
                'max:200',
            ],
            /**
             * ФИО контактного лица на объекте.
             *
             * @example Иванов Иван Иванович
             */
            'objectData.contactName' => ['nullable', 'string', 'max:200'],
            /**
             * Телефон контактного лица.
             *
             * @example +79991234567
             */
            'objectData.contactPhone' => ['nullable', 'string', 'regex:/^\+?\d{10,15}$/'],
            /**
             * Название компании-объекта.
             *
             * @example Звёздочка
             */
            'objectData.company' => ['nullable', 'string', 'max:200'],
            /**
             * Размер лифта (свободный текст).
             *
             * @example 1,5 на 2
             */
            'objectData.liftSize' => ['nullable', 'string', 'max:50'],
            /**
             * Есть ли лифт. null = неизвестно.
             *
             * @example true
             */
            'objectData.hasLift' => ['nullable', 'boolean'],
            /**
             * Нужен ли пропуск курьеру.
             *
             * @example false
             */
            'objectData.needsPass' => ['nullable', 'boolean'],
            /**
             * Возможен ли проезд крупногабарита.
             *
             * @example true
             */
            'objectData.canOversized' => ['nullable', 'boolean'],
            /**
             * Время начала приёма (HH:mm).
             *
             * @example 10:00
             */
            'objectData.workingHoursFrom' => ['nullable', 'date_format:H:i'],
            /**
             * Время окончания приёма (HH:mm).
             *
             * @example 20:00
             */
            'objectData.workingHoursTo' => ['nullable', 'date_format:H:i', 'after:objectData.workingHoursFrom'],
            /**
             * Круглосуточный приём.
             *
             * @example false
             */
            'objectData.is247' => ['nullable', 'boolean'],
            /**
             * Дни работы (mon|tue|wed|thu|fri|sat|sun).
             *
             * @example ["mon","tue","wed","thu","fri"]
             */
            'objectData.workingDays' => ['nullable', 'array'],
            'objectData.workingDays.*' => ['string', \Illuminate\Validation\Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            /**
             * Работает ежедневно (обнуляет workingDays).
             *
             * @example false
             */
            'objectData.isDaily' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'country.required' => 'Укажите страну',
            'country.max' => 'Название страны слишком длинное',
            'city.required' => 'Укажите город',
            'city.max' => 'Название города слишком длинное',
            'street.required' => 'Укажите улицу',
            'street.max' => 'Название улицы слишком длинное',
            'houseNumber.required' => 'Укажите номер дома',
            'houseNumber.max' => 'Номер дома слишком длинный',
            'comment.max' => 'Комментарий слишком длинный',
            'latitude.required' => 'Укажите широту адреса',
            'latitude.numeric' => 'Широта должна быть числом',
            'latitude.between' => 'Широта должна быть в диапазоне от -90 до 90',
            'longitude.required' => 'Укажите долготу адреса',
            'longitude.numeric' => 'Долгота должна быть числом',
            'longitude.between' => 'Долгота должна быть в диапазоне от -180 до 180',
        ];
    }

    /**
     * Подготовленные данные для обновления адреса.
     *
     * @return array<string, mixed>
     */
    public function addressData(): array
    {
        $data = [];

        if ($this->has('title')) {
            $data['title'] = $this->validated('title');
        }
        if ($this->has('country')) {
            $data['country'] = $this->validated('country');
        }
        if ($this->has('city')) {
            $data['city'] = $this->validated('city');
        }
        if ($this->has('street')) {
            $data['street'] = $this->validated('street');
        }
        if ($this->has('houseNumber')) {
            $data['house_number'] = $this->validated('houseNumber');
        }
        if ($this->has('entrance')) {
            $data['entrance'] = $this->validated('entrance');
        }
        if ($this->has('floor')) {
            $data['floor'] = $this->validated('floor');
        }
        if ($this->has('apartment')) {
            $data['apartment'] = $this->validated('apartment');
        }
        if ($this->has('intercom')) {
            $data['intercom'] = $this->validated('intercom');
        }
        if ($this->has('comment')) {
            $data['comment'] = $this->validated('comment');
        }
        if ($this->has('latitude')) {
            $data['latitude'] = $this->validated('latitude');
        }
        if ($this->has('longitude')) {
            $data['longitude'] = $this->validated('longitude');
        }
        if ($this->has('isObject')) {
            $data['is_object'] = (bool) $this->validated('isObject', false);

            $objectData = $this->validated('objectData');
            if ($data['is_object'] && is_array($objectData)) {
                $data['object_data'] = \App\DTOs\ObjectAddressData::fromArray([
                    'name' => $objectData['name'] ?? null,
                    'contact_name' => $objectData['contactName'] ?? null,
                    'contact_phone' => $objectData['contactPhone'] ?? null,
                    'company' => $objectData['company'] ?? null,
                    'lift_size' => $objectData['liftSize'] ?? null,
                    'has_lift' => $objectData['hasLift'] ?? null,
                    'needs_pass' => $objectData['needsPass'] ?? null,
                    'can_oversized' => $objectData['canOversized'] ?? null,
                    'working_hours_from' => $objectData['workingHoursFrom'] ?? null,
                    'working_hours_to' => $objectData['workingHoursTo'] ?? null,
                    'is_24_7' => $objectData['is247'] ?? false,
                    'working_days' => $objectData['workingDays'] ?? [],
                    'is_daily' => $objectData['isDaily'] ?? false,
                ]);
            } else {
                $data['object_data'] = null;
            }
        }

        return $data;
    }
}
