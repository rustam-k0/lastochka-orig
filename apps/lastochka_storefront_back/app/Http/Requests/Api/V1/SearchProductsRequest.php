<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SearchProductsRequest extends FormRequest
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
            'categories_limit' => 'categoriesLimit',
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'productsPerStore' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'onlyDiscounted' => ['sometimes', 'boolean'],
            'sugarFree' => ['sometimes', 'boolean'],
            'brandId' => ['sometimes', 'integer', 'min:1'],
            'categoryId' => ['sometimes', 'integer', 'min:1'],
            'categoriesLimit' => ['sometimes', 'integer', 'min:0'],
            'storeId' => ['sometimes', 'integer', 'min:1'],
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
        $default = config('meilisearch.search.default_per_page', 10);
        $max = config('meilisearch.search.max_per_page', 50);

        return min($this->integer('perPage', $default), $max);
    }

    public function getProductsPerStore(): int
    {
        $default = config('meilisearch.search.default_products_per_store', 10);
        $max = config('meilisearch.search.max_products_per_store', 50);

        return min($this->integer('productsPerStore', $default), $max);
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

    /**
     * Магазин-источник цены и остатка (сетевой каталог). Null — контекст
     * определяется активным адресом пользователя, как раньше.
     */
    public function getStoreId(): ?int
    {
        return $this->has('storeId') ? $this->integer('storeId') : null;
    }

    public function getCategoriesLimit(): int
    {
        $default = config('meilisearch.search.default_categories_limit', 5);
        $max = config('meilisearch.search.max_categories_limit', 20);

        return min($this->integer('categoriesLimit', $default), $max);
    }
}
