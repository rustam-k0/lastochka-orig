<?php

namespace App\GraphQL\Validators\MerchantBanner;

use App\Models\MerchantBanner;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class MerchantBannerCreateValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $user = auth()->user();
        $linkType = $this->arg('input.link_type') ?? MerchantBanner::LINK_TYPE_NONE;
        $shopId = $this->getShopId();

        // Ровно один источник целей: shop_id | shop_ids | all_shops (MID-238)
        $shopIdRules = $user->isShopScope()
            ? ['nullable', Rule::exists('shops', 'id')]
            : ['nullable', 'required_without_all:input.shop_ids,input.all_shops', Rule::exists('shops', 'id')];

        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $rules = [
            'input.title' => ['required', 'string', 'max:255'],
            'input.link_type' => ['nullable', Rule::in(['none', 'product', 'category'])],
            'input.display_category_id' => ['nullable', Rule::exists('categories', 'id')],
            'input.shop_id' => $shopIdRules,
            'input.shop_ids' => ['nullable', 'array', 'min:1', 'max:' . \App\Services\MerchantMultiShop\MultiShopCreateService::MAX_EXPLICIT_SHOPS],
            'input.shop_ids.*' => ['integer', Rule::exists('shops', 'id')],
            'input.all_shops' => ['nullable', 'boolean'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.position' => ['nullable', 'integer', 'min:0'],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        if ($linkType === MerchantBanner::LINK_TYPE_PRODUCT) {
            $rules['input.product_id'] = [
                'required',
                Rule::exists('products', 'id'),
                // В сетевом каталоге карточка глобальна (принадлежит якорю) —
                // принадлежность магазину не проверяем (MID-238)
                function ($attribute, $value, $fail) use ($shopId, $networkMode) {
                    if (!$networkMode && $shopId && $value) {
                        $product = Product::find($value);
                        if ($product && $product->shop_id != $shopId) {
                            $fail('Товар не принадлежит указанному магазину.');
                        }
                    }
                },
            ];
            $rules['input.category_id'] = ['nullable'];
        } elseif ($linkType === MerchantBanner::LINK_TYPE_CATEGORY) {
            $rules['input.category_id'] = ['required', Rule::exists('categories', 'id')];
            $rules['input.product_id'] = ['nullable'];
        } else {
            $rules['input.product_id'] = ['nullable'];
            $rules['input.category_id'] = ['nullable'];
        }

        return $rules;
    }

    /**
     * Получение shop_id с учётом роли пользователя
     */
    protected function getShopId(): ?int
    {
        $user = auth()->user();

        if ($user->isShopScope()) {
            $userShop = $user->shops()->first();
            return $userShop?->shop_id;
        }

        return $this->arg('input.shop_id');
    }

    /**
     * Return custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input.title.required' => 'Название баннера обязательно',
            'input.title.max' => 'Название баннера не должно превышать 255 символов',
            'input.shop_id.required' => 'ID магазина обязателен',
            'input.shop_id.exists' => 'Указанный магазин не найден',
            'input.product_id.required' => 'ID товара обязателен при link_type = product',
            'input.product_id.exists' => 'Указанный товар не найден',
            'input.category_id.required' => 'ID категории обязателен при link_type = category',
            'input.category_id.exists' => 'Указанная категория не найдена',
            'input.display_category_id.exists' => 'Указанная категория для показа не найдена',
            'input.position.min' => 'Позиция не может быть отрицательной',
        ];
    }
}
