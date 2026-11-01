<?php

namespace App\GraphQL\Validators\MerchantBanner;

use App\Models\MerchantBanner;
use App\Models\Product;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class MerchantBannerUpdateValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $bannerId = $this->arg('id');
        $banner = MerchantBanner::find($bannerId);
        $shopId = $banner?->shop_id;

        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $linkType = $this->arg('input.link_type') ?? $banner?->link_type ?? MerchantBanner::LINK_TYPE_NONE;

        $rules = [
            'input.title' => ['nullable', 'string', 'max:255'],
            'input.link_type' => ['nullable', Rule::in(['none', 'product', 'category'])],
            'input.display_category_id' => ['nullable', Rule::exists('categories', 'id')],
            'input.is_active' => ['nullable', 'boolean'],
            'input.position' => ['nullable', 'integer', 'min:0'],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        if ($linkType === MerchantBanner::LINK_TYPE_PRODUCT) {
            $productIdValue = $this->arg('input.product_id');

            if ($productIdValue !== null || $banner?->link_type !== MerchantBanner::LINK_TYPE_PRODUCT) {
                $rules['input.product_id'] = [
                    'required',
                    Rule::exists('products', 'id'),
                    // В сетевом каталоге карточка глобальна (принадлежит якорю),
                    // принадлежность магазину баннера не проверяем (MID-317)
                    function ($attribute, $value, $fail) use ($shopId, $networkMode) {
                        if (!$networkMode && $shopId && $value) {
                            $product = Product::find($value);
                            if ($product && $product->shop_id != $shopId) {
                                $fail('Товар не принадлежит магазину баннера.');
                            }
                        }
                    },
                ];
            }
            $rules['input.category_id'] = ['nullable'];
        } elseif ($linkType === MerchantBanner::LINK_TYPE_CATEGORY) {
            $categoryIdValue = $this->arg('input.category_id');

            if ($categoryIdValue !== null || $banner?->link_type !== MerchantBanner::LINK_TYPE_CATEGORY) {
                $rules['input.category_id'] = ['required', Rule::exists('categories', 'id')];
            }
            $rules['input.product_id'] = ['nullable'];
        } else {
            $rules['input.product_id'] = ['nullable'];
            $rules['input.category_id'] = ['nullable'];
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
            'input.title.max' => 'Название баннера не должно превышать 255 символов',
            'input.product_id.required' => 'ID товара обязателен при link_type = product',
            'input.product_id.exists' => 'Указанный товар не найден',
            'input.category_id.required' => 'ID категории обязателен при link_type = category',
            'input.category_id.exists' => 'Указанная категория не найдена',
            'input.display_category_id.exists' => 'Указанная категория для показа не найдена',
            'input.position.min' => 'Позиция не может быть отрицательной',
        ];
    }
}
