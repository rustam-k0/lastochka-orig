<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SearchStoreFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated snake_case query-параметры как алиасы camelCase —
     * так же, как {@see SearchProductsInStoreRequest}: экран фильтра шлёт те же
     * параметры, что и выдача поиска.
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
            'onlyDiscounted' => ['sometimes', 'boolean'],
            'sugarFree' => ['sometimes', 'boolean'],
            'brandId' => ['sometimes', 'integer', 'min:1'],
            'categoryId' => ['sometimes', 'integer', 'min:1'],
            'priceMin' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priceMax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter' => ['sometimes', 'array'],
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
}
