<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ProductGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GetStoreProductGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Конвертация строковых boolean в настоящие для валидации
        if ($this->has('withProducts')) {
            $this->merge([
                'withProducts' => filter_var($this->input('withProducts'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            /**
             * Фильтр по месту размещения: store_page, catalog или all.
             *
             * @example store_page
             */
            'placement' => ['sometimes', 'string', Rule::in(['store_page', 'catalog', 'all'])],
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
        ];
    }

    public function messages(): array
    {
        return [
            'placement.in' => 'Допустимые значения: store_page, catalog, all',
            'productsLimit.max' => 'Максимум 20 товаров в группе',
            'productsLimit.min' => 'Минимум 1 товар в группе',
        ];
    }

    public function placement(): string
    {
        return $this->validated('placement', 'all');
    }

    public function withProducts(): bool
    {
        return $this->boolean('withProducts', false);
    }

    public function productsLimit(): int
    {
        return $this->integer('productsLimit', 4);
    }
}
