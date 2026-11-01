<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SearchSuggestionsRequest extends FormRequest
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
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'structured' => ['sometimes', 'in:0,1,true,false'],
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
            'query.min' => 'Минимальная длина запроса — 1 символ.',
            'query.max' => 'Максимальная длина запроса — 255 символов.',
        ];
    }

    public function getQuery(): string
    {
        return $this->string('query')->trim()->toString();
    }

    public function getLimit(): int
    {
        return min($this->integer('limit', 10), 20);
    }

    public function isStructured(): bool
    {
        return $this->boolean('structured', false);
    }

    /**
     * Магазин, выбранный на фронте. Null — доступные магазины определяются
     * активным адресом пользователя, как раньше.
     */
    public function getStoreId(): ?int
    {
        return $this->has('storeId') ? $this->integer('storeId') : null;
    }
}
