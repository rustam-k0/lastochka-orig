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

final class CreateOrderRequest extends FormRequest
{
    use ResolvesReturnUrl;

    /** Методы доставки через пункты выдачи (ПВЗ). */
    private const PICKUP_POINT_METHODS = ['cdek', 'russian_post'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('paymentMethod') && ! $this->has('paymentType')) {
            $this->merge(['paymentType' => $this->input('paymentMethod')]);
        }
    }

    public function rules(): array
    {
        $user = $this->user();
        $shippingMethod = $this->input('shippingMethod', 'courier');
        $isPickupPoint = in_array($shippingMethod, self::PICKUP_POINT_METHODS, true);
        // Модуль «Оформление без слотов» (MID-256): слот опционален для
        // курьерской доставки — без него заказ уходит «на ближайшее время».
        // Самовывоз завязан на время выдачи в магазине, поэтому слот там
        // обязателен всегда, независимо от модуля.
        $slotOptional = $isPickupPoint
            || ($shippingMethod !== 'pickup'
                && app(ModuleConfigService::class)->isCheckoutWithoutSlotsEnabled());

        return [
            /**
             * ID магазина, из которого оформляется заказ.
             *
             * @example 1
             */
            'storeId' => ['required', 'integer', Rule::exists('stores', 'id')],
            /**
             * ID адреса доставки (должен принадлежать текущему пользователю).
             * Обязателен для метода доставки курьером, не нужен при самовывозе.
             * При cdek/russian_post — адрес ПВЗ с additional.kind=pickup.
             *
             * @example 5
             */
            'addressId' => [
                $isPickupPoint ? 'required' : 'required_unless:shippingMethod,pickup',
                'nullable',
                'integer',
                $user
                    ? Rule::exists('addresses', 'id')->where('user_id', $user->id)
                    : Rule::exists('addresses', 'id'),
            ],
            /**
             * ID слота доставки (Unix timestamp начала слота).
             * Для доставки в пункт выдачи внешней службы не используется,
             * но может быть передан (игнорируется).
             * При включённом модуле «Оформление без слотов» (GET /app-settings →
             * checkoutWithoutSlots) необязателен для курьерской доставки: без
             * слота заказ доставляется в ближайшее время; переданный слот
             * учитывается как обычно. При shipping_method = pickup слот
             * обязателен всегда (время выдачи в магазине).
             *
             * @example 1735635600
             */
            'deliverySlotId' => [$slotOptional ? 'nullable' : 'required', 'nullable', 'integer'],
            /**
             * Код тарифа внешней службы доставки.
             * Обязателен, когда заказ оформляется в пункт выдачи такой службы.
             *
             * @example 136
             */
            'shippingTariffCode' => [
                Rule::requiredIf($isPickupPoint),
                'nullable',
                'string',
                'max:64',
            ],
            /**
             * Комментарий к заказу.
             *
             * @example Позвоните за 10 минут до доставки
             */
            'comment' => ['nullable', 'string', 'max:1000'],
            /**
             * Коды выбранных опций оформления (GET /app-settings → checkoutOptions),
             * например «Охладить напитки!».
             *
             * @example ["chill_before_delivery"]
             */
            'checkoutOptions' => ['sometimes', 'array', 'max:20'],
            'checkoutOptions.*' => ['string', 'max:64'],
            /**
             * Ключ идемпотентности для предотвращения дублирования.
             *
             * @example 550e8400-e29b-41d4-a716-446655440000
             */
            'idempotencyKey' => ['nullable', 'string', 'max:64'],
            /**
             * Действие с бонусами: начислить или списать.
             *
             * @example spend
             */
            'bonusAction' => ['nullable', 'string', 'in:earn,spend'],
            /**
             * Количество бонусов для списания (целые рубли, 1 бонус = 1 рубль).
             * Не больше доступного баланса пользователя после резервов других заказов.
             *
             * @example 500
             */
            'bonusSpendAmount' => array_filter([
                'required_if:bonusAction,spend',
                'nullable',
                'integer',
                'min:1',
                $user ? 'max:'.(int) $user->available_bonus_balance : null,
            ]),
            /**
             * Метод доставки.
             * Допустимые значения: courier, pickup, cdek, russian_post.
             *
             * @example courier
             */
            'shippingMethod' => ['sometimes', 'string', 'in:courier,pickup,cdek,russian_post'],
            /**
             * Тип оплаты: cash или card.
             *
             * @example card
             */
            'paymentType' => ['sometimes', 'string', 'in:cash,card'],
            /**
             * Адрес, на который вернуть клиента после оплаты картой
             * (ЮKassa confirmation.return_url). Принимается относительный путь
             * витрины (`/payment/success?orderId=1`), абсолютный адрес с
             * разрешённого домена либо диплинк приложения с разрешённой схемой.
             * Не передан — используется адрес по умолчанию из настроек.
             *
             * @example /payment/success
             */
            'returnUrl' => ['nullable', 'string', 'max:'.ReturnUrl::MAX_LENGTH, new ReturnUrlRule],
            /**
             * ID сохранённой карты для автоплатежа.
             *
             * @example 1
             */
            'paymentCardId' => [
                'nullable',
                'integer',
                $user
                    ? Rule::exists('payment_cards', 'id')->where('user_id', $user->id)
                    : Rule::exists('payment_cards', 'id'),
            ],
        ];
    }

    /**
     * Дополнительная валидация: проверяем корректность адреса ПВЗ
     * для методов cdek / russian_post.
     */
    public function withValidator(Validator $validator): void
    {
        // Опции чекаута: принимаем только включённые в настройке коды
        $validator->after(function (Validator $v): void {
            $codes = $this->input('checkoutOptions');
            if (! is_array($codes) || $codes === []) {
                return;
            }

            $unknown = app(\App\Services\Checkout\CheckoutOptionsService::class)
                ->unknownCodes(array_filter($codes, 'is_string'));

            if ($unknown !== []) {
                $v->errors()->add(
                    'checkoutOptions',
                    'Неизвестные опции оформления: '.implode(', ', $unknown),
                );
            }
        });

        $shippingMethod = $this->input('shippingMethod', 'courier');

        if (! in_array($shippingMethod, self::PICKUP_POINT_METHODS, true)) {
            return;
        }

        $validator->after(function (Validator $v) use ($shippingMethod): void {
            $addressId = $this->input('addressId');
            if (! $addressId) {
                return; // Уже обработано базовой required-валидацией
            }

            /** @var Address|null $address */
            $address = Address::find($addressId);
            if (! $address) {
                return; // Уже обработано rules() через Rule::exists
            }

            $additional = $address->additional ?? [];

            if (($additional['kind'] ?? null) !== 'pickup') {
                $v->errors()->add(
                    'addressId',
                    'Адрес должен быть пунктом выдачи заказов (pickup). Укажите адрес ПВЗ.'
                );

                return;
            }

            if (($additional['provider'] ?? null) !== $shippingMethod) {
                $v->errors()->add(
                    'addressId',
                    'Провайдер ПВЗ не совпадает с выбранным методом доставки.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'storeId.required' => 'Укажите магазин',
            'storeId.exists' => 'Магазин не найден',
            'addressId.required' => 'Укажите адрес доставки',
            'addressId.exists' => 'Адрес не найден',
            'deliverySlotId.required' => 'Выберите время доставки',
            'shippingTariffCode.required' => 'Укажите тариф доставки',
            'comment.max' => 'Комментарий слишком длинный',
        ];
    }

    public function attributes(): array
    {
        return [
            'storeId' => 'магазин',
            'addressId' => 'адрес',
            'deliverySlotId' => 'слот доставки',
            'shippingTariffCode' => 'тариф доставки',
            'comment' => 'комментарий',
            'returnUrl' => 'адрес возврата',
        ];
    }
}
