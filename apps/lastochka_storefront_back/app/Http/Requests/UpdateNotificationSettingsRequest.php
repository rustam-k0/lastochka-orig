<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ordersEnabled' => ['sometimes', 'boolean'],
            'promoEnabled' => ['sometimes', 'boolean'],
            'pushEnabled' => ['sometimes', 'boolean'],
            'emailEnabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function settings(): array
    {
        $map = [
            'ordersEnabled' => 'orders_enabled',
            'promoEnabled' => 'promo_enabled',
            'pushEnabled' => 'push_enabled',
            'emailEnabled' => 'email_enabled',
        ];

        $payload = [];
        foreach ($map as $camel => $snake) {
            if ($this->has($camel)) {
                $payload[$snake] = $this->boolean($camel);
            }
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ordersEnabled.boolean' => 'Поле «Уведомления о заказах» должно быть true или false.',
            'promoEnabled.boolean' => 'Поле «Акции и распродажи» должно быть true или false.',
            'pushEnabled.boolean' => 'Поле «Push-уведомления» должно быть true или false.',
            'emailEnabled.boolean' => 'Поле «Email-уведомления» должно быть true или false.',
        ];
    }
}
