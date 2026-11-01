<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ProductGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GetProductGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Алиас deprecated snake_case → camelCase для обратной совместимости.
        if ($this->has('is_cart_page') && ! $this->has('isCartPage')) {
            $this->merge(['isCartPage' => $this->input('is_cart_page')]);
        }

        // Конвертация строковых boolean в настоящие для валидации
        if ($this->has('withProducts')) {
            $this->merge([
                'withProducts' => filter_var($this->input('withProducts'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('isCartPage')) {
            $this->merge([
                'isCartPage' => filter_var($this->input('isCartPage'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            /**
             * Место размещения (home — главная, search — страница поиска).
             *
             * Обязателен, если не передан ни isCartPage, ни filterValues
             * (фасетный каталог комплексных решений — см. 05-complex-solutions-catalog.md §5).
             *
             * @example home
             */
            'placement' => ['required_without_all:isCartPage,filterValues', 'string', Rule::in(['home', 'search'])],
            /**
             * Загрузить товары в группах.
             *
             * @example true
             */
            'withProducts' => ['sometimes', 'boolean'],
            /**
             * Лимит товаров в каждой группе (max: 20).
             *
             * @example 4
             */
            'productsLimit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            /**
             * Фильтр товарных групп для корзины.
             *
             * @example true
             */
            'isCartPage' => ['sometimes', 'boolean'],
            /**
             * AND-фильтрация по id значений product_group_filter_values
             * для фасетной навигации каталога комплексных решений.
             * CSV из положительных целых чисел.
             *
             * @example 1,3,5
             */
            'filterValues' => ['sometimes', 'string', 'regex:/^[1-9]\d*(,[1-9]\d*)*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'placement.required_without_all' => 'Параметр placement обязателен, если не указан ни isCartPage, ни filterValues',
            'placement.in' => 'Допустимые значения: home, search',
            'filterValues.regex' => 'filterValues должен быть CSV из положительных целых (например: 1,3,5)',
            'productsLimit.max' => 'Максимум 20 товаров в группе',
            'productsLimit.min' => 'Минимум 1 товар в группе',
        ];
    }

    public function placement(): ?string
    {
        return $this->validated('placement');
    }

    public function withProducts(): bool
    {
        return $this->boolean('withProducts', false);
    }

    public function productsLimit(): int
    {
        return $this->integer('productsLimit', 4);
    }

    public function isCartPage(): ?bool
    {
        return $this->has('isCartPage') ? $this->boolean('isCartPage') : null;
    }
}
