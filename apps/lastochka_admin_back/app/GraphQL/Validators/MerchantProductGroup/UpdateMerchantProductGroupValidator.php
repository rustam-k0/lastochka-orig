<?php

namespace App\GraphQL\Validators\MerchantProductGroup;

use App\Models\MerchantProductGroup;
use App\Enums\ProductGroupFillType;
use App\Rules\ProductBelongsToShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UpdateMerchantProductGroupValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $groupId = $this->arg('id');
        $group = MerchantProductGroup::find($groupId);
        $shopId = $group?->shop_id;

        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $rules = [
            'input.title' => ['nullable', 'string', 'max:255'],
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
            'input.product_ids.*' => ['integer'],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        // В сетевом каталоге карточка товара глобальна (принадлежит якорю),
        // принадлежность магазину группы не проверяем, только существование (MID-317).
        // У автоматической группы (итоговый fill_type не manual) product_ids
        // игнорируются мутацией — принадлежность магазину не проверяем.
        $isManualFill = ProductGroupFillType::isManualValue(
            $this->arg('input.fill_type') ?? $group?->fill_type ?? ProductGroupFillType::MANUAL->value
        );

        if ($networkMode) {
            $rules['input.product_ids.*'][] = Rule::exists('products', 'id');
        } elseif ($shopId && $isManualFill && !empty($this->arg('input.product_ids'))) {
            $rules['input.product_ids'][] = new ProductBelongsToShop($shopId);
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
            'input.title.max' => 'Название группы не должно превышать 255 символов',
            'input.sort_order.min' => 'Порядок сортировки не может быть отрицательным',
            'input.fill_type.in' => 'Неизвестный способ наполнения группы',
        ];
    }
}
