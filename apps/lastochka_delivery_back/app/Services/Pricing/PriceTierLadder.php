<?php

namespace App\Services\Pricing;

/**
 * Лесенка «цены от количества» (MID-308): нормализация, сравнение и перевод
 * из базовых единиц товара в порции. Код идентичен во всех сервисах платформы.
 */
final class PriceTierLadder
{
    /**
     * Предел числа ступеней. Лесенка длиннее считается битой: снапшот лесенки
     * ложится в каждую позицию заказа и в каждое событие заказа, поэтому ошибка
     * источника (тег, продублированный циклом) не должна раздувать payload'ы.
     */
    public const MAX_TIERS = 50;

    /**
     * Верхние границы порога и цены: разрядность колонок таблиц ступеней
     * (min_quantity decimal(10,3), цена decimal(12,2)). Значение вне диапазона
     * (штрихкод в поле количества, 1e12 из кривой выгрузки) иначе дошло бы до
     * INSERT и на MySQL в strict mode уронило бы транзакцию консьюмера вместе
     * с ценами всей пачки. Такая лесенка считается битой, как и остальные.
     */
    public const MAX_QUANTITY = 9999999.999;

    public const MAX_PRICE = 9999999999.99;

    /**
     * Приводит сырую лесенку к отсортированному списку ступеней.
     *
     * @param  mixed  $raw  значение ключа price_tiers из события или аргумента мутации
     * @param  string  $priceKey  ключ цены во входных элементах: 'price' (события) или 'value' (GraphQL, djami)
     * @return array<int, array{min_quantity: float, price: float}>|null
     *                                                                     [] для пустого входа, null для невалидной лесенки
     */
    public static function normalize($raw, string $priceKey = 'price'): ?array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            return null;
        }

        if (count($raw) > self::MAX_TIERS) {
            return null;
        }

        $byThreshold = [];

        foreach ($raw as $tier) {
            if (! is_array($tier)) {
                return null;
            }

            $minQuantity = $tier['min_quantity'] ?? null;
            $price = $tier[$priceKey] ?? null;

            if (! is_numeric($minQuantity) || ! is_numeric($price)) {
                return null;
            }

            $minQuantity = round((float) $minQuantity, 3);
            $price = round((float) $price, 2);

            if ($minQuantity <= 0 || $price <= 0) {
                return null;
            }

            if ($minQuantity > self::MAX_QUANTITY || $price > self::MAX_PRICE) {
                return null;
            }

            $key = number_format($minQuantity, 3, '.', '');
            if (isset($byThreshold[$key])) {
                return null;
            }

            $byThreshold[$key] = ['min_quantity' => $minQuantity, 'price' => $price];
        }

        $ladder = array_values($byThreshold);
        usort($ladder, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return $ladder;
    }

    /**
     * Каждая следующая ступень дешевле предыдущей (проверка ручного ввода).
     *
     * @param  array<int, array{min_quantity: float, price: float}>  $ladder  результат normalize()
     */
    public static function isStrictlyDecreasing(array $ladder): bool
    {
        for ($i = 1, $count = count($ladder); $i < $count; $i++) {
            if ($ladder[$i]['price'] >= $ladder[$i - 1]['price']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{min_quantity: float, price: float}>  $a
     * @param  array<int, array{min_quantity: float, price: float}>  $b
     */
    public static function same(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $tier) {
            if (abs($tier['min_quantity'] - $b[$i]['min_quantity']) >= 0.0005
                || abs($tier['price'] - $b[$i]['price']) >= 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * Перевод из базовых единиц товара в порции и цену за порцию.
     *
     * @param  array<int, array{min_quantity: float, price: float}>  $ladder  результат normalize()
     * @param  float  $baseSize  размер базовой единицы в наименьших (кг, л = 1000; г, мл, шт = 1)
     * @param  float  $stepSmallest  размер порции в наименьших единицах
     * @return array<int, array{min_quantity: float, price: float}>
     */
    public static function toPortions(array $ladder, float $baseSize, float $stepSmallest): array
    {
        if ($baseSize <= 0 || $stepSmallest <= 0 || abs($baseSize - $stepSmallest) < 0.0005) {
            return $ladder;
        }

        return array_map(static fn (array $tier): array => [
            'min_quantity' => round($tier['min_quantity'] * $baseSize / $stepSmallest, 3),
            // Без промежуточного округления цены за грамм: оно теряло копейки.
            'price' => round($tier['price'] * $stepSmallest / $baseSize, 2),
        ], $ladder);
    }
}
