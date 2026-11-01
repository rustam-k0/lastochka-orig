<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SearchRecommendationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'storeId' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function getLimit(): int
    {
        return min($this->integer('limit', 20), 50);
    }

    /**
     * Магазин-источник цены и остатка (сетевой каталог). Null — контекст
     * определяется активным адресом пользователя, как раньше.
     */
    public function getStoreId(): ?int
    {
        return $this->has('storeId') ? $this->integer('storeId') : null;
    }
}
