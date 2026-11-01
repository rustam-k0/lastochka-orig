<?php

namespace App\GraphQL\Validators\MerchantSale;

use App\Models\MerchantSale;
use App\Rules\ProductBelongsToShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UpdateMerchantSaleValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $saleId = $this->arg('id');
        $sale = MerchantSale::find($saleId);
        $shopId = $sale?->shop_id;

        $newShopId = $this->arg('input.shop_id') ?? $this->arg('shop_id');
        if ($newShopId) {
            $shopId = $newShopId;
        }

        $rules = [
            'id' => ['required', 'integer', 'exists:merchant_sales,id'],
            'input.title' => ['nullable', 'string', 'max:255'],
            'input.description' => ['nullable', 'string', 'max:2000'],
            'input.shop_id' => ['nullable', 'integer', Rule::exists('shops', 'id')],
            'input.discount_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'input.starts_at' => ['nullable', 'date'],
            'input.ends_at' => ['nullable', 'date'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.image' => ['nullable', 'array'],
            'input.image.path' => ['required_with:input.image', 'string'],
            'input.image.origin_name' => ['required_with:input.image', 'string'],
        ];

        $networkMode = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        // В сетевом каталоге карточка товара глобальна (принадлежит якорю),
        // принадлежность магазину акции не проверяем, только существование (MID-317)
        $productIds = $this->arg('input.product_ids') ?? $this->arg('product_ids');
        if ($productIds !== null && $networkMode) {
            $rules['input.product_ids'] = ['array'];
            $rules['input.product_ids.*'] = ['integer', Rule::exists('products', 'id')];
        } elseif ($productIds !== null && $shopId) {
            $rules['input.product_ids'] = ['array', new ProductBelongsToShop($shopId)];
            $rules['input.product_ids.*'] = ['integer'];
        }

        $endsAt = $this->arg('input.ends_at') ?? $this->arg('ends_at');
        $startsAt = $this->arg('input.starts_at') ?? $this->arg('starts_at');

        if ($endsAt !== null && $startsAt !== null) {
            $rules['input.ends_at'][] = 'after:input.starts_at';
        } elseif ($endsAt !== null && $sale) {
            $rules['input.ends_at'][] = 'after:' . $sale->starts_at->toDateTimeString();
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
            'id.required' => 'ID акции обязателен',
            'id.exists' => 'Акция не найдена',
            'input.title.max' => 'Название акции не должно превышать 255 символов',
            'input.description.max' => 'Описание акции не должно превышать 2000 символов',
            'input.discount_percent.min' => 'Процент скидки должен быть не менее 1%',
            'input.discount_percent.max' => 'Процент скидки не может превышать 100%',
            'input.ends_at.after' => 'Дата окончания должна быть позже даты начала',
        ];
    }
}
