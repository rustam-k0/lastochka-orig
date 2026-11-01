<?php

namespace App\GraphQL\Validators\Story;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class StoryUpdateValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', Rule::exists('stories', 'id')],
            'title' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'link_type' => ['nullable', Rule::in(['none', 'product', 'shop', 'external'])],
            'product_id' => ['required_if:link_type,product', 'nullable', Rule::exists('products', 'id')],
            'shop_id' => ['required_if:link_type,shop', 'nullable', Rule::exists('shops', 'id')],
            'external_url' => ['required_if:link_type,external', 'nullable', 'url', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'thumbnail_image' => ['nullable', 'array'],
            'thumbnail_image.path' => ['required_with:thumbnail_image', 'string'],
            'thumbnail_image.origin_name' => ['required_with:thumbnail_image', 'string'],
            'images' => ['nullable', 'array'],
            'images.*.path' => ['required', 'string'],
            'images.*.origin_name' => ['required', 'string'],
        ];
    }

    /**
     * Return custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.required' => 'ID истории обязателен',
            'id.exists' => 'История не найдена',
            'title.max' => 'Название истории не должно превышать 255 символов',
            'expires_at.after' => 'Дата окончания должна быть в будущем',
            'product_id.required_if' => 'ID товара обязателен при типе перехода "product"',
            'product_id.exists' => 'Указанный товар не найден',
            'shop_id.required_if' => 'ID магазина обязателен при типе перехода "shop"',
            'shop_id.exists' => 'Указанный магазин не найден',
            'external_url.required_if' => 'Внешняя ссылка обязательна при типе перехода "external"',
            'external_url.url' => 'Внешняя ссылка должна быть валидным URL',
        ];
    }
}
