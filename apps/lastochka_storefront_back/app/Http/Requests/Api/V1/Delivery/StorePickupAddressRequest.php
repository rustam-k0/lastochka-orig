<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;

final class StorePickupAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Провайдер самовывоза.
             *
             * @example cdek
             */
            'provider' => ['required', 'string', 'in:cdek,russian_post'],
            /**
             * Внешний идентификатор точки выдачи (код ПВЗ СДЭК или индекс ОПС).
             *
             * @example MKHCH19
             */
            'externalId' => ['required', 'string', 'max:64'],
            /**
             * Название точки выдачи.
             *
             * @example ПВЗ на Гамидова
             */
            'title' => ['nullable', 'string', 'max:200'],
            /**
             * Страна расположения.
             *
             * @example Россия
             */
            'country' => ['required', 'string', 'max:100'],
            /**
             * Город расположения.
             *
             * @example Махачкала
             */
            'city' => ['required', 'string', 'max:100'],
            /**
             * Почтовый индекс.
             *
             * @example 367000
             */
            'postalCode' => ['nullable', 'string', 'max:20'],
            /**
             * Адрес (улица и дом).
             *
             * @example ул. Гамидова, 42
             */
            'street' => ['required', 'string', 'max:300'],
            /**
             * Широта.
             *
             * @example 42.98
             */
            'lat' => ['required', 'numeric', 'between:-90,90'],
            /**
             * Долгота.
             *
             * @example 47.50
             */
            'lng' => ['required', 'numeric', 'between:-180,180'],
            /**
             * Телефон точки выдачи.
             *
             * @example +79641234567
             */
            'phone' => ['nullable', 'string', 'max:30'],
            /**
             * Сырые данные точки выдачи от провайдера.
             */
            'raw' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Укажите провайдера доставки',
            'provider.in' => 'Провайдер должен быть cdek или russian_post',
            'externalId.required' => 'Укажите код точки выдачи',
            'externalId.max' => 'Код точки выдачи слишком длинный',
            'country.required' => 'Укажите страну',
            'city.required' => 'Укажите город',
            'street.required' => 'Укажите адрес',
            'lat.required' => 'Укажите широту',
            'lat.numeric' => 'Широта должна быть числом',
            'lat.between' => 'Широта должна быть в диапазоне от -90 до 90',
            'lng.required' => 'Укажите долготу',
            'lng.numeric' => 'Долгота должна быть числом',
            'lng.between' => 'Долгота должна быть в диапазоне от -180 до 180',
        ];
    }
}
