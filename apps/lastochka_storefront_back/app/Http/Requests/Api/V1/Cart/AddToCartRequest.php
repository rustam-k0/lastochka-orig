<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use App\Rules\CartSupplementsRule;
use App\Services\Cart\CartStoreOffers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated `values` как алиас `valueIds` в каждой добавке.
     * Канонический ключ — `valueIds` (по ТЗ). Совместимость нужна для
     * установленных версий мобилки, которые сериализуют `values`.
     */
    protected function prepareForValidation(): void
    {
        $supplements = $this->input('supplements');
        if (! is_array($supplements)) {
            return;
        }

        $normalized = [];
        foreach ($supplements as $item) {
            if (is_array($item) && ! array_key_exists('valueIds', $item) && array_key_exists('values', $item)) {
                $item['valueIds'] = $item['values'];
            }
            $normalized[] = $item;
        }

        $this->merge(['supplements' => $normalized]);
    }

    public function rules(): array
    {
        return [
            /**
             * ID товара для добавления в корзину.
             *
             * @example 1
             */
            // 0 вместо false: DatabaseRule::formatWheres кастит bool в строку,
            // (string) false = '' → на SQLite сравнение is_configurable = '' не матчится.
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('is_configurable', 0)],
            /**
             * Количество товара (от 1 до 99999).
             *
             * @example 2
             */
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            /**
             * Добавки к товару.
             *
             * @example [{"supplementId": 1, "valueIds": [10]}]
             */
            // Магазин резолвим только когда добавки реально переданы: иначе
            // каждый запрос без добавок дёргал бы резолв адреса впустую.
            'supplements' => ['sometimes', 'array', new CartSupplementsRule(
                (int) $this->input('productId', 0),
                $this->has('supplements') ? $this->purchaseStoreId() : 0,
            )],
            'supplements.*.supplementId' => ['required', 'integer', 'exists:supplements,id'],
            'supplements.*.valueIds' => ['sometimes', 'array', 'min:1'],
            'supplements.*.valueIds.*' => ['integer', 'exists:supplement_values,id'],
            'supplements.*.valueQuantities' => ['sometimes', 'array', 'min:1'],
            'supplements.*.valueQuantities.*.valueId' => ['required', 'integer', 'exists:supplement_values,id'],
            'supplements.*.valueQuantities.*.quantity' => ['required', 'integer', 'min:1'],
            /**
             * Желаемая дата доставки. Учитывается не во всех режимах витрины.
             *
             * @example "2026-04-15"
             */
            'deliveryDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            /**
             * Магазин покупки (только сетевой каталог): из него берутся цена и
             * остаток позиции. Если не передан — первый доступный магазин
             * активного адреса. В обычном режиме игнорируется.
             *
             * @example 3
             */
            // 1 вместо true: DatabaseRule::formatWheres кастит bool в строку,
            // (string) true = '1' работает, но на SQLite сравнение с '' у false
            // ломается — держим числовой литерал, как в правиле productId.
            'storeId' => ['sometimes', 'nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', 1)],
        ];
    }

    /**
     * Магазин покупки для проверки товарных добавок: явный storeId запроса,
     * иначе первый доступный магазин активного адреса. 0 — легаси-режим либо
     * магазин не определился (тогда добавление отклонит сам Action).
     */
    private function purchaseStoreId(): int
    {
        $user = $this->user();

        if ($user === null) {
            return 0;
        }

        $requested = $this->integer('storeId');

        return app(CartStoreOffers::class)->resolvePurchaseStoreId(
            $requested > 0 ? $requested : null,
            $user
        );
    }

    public function messages(): array
    {
        return [
            'productId.required' => 'Укажите товар',
            'productId.exists' => 'Товар не найден или является вариативным (выберите конкретный вариант)',
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не меньше 1',
            'quantity.max' => 'Максимальное количество — 99999',
            'supplements.*.supplementId.required' => 'Укажите ID добавки',
            'supplements.*.supplementId.exists' => 'Добавка не найдена',
            'supplements.*.valueIds.required' => 'Выберите значения добавки',
            'supplements.*.valueIds.min' => 'Выберите хотя бы одно значение добавки',
            'supplements.*.valueIds.*.exists' => 'Значение добавки не найдено',
            'deliveryDate.date_format' => 'Дата доставки должна быть в формате Y-m-d',
            'deliveryDate.after_or_equal' => 'Дата доставки не может быть в прошлом',
            'storeId.exists' => 'Магазин не найден',
        ];
    }
}
