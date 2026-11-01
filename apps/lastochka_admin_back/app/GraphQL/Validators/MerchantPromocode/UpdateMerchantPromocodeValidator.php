<?php

namespace App\GraphQL\Validators\MerchantPromocode;

use App\Models\MerchantPromocode;
use App\Models\Role;
use App\Rules\UniquePromocodeForShop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UpdateMerchantPromocodeValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $promocodeId = $this->arg('input.id');
        $promocode = MerchantPromocode::find($promocodeId);
        $shopId = $promocode?->shop_id;

        $discountType = $this->arg('input.discount_type') ?? $promocode?->discount_type ?? 'percent';

        $discountValueRules = ['nullable', 'integer', 'min:1'];
        if ($discountType === 'percent') {
            $discountValueRules[] = 'max:100';
        }

        $input = $this->args->toArray()['input'] ?? [];
        $maxUsesProvided = array_key_exists('max_uses', $input);
        $maxUsesPerClientRules = ['nullable', 'integer', 'min:1'];

        // При update max_uses_per_client ограничиваем по новому значению max_uses (если передан)
        // или по текущему значению модели. Важно отличать «ключ не передан» от «передан null»:
        // null = явное снятие общего лимита, тогда per-client кэп не применяем.
        $effectiveMaxUses = $maxUsesProvided ? ($input['max_uses'] ?? null) : $promocode?->max_uses;
        if ($effectiveMaxUses !== null) {
            $maxUsesPerClientRules[] = "max:{$effectiveMaxUses}";
        }

        $rules = [
            'input.id' => ['required', 'integer', 'exists:merchant_promocodes,id'],
            'input.scope' => ['nullable', Rule::in([MerchantPromocode::SCOPE_STORE, MerchantPromocode::SCOPE_PLATFORM])],
            'input.shop_id' => ['nullable', 'integer', Rule::exists('shops', 'id')],
            'input.title' => ['nullable', 'string', 'max:255'],
            'input.discount_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'input.discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'input.discount_value' => $discountValueRules,
            'input.valid_from' => ['nullable', 'date'],
            'input.valid_to' => ['nullable', 'date'],
            'input.is_active' => ['nullable', 'boolean'],
            'input.max_uses' => ['nullable', 'integer', 'min:1'],
            'input.max_uses_per_client' => $maxUsesPerClientRules,
            'input.binding_type' => ['nullable', Rule::in(['all', 'products', 'categories'])],
            'input.product_ids' => ['nullable', 'array'],
            'input.product_ids.*' => ['integer', 'exists:products,id'],
            'input.category_ids' => ['nullable', 'array'],
            'input.category_ids.*' => ['integer', 'exists:categories,id'],
        ];

        if ($this->arg('input.code') !== null) {
            $rules['input.code'] = [
                'string',
                'max:50',
                new UniquePromocodeForShop($shopId, $promocodeId),
            ];
        }

        $validFrom = $this->arg('input.valid_from') ?? $promocode?->valid_from?->format('Y-m-d');
        $validTo = $this->arg('input.valid_to');

        if ($validTo !== null && $validFrom !== null) {
            $rules['input.valid_to'][] = 'after_or_equal:input.valid_from';
        } elseif ($validTo !== null && $promocode) {
            $rules['input.valid_to'][] = function ($attribute, $value, $fail) use ($promocode) {
                if ($value < $promocode->valid_from->format('Y-m-d')) {
                    $fail('Дата окончания должна быть не раньше даты начала');
                }
            };
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
            'input.id.required' => 'ID промокода обязателен',
            'input.id.exists' => 'Промокод не найден',
            'input.title.max' => 'Название промокода не должно превышать 255 символов',
            'input.code.max' => 'Код промокода не должен превышать 50 символов',
            'input.discount_percent.min' => 'Процент скидки должен быть не менее 1',
            'input.discount_percent.max' => 'Процент скидки должен быть не более 100',
            'input.discount_type.in' => 'Тип скидки должен быть percent или fixed',
            'input.discount_value.integer' => 'Значение скидки должно быть целым числом',
            'input.discount_value.min' => 'Значение скидки должно быть не менее 1',
            'input.discount_value.max' => 'Процент скидки должен быть не более 100',
            'input.valid_from.date' => 'Неверный формат даты начала',
            'input.valid_to.date' => 'Неверный формат даты окончания',
            'input.valid_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала',
            'input.max_uses.integer' => 'Максимальное количество использований должно быть целым числом',
            'input.max_uses.min' => 'Максимальное количество использований должно быть не менее 1',
            'input.max_uses_per_client.integer' => 'Лимит на клиента должен быть целым числом',
            'input.max_uses_per_client.min' => 'Лимит на клиента должен быть не менее 1',
            'input.max_uses_per_client.max' => 'Лимит на клиента не может превышать общий лимит',
            'input.binding_type.in' => 'Тип привязки должен быть all, products или categories',
            'input.product_ids.*.exists' => 'Указанный товар не найден',
            'input.category_ids.*.exists' => 'Указанная категория не найдена',
        ];
    }
}
