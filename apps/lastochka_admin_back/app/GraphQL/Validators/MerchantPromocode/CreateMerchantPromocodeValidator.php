<?php

namespace App\GraphQL\Validators\MerchantPromocode;

use App\Models\MerchantPromocode;
use App\Rules\UniquePromocodeForShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateMerchantPromocodeValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $user = auth()->user();
        $shopId = $this->getShopId();
        $scope = $this->arg('input.scope') ?? MerchantPromocode::SCOPE_STORE;

        // Platform-wide → shop_id nullable, иначе required (кроме селлеров,
        // где shop_id подставляется автоматически).
        if ($scope === MerchantPromocode::SCOPE_PLATFORM) {
            $shopIdRules = ['nullable'];
        } else {
            $shopIdRules = $user->isShopScope()
                ? ['nullable', Rule::exists('shops', 'id')]
                : ['required', Rule::exists('shops', 'id')];
        }

        $discountType = $this->arg('input.discount_type');
        $bindingType = $this->arg('input.binding_type');

        $discountValueRules = ['required', 'integer', 'min:1'];
        if ($discountType === 'percent') {
            $discountValueRules[] = 'max:100';
        }

        $productIdsRules = $bindingType === 'products'
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];

        $categoryIdsRules = $bindingType === 'categories'
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];

        $maxUses = $this->arg('input.max_uses');
        $maxUsesPerClientRules = ['nullable', 'integer', 'min:1'];
        if ($maxUses !== null) {
            $maxUsesPerClientRules[] = 'lte:input.max_uses';
        }

        return [
            'input.scope' => ['nullable', Rule::in([MerchantPromocode::SCOPE_STORE, MerchantPromocode::SCOPE_PLATFORM])],
            'input.shop_id' => $shopIdRules,
            'input.title' => ['required', 'string', 'max:255'],
            'input.code' => [
                'required',
                'string',
                'max:50',
                new UniquePromocodeForShop($shopId),
            ],
            'input.discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'input.discount_value' => $discountValueRules,
            'input.valid_from' => ['required', 'date'],
            'input.valid_to' => ['required', 'date', 'after_or_equal:input.valid_from'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.max_uses' => ['nullable', 'integer', 'min:1'],
            'input.max_uses_per_client' => $maxUsesPerClientRules,
            'input.binding_type' => ['required', Rule::in(['all', 'products', 'categories'])],
            'input.product_ids' => $productIdsRules,
            'input.product_ids.*' => ['integer', 'exists:products,id'],
            'input.category_ids' => $categoryIdsRules,
            'input.category_ids.*' => ['integer', 'exists:categories,id'],
        ];
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
            'input.scope.in' => 'Scope должен быть store или platform',
            'input.shop_id.required' => 'ID магазина обязателен',
            'input.shop_id.exists' => 'Указанный магазин не найден',
            'input.title.required' => 'Название промокода обязательно',
            'input.title.max' => 'Название промокода не должно превышать 255 символов',
            'input.code.required' => 'Код промокода обязателен',
            'input.code.max' => 'Код промокода не должен превышать 50 символов',
            'input.discount_type.required' => 'Тип скидки обязателен',
            'input.discount_type.in' => 'Тип скидки должен быть percent или fixed',
            'input.discount_value.required' => 'Значение скидки обязательно',
            'input.discount_value.integer' => 'Значение скидки должно быть целым числом',
            'input.discount_value.min' => 'Значение скидки должно быть не менее 1',
            'input.discount_value.max' => 'Процент скидки должен быть не более 100',
            'input.valid_from.required' => 'Дата начала действия обязательна',
            'input.valid_from.date' => 'Неверный формат даты начала',
            'input.valid_to.required' => 'Дата окончания действия обязательна',
            'input.valid_to.date' => 'Неверный формат даты окончания',
            'input.valid_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'input.max_uses.integer' => 'Максимальное количество использований должно быть целым числом',
            'input.max_uses.min' => 'Максимальное количество использований должно быть не менее 1',
            'input.max_uses_per_client.integer' => 'Лимит на клиента должен быть целым числом',
            'input.max_uses_per_client.min' => 'Лимит на клиента должен быть не менее 1',
            'input.max_uses_per_client.lte' => 'Лимит на клиента не может превышать общий лимит',
            'input.binding_type.required' => 'Тип привязки обязателен',
            'input.binding_type.in' => 'Тип привязки должен быть all, products или categories',
            'input.product_ids.required' => 'Необходимо выбрать хотя бы один товар',
            'input.product_ids.min' => 'Необходимо выбрать хотя бы один товар',
            'input.product_ids.*.exists' => 'Указанный товар не найден',
            'input.category_ids.required' => 'Необходимо выбрать хотя бы одну категорию',
            'input.category_ids.min' => 'Необходимо выбрать хотя бы одну категорию',
            'input.category_ids.*.exists' => 'Указанная категория не найдена',
        ];
    }
}
