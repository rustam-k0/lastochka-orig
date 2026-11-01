<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use App\Http\Requests\Concerns\ResolvesReturnUrl;
use App\Models\Address;
use App\Rules\ReturnUrlRule;
use App\Services\Settings\ModuleConfigService;
use App\Support\Payment\ReturnUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateClientOrderRequest extends FormRequest
{
    use ResolvesReturnUrl;

    /** Методы доставки через пункты выдачи (ПВЗ). */
    private const PICKUP_POINT_METHODS = ['cdek', 'russian_post'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $shippingMethod = (string) $this->input('shippingMethod', 'courier');
        $isPickupPoint = in_array($shippingMethod, self::PICKUP_POINT_METHODS, true);
        // Модуль «Оформление без слотов» (MID-256): для курьерской доставки
        // deliverySlots опциональны — без них по каждому магазину корзины
        // создаётся заказ «на ближайшее время». Самовывоз всегда со слотом.
        $slotsOptional = $isPickupPoint
            || ($shippingMethod !== 'pickup'
                && app(ModuleConfigService::class)->isCheckoutWithoutSlotsEnabled());

        return [
            /**
             * ID адреса доставки. Для cdek/russian_post — адрес ПВЗ
             * (additional.kind=pickup, additional.provider=shippingMethod).
             *
             * @example 5
             */
            'addressId' => ['required', 'integer', 'exists:addresses,id'],

            /**
             * Тип оплаты: card или cash.
             *
             * @example card
             */
            'paymentType' => ['required', 'string', 'in:card,cash'],

            /**
             * ID сохранённой карты для автоплатежа.
             *
             * @example 1
             */
            'paymentCardId' => ['nullable', 'integer', 'exists:payment_cards,id'],

            /**
             * Адрес, на который вернуть клиента после оплаты картой
             * (ЮKassa confirmation.return_url). Относительный путь витрины,
             * абсолютный адрес с разрешённого домена либо диплинк приложения.
             * Не передан — адрес по умолчанию из настроек.
             *
             * @example /payment/success
             */
            'returnUrl' => ['nullable', 'string', 'max:'.ReturnUrl::MAX_LENGTH, new ReturnUrlRule],

            /**
             * Метод доставки. По умолчанию `courier` для backward compatibility.
             * Допустимые: courier, pickup, cdek, russian_post.
             *
             * @example courier
             */
            'shippingMethod' => ['sometimes', 'string', 'in:courier,pickup,cdek,russian_post'],

            /**
             * Слоты доставки: массив {storeId, slotId} по числу магазинов в корзине.
             * Обязательно при shippingMethod=courier/pickup. Игнорируется при cdek/russian_post.
             * При включённом модуле «Оформление без слотов» (GET /app-settings →
             * checkoutWithoutSlots) необязательно для курьерской доставки: без
             * слотов заказ по каждому магазину корзины уходит «на ближайшее
             * время». При shippingMethod=pickup слоты обязательны всегда.
             */
            'deliverySlots' => [
                $slotsOptional ? 'sometimes' : 'required',
                'array',
                $slotsOptional ? '' : 'min:1',
            ],
            'deliverySlots.*.storeId' => [$slotsOptional ? 'sometimes' : 'required', 'integer'],
            'deliverySlots.*.slotId' => [$slotsOptional ? 'sometimes' : 'required', 'integer'],

            /**
             * Тариф(ы) доставки для cdek/russian_post.
             * Может быть строкой (один тариф на все магазины корзины) или map storeId→code.
             * Обязательно при shippingMethod=cdek/russian_post.
             *
             * @example "136"
             */
            'shippingTariffCodes' => [
                Rule::requiredIf($isPickupPoint),
                'nullable',
            ],
            'shippingTariffCodes.*' => ['string', 'max:64'],

            'promocodeIds' => ['sometimes', 'array'],
            'promocodeIds.*' => ['integer', 'exists:promocodes,id'],

            /**
             * Действие с бонусами: earn — начислить, spend — списать.
             */
            'bonusAction' => ['nullable', 'string', 'in:earn,spend'],

            /**
             * Сумма списания бонусов (для bonusAction=spend).
             * Не может превышать доступный баланс пользователя.
             *
             * @example 400
             */
            'bonusSpendAmount' => array_filter([
                'required_if:bonusAction,spend',
                'nullable',
                'integer',
                'min:1',
                $this->user() ? 'max:'.(int) $this->user()->available_bonus_balance : null,
            ]),
            'comment' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Дополнительная валидация: проверяем корректность адреса ПВЗ
     * и корректность формата shippingTariffCodes для cdek/russian_post.
     */
    public function withValidator(Validator $validator): void
    {
        $shippingMethod = (string) $this->input('shippingMethod', 'courier');

        if (! in_array($shippingMethod, self::PICKUP_POINT_METHODS, true)) {
            return;
        }

        $validator->after(function (Validator $v) use ($shippingMethod): void {
            $this->validatePickupAddress($v, $shippingMethod);
            $this->validateTariffCodes($v);
        });
    }

    private function validatePickupAddress(Validator $v, string $shippingMethod): void
    {
        $addressId = $this->input('addressId');
        if (! $addressId) {
            return;
        }

        /** @var Address|null $address */
        $address = Address::find($addressId);
        if (! $address) {
            return;
        }

        $additional = $address->additional ?? [];

        if (($additional['kind'] ?? null) !== 'pickup') {
            $v->errors()->add(
                'addressId',
                'Адрес должен быть пунктом выдачи заказов (pickup). Укажите адрес ПВЗ.',
            );

            return;
        }

        if (($additional['provider'] ?? null) !== $shippingMethod) {
            $v->errors()->add(
                'addressId',
                'Провайдер ПВЗ не совпадает с выбранным методом доставки.',
            );
        }
    }

    private function validateTariffCodes(Validator $v): void
    {
        $codes = $this->input('shippingTariffCodes');

        if ($codes === null || $codes === '') {
            $v->errors()->add('shippingTariffCodes', 'Укажите тариф доставки.');

            return;
        }

        if (is_string($codes)) {
            if (mb_strlen($codes) > 64) {
                $v->errors()->add('shippingTariffCodes', 'Тариф слишком длинный.');
            }

            return;
        }

        if (! is_array($codes) || $codes === []) {
            $v->errors()->add('shippingTariffCodes', 'Тариф должен быть строкой или картой storeId→code.');

            return;
        }

        foreach ($codes as $storeId => $code) {
            if (! is_string($code) || $code === '') {
                $v->errors()->add('shippingTariffCodes.'.$storeId, 'Пустой тариф для магазина.');
            }
        }
    }

    public function messages(): array
    {
        return [
            'addressId.required' => 'Укажите адрес доставки',
            'addressId.exists' => 'Адрес не найден',
            'deliverySlots.required' => 'Выберите слоты доставки',
            'shippingTariffCodes.required' => 'Укажите тариф доставки',
            'bonusSpendAmount.required_if' => 'Укажите сумму списания бонусов',
            'bonusSpendAmount.max' => 'Недостаточно бонусов на балансе',
        ];
    }
}
