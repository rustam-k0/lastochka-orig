<?php

namespace App\GraphQL\Validators\MerchantProductGroup;

use App\Enums\ProductGroupFillType;
use App\Rules\ProductBelongsToShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateMerchantProductGroupValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $user = auth()->user();
        $shopId = $this->arg('input.shop_id') ?? $this->arg('shop_id');

        // Для селлера shop_id подставляется автоматически, для админа
        // обязателен ровно один источник целей: shop_id | shop_ids | all_shops
        // (MID-229)
        $shopIdRules = $user->isShopScope()
            ? ['nullable', Rule::exists('shops', 'id')]
            : ['nullable', 'required_without_all:input.shop_ids,input.all_shops', Rule::exists('shops', 'id')];

        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $rules = [
            'input.shop_id' => $shopIdRules,
            'input.shop_ids' => ['nullable', 'array', 'min:1', 'max:' . \App\Services\MerchantMultiShop\MultiShopCreateService::MAX_EXPLICIT_SHOPS],
            'input.shop_ids.*' => ['integer', Rule::exists('shops', 'id')],
            'input.all_shops' => ['nullable', 'boolean'],
            'input.title' => ['required', 'string', 'max:255'],
            'input.meta' => ['nullable', 'array'],
            'input.show_on_main' => ['nullable', 'boolean'],
            'input.show_on_merchant_page' => ['nullable', 'boolean'],
            'input.show_in_merchant_catalog' => ['nullable', 'boolean'],
            'input.show_on_search' => ['nullable', 'boolean'],
            'input.sort_order' => ['nullable', 'integer', 'min:0'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.is_recommended' => ['nullable', 'boolean'],
            'input.is_cart_page' => ['nullable', 'boolean'],
            'input.is_complex_solution' => ['nullable', 'boolean'],
            'input.fill_type' => ['nullable', 'string', Rule::in(ProductGroupFillType::values())],
            'input.product_ids' => ['nullable', 'array'],
            'input.product_ids.*' => ['integer', Rule::exists('products', 'id')],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        // В сетевом каталоге карточка товара глобальна (принадлежит якорю) —
        // принадлежность товара магазину не проверяем (MID-229). Вне сети
        // товары должны принадлежать каждому целевому магазину. У автоматической
        // группы (fill_type не manual) product_ids игнорируются — не проверяем.
        $isManualFill = ProductGroupFillType::isManualValue($this->arg('input.fill_type') ?? ProductGroupFillType::MANUAL->value);

        if (!$networkMode && $isManualFill && !empty($this->arg('input.product_ids'))) {
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
            'input.shop_id.required' => 'ID магазина обязателен',
            'input.shop_id.exists' => 'Указанный магазин не найден',
            'input.title.required' => 'Название группы обязательно',
            'input.title.max' => 'Название группы не должно превышать 255 символов',
            'input.sort_order.min' => 'Порядок сортировки не может быть отрицательным',
            'input.fill_type.in' => 'Неизвестный способ наполнения группы',
        ];
    }
}
