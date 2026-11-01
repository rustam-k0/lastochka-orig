<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SearchProductsInStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated snake_case query-параметры как алиасы camelCase для обратной совместимости.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'only_discounted' => 'onlyDiscounted',
            'sugar_free' => 'sugarFree',
            'brand_id' => 'brandId',
            'category_id' => 'categoryId',
        ] as $snake => $camel) {
            if ($this->has($snake) && ! $this->has($camel)) {
                $this->merge([$camel => $this->input($snake)]);
            }
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'onlyDiscounted' => ['sometimes', 'boolean'],
            'sugarFree' => ['sometimes', 'boolean'],
            'brandId' => ['sometimes', 'integer', 'min:1'],
            'categoryId' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string'],
            'priceMin' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priceMax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter' => ['sometimes', 'array'],
            'countOnly' => ['sometimes', 'in:0,1,true,false'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'query.required' => 'Поисковый запрос обязателен.',
            'query.min' => 'Минимальная длина запроса — 2 символа.',
            'query.max' => 'Максимальная длина запроса — 255 символов.',
        ];
    }

    public function getQuery(): string
    {
        return $this->string('query')->trim()->toString();
    }

    public function getPage(): int
    {
        return $this->integer('page', 1);
    }

    public function getPerPage(): int
    {
        return min($this->integer('perPage', 20), 100);
    }

    public function getOnlyDiscounted(): ?bool
    {
        return $this->has('onlyDiscounted') ? $this->boolean('onlyDiscounted') : null;
    }

    public function getSugarFree(): ?bool
    {
        return $this->has('sugarFree') ? $this->boolean('sugarFree') : null;
    }

    public function getBrandId(): ?int
    {
        return $this->has('brandId') ? $this->integer('brandId') : null;
    }

    public function getCategoryId(): ?int
    {
        return $this->has('categoryId') ? $this->integer('categoryId') : null;
    }

    public function getSort(): ?string
    {
        return $this->has('sort') ? $this->string('sort')->toString() : null;
    }

    public function isCountOnly(): bool
    {
        return $this->boolean('countOnly');
    }
}
