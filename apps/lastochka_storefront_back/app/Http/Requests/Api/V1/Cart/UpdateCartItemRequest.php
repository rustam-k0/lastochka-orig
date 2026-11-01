<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use App\Rules\CartSupplementsRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated `values` как алиас `valueIds` в каждой добавке.
     * Канонический ключ — `valueIds` (по ТЗ).
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
             * Новое количество товара (от 1 до 99999).
             *
             * @example 3
             */
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
            /**
             * Добавки к товару (опционально, перезаписывает текущие).
             *
             * @example [{"supplementId": 1, "valueIds": [10]}]
             */
            // Магазин покупки берётся у самой позиции: в сетевом каталоге
            // остаток товарной добавки живёт в оффере этого магазина.
            'supplements' => ['sometimes', 'array', new CartSupplementsRule(
                (int) ($this->route('cartItem')?->product_id ?? 0),
                (int) ($this->route('cartItem')?->store_id ?? 0),
            )],
            'supplements.*.supplementId' => ['required', 'integer', 'exists:supplements,id'],
            'supplements.*.valueIds' => ['required', 'array', 'min:1'],
            'supplements.*.valueIds.*' => ['integer', 'exists:supplement_values,id'],
            /**
             * Желаемая дата доставки. Учитывается не во всех режимах витрины.
             *
             * @example "2026-04-15"
             */
            'deliveryDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
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
        ];
    }
}
