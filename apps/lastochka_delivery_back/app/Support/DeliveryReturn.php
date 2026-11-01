<?php

namespace App\Support;

/**
 * Чистая математика и валидация возврата товара курьером при доставке (tg-14265).
 * Без БД и Laravel — для unit-тестов в обход prod-DB мины delivery_back.
 *
 * «Порции» — шкала ProductConfirm::getStepGrams(): штучный товар 1 порция = 1 шт,
 * весовой — порция = step_grams. Весовой возвращается только полностью.
 */
final class DeliveryReturn
{
    /**
     * Весовой ли товар. Основной признак — карточка товара
     * (Product::isWeightBased(), info_weight_type_id), тот же, по которому
     * приложение курьера решает, слать quantity или нет. actual_weight —
     * только fallback для позиций без товара: на штучном он бывает залипшим
     * от старых версий мобилки сборщика (MID-246) и весовость не означает.
     *
     * @param bool|null $isWeightBasedProduct null — товар позиции недоступен
     */
    public static function isWeighted(?bool $isWeightBasedProduct, ?int $actualWeight): bool
    {
        if ($isWeightBasedProduct !== null) {
            return $isWeightBasedProduct;
        }

        return $actualWeight !== null;
    }

    /** Сколько порций было собрано (списано) по позиции. */
    public static function collectedPortions(?int $quantity, ?int $actualWeight, int $stepGrams): int
    {
        if ($actualWeight !== null) {
            return max(1, (int) round($actualWeight / max(1, $stepGrams)));
        }

        return (int) ($quantity ?? 0);
    }

    /**
     * Валидация запроса на возврат. null = ок, иначе текст ошибки.
     *
     * @param int $alreadyReturned уже возвращённые порции по позиции
     * @param int $collected всего собрано порций
     */
    public static function validateReturn(bool $isWeighted, ?int $quantity, int $alreadyReturned, int $collected): ?string
    {
        if ($isWeighted) {
            // Весовой: частичный запрос (передан quantity) запрещён.
            if ($quantity !== null) {
                return 'Весовой товар можно вернуть только полностью';
            }

            return null;
        }

        // Штучный: количество обязательно и в диапазоне [1 .. collected - alreadyReturned].
        if ($quantity === null) {
            return 'Укажите количество для возврата';
        }

        $available = $collected - $alreadyReturned;
        if ($quantity < 1 || $quantity > $available) {
            return 'Количество превышает доступное к возврату';
        }

        return null;
    }

    /** Итоговое returned_quantity для записи (накопительно для штучного, полное для весового). */
    public static function resolveReturnedPortions(bool $isWeighted, ?int $quantity, int $alreadyReturned, int $collected): int
    {
        if ($isWeighted) {
            return $collected;
        }

        return min($collected, $alreadyReturned + (int) ($quantity ?? 0));
    }

    /**
     * Стоимость фактически доставленного по позиции (collected − returned).
     *
     * @param float|null $weighedLineTotal итог взвешенной строки, зафиксированный
     *   при сборке (order_items.price после AssemblyFinish). Приоритетнее пересчёта
     *   по живому шагу: правка фасовки между сборкой и доставкой меняла сумму.
     */
    public static function deliveredLineTotal(float $unitPrice, ?int $actualWeight, int $stepGrams, int $collected, int $returned, ?float $weighedLineTotal = null): float
    {
        if ($actualWeight !== null) {
            // Весовой — бинарно: вернули полностью → 0, иначе полная стоимость веса.
            if ($returned >= $collected) {
                return 0.0;
            }

            if ($weighedLineTotal !== null) {
                return round($weighedLineTotal, 2);
            }

            return round($unitPrice * $actualWeight / max(1, $stepGrams), 2);
        }

        $delivered = max(0, $collected - $returned);

        return round($unitPrice * $delivered, 2);
    }
}
