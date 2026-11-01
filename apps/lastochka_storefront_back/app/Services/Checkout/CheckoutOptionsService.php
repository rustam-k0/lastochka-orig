<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\StorefrontSetting;

/**
 * Опции оформления заказа (MENDEV-17): настраиваемые чекбоксы чекаута
 * вида «Охладить напитки!».
 *
 * Список живёт в storefront_settings (key `checkout_options`, json:
 * [{code, label, enabled}]) и управляется из админки событиями
 * admin_settings. Без настройки действует дефолт с опцией охлаждения —
 * фича работает из коробки, а «системность» в том, что набор расширяем
 * без правок кода: новая опция = новый элемент настройки.
 *
 * В заказ пишется снапшот выбранных опций (code + label на момент
 * оформления): переименование опции в админке не меняет старые заказы.
 */
final class CheckoutOptionsService
{
    public const SETTING_KEY = 'checkout_options';

    private const DEFAULT_OPTIONS = [
        ['code' => 'chill_before_delivery', 'label' => 'Охладить напитки!', 'enabled' => true],
    ];

    /**
     * Включённые опции чекаута.
     *
     * @return list<array{code: string, label: string}>
     */
    public function enabled(): array
    {
        $raw = StorefrontSetting::getValue(self::SETTING_KEY, self::DEFAULT_OPTIONS);

        $options = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (! is_array($item) || empty($item['code']) || empty($item['label'])) {
                continue;
            }
            if (! (bool) ($item['enabled'] ?? true)) {
                continue;
            }

            $options[] = [
                'code' => (string) $item['code'],
                'label' => (string) $item['label'],
            ];
        }

        return $options;
    }

    /**
     * Снапшот выбранных опций для сохранения в заказ.
     *
     * @param  list<string>  $codes
     * @return list<array{code: string, label: string}>
     */
    public function snapshotFor(array $codes): array
    {
        $enabled = collect($this->enabled())->keyBy('code');

        return collect($codes)
            ->unique()
            ->filter(fn ($code) => $enabled->has($code))
            ->map(fn ($code) => $enabled->get($code))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> коды, которых нет среди включённых опций
     */
    public function unknownCodes(array $codes): array
    {
        $enabled = array_column($this->enabled(), 'code');

        return array_values(array_diff(array_unique($codes), $enabled));
    }
}
