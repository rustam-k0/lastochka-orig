<?php

namespace App\GraphQL\Validators\MerchantSale;

use App\Rules\ProductBelongsToShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateMerchantSaleValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $shopId = $this->arg('input.shop_id') ?? $this->arg('shop_id');
        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $rules = [
            'input.title' => ['required', 'string', 'max:255'],
            'input.description' => ['nullable', 'string', 'max:2000'],
            // Ровно один источник целей: shop_id | shop_ids | all_shops (MID-237)
            'input.shop_id' => ['nullable', 'required_without_all:input.shop_ids,input.all_shops', Rule::exists('shops', 'id')],
            'input.shop_ids' => ['nullable', 'array', 'min:1', 'max:' . \App\Services\MerchantMultiShop\MultiShopCreateService::MAX_EXPLICIT_SHOPS],
            'input.shop_ids.*' => ['integer', Rule::exists('shops', 'id')],
            'input.all_shops' => ['nullable', 'boolean'],
            'input.discount_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'input.starts_at' => ['required', 'date'],
            'input.ends_at' => ['required', 'date', 'after:input.starts_at'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.product_ids' => ['required', 'array', 'min:1'],
            'input.product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        // В сетевом каталоге карточка товара глобальна (принадлежит якорю) —
        // принадлежность товара конкретному магазину не проверяем, достаточно
        // существования. Вне сети сохраняем прежнее правило: товары должны
        // принадлежать каждому целевому магазину.
        if (!$networkMode) {
            $targetShopIds = array_filter(array_merge(
                $shopId ? [(int) $shopId] : [],
                array_map('intval', $this->arg('input.shop_ids') ?? []),
            ));

            foreach ($targetShopIds as $targetShopId) {
                $rules['input.product_ids'][] = new ProductBelongsToShop($targetShopId);
            }
        }

        return $rules;
    }

    /**
     * Return custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input.title.required' => 'Название акции обязательно',
            'input.title.max' => 'Название акции не должно превышать 255 символов',
            'input.description.max' => 'Описание акции не должно превышать 2000 символов',
            'input.shop_id.required' => 'ID магазина обязателен',
            'input.shop_id.exists' => 'Указанный магазин не найден',
            'input.discount_percent.required' => 'Процент скидки обязателен',
            'input.discount_percent.min' => 'Процент скидки должен быть не менее 1%',
            'input.discount_percent.max' => 'Процент скидки не может превышать 100%',
            'input.starts_at.required' => 'Дата начала акции обязательна',
            'input.ends_at.required' => 'Дата окончания акции обязательна',
            'input.ends_at.after' => 'Дата окончания должна быть позже даты начала',
            'input.product_ids.required' => 'Необходимо выбрать хотя бы один товар',
            'input.product_ids.min' => 'Необходимо выбрать хотя бы один товар',
        ];
    }
}
