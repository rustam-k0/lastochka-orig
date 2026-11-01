<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Address;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CreateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
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

        // is247=true → обнулить часы (не валидируются и в storage идут как null)
        if ((bool) ($objectData['is247'] ?? false)) {
            $objectData['workingHoursFrom'] = null;
            $objectData['workingHoursTo'] = null;
        }

        // isDaily=true → обнулить дни
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
             * @example Дом
             */
            'title' => ['nullable', 'string', 'max:50'],
            /**
             * Страна.
             *
             * @example Россия
             */
            'country' => ['required', 'string', 'max:100'],
            /**
             * Город.
             *
             * @example Москва
             */
            'city' => ['required', 'string', 'max:100'],
            /**
             * Улица.
             *
             * @example Ленина
             */
            'street' => ['nullable', 'string', 'max:200'],
            /**
             * Номер дома.
             *
             * @example 10
             */
            'houseNumber' => ['nullable', 'string', 'max:20'],
            /**
             * Подъезд.
             *
             * @example 2
             */
            'entrance' => ['nullable', 'string', 'max:10'],
            /**
             * Этаж.
             *
             * @example 5
             */
            'floor' => ['nullable', 'string', 'max:10'],
            /**
             * Номер квартиры.
             *
             * @example 42
             */
            'apartment' => ['nullable', 'string', 'max:20'],
            /**
             * Код домофона.
             *
             * @example 42
             */
            'intercom' => ['nullable', 'string', 'max:20'],
            /**
             * Комментарий для курьера.
             *
             * @example Домофон не работает, позвоните
             */
            'comment' => ['nullable', 'string', 'max:500'],
            /**
             * Широта (от -90 до 90). Обязательна вместе с долготой.
             *
             * @example 55.7558
             */
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            /**
             * Долгота (от -180 до 180). Обязательна вместе с широтой.
             *
             * @example 37.6173
             */
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            /**
             * Является ли адрес коммерческим/строительным объектом (вкл. блок objectData).
             * Игнорируется если модуль `addresses.objects` выключен в админке.
             *
             * @example false
             */
            'isObject' => ['nullable', 'boolean'],
            /**
             * Дополнительные данные объекта: контакты, лифт, доступ, время приёма, дни работы. Обязательно если isObject=true.
             */
            'objectData' => [
                \Illuminate\Validation\Rule::requiredIf(fn () => (bool) $this->input('isObject')),
                'nullable',
                'array',
            ],
            /**
             * Название объекта (отличает в списке "Мои адреса"). Обязательно при isObject=true.
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
             * ФИО контактного лица на объекте (например прораб, охранник).
             *
             * @example Иванов Иван Иванович
             */
            'objectData.contactName' => ['nullable', 'string', 'max:200'],
            /**
             * Телефон контактного лица. Формат: 10-15 цифр с опциональным +.
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
             * Размер лифта (свободный текст, например ширина × глубина в метрах).
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
             * Нужен ли пропуск для входа курьеру. null = неизвестно.
             *
             * @example false
             */
            'objectData.needsPass' => ['nullable', 'boolean'],
            /**
             * Возможен ли проезд крупногабаритного груза. null = неизвестно.
             *
             * @example true
             */
            'objectData.canOversized' => ['nullable', 'boolean'],
            /**
             * Время начала приёма доставок (HH:mm). Игнорируется если is247=true.
             *
             * @example 10:00
             */
            'objectData.workingHoursFrom' => ['nullable', 'date_format:H:i'],
            /**
             * Время окончания приёма доставок (HH:mm). Должно быть позже workingHoursFrom.
             *
             * @example 20:00
             */
            'objectData.workingHoursTo' => ['nullable', 'date_format:H:i', 'after:objectData.workingHoursFrom'],
            /**
             * Круглосуточный приём (обнуляет workingHoursFrom/To на сервере).
             *
             * @example false
             */
            'objectData.is247' => ['nullable', 'boolean'],
            /**
             * Дни работы (mon|tue|wed|thu|fri|sat|sun). Игнорируется если isDaily=true.
             *
             * @example ["mon","tue","wed","thu","fri"]
             */
            'objectData.workingDays' => ['nullable', 'array'],
            'objectData.workingDays.*' => ['string', \Illuminate\Validation\Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            /**
             * Работает ежедневно (обнуляет workingDays на сервере).
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
            'street.max' => 'Название улицы слишком длинное',
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
     * Подготовленные данные для создания адреса.
     *
     * @return array<string, mixed>
     */
    public function addressData(): array
    {
        $data = [
            'title' => $this->validated('title'),
            'country' => $this->validated('country'),
            'city' => $this->validated('city'),
            'street' => $this->validated('street'),
            'house_number' => $this->validated('houseNumber'),
            'entrance' => $this->validated('entrance'),
            'floor' => $this->validated('floor'),
            'apartment' => $this->validated('apartment'),
            'intercom' => $this->validated('intercom'),
            'comment' => $this->validated('comment'),
            'latitude' => $this->validated('latitude'),
            'longitude' => $this->validated('longitude'),
            'is_object' => (bool) $this->validated('isObject', false),
        ];

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

        return $data;
    }
}
