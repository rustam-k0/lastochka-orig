<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\ModuleConfigService;
use App\Services\Pricing\PriceTierLadder;
use Illuminate\Contracts\Validation\InvokableRule;

/**
 * Лесенка «цены от количества» при ручном вводе (аргумент price_tiers
 * GraphQL-мутаций, MID-308). Строже приёма из 1С: каждая следующая ступень
 * обязана быть дешевле предыдущей.
 *
 * Правило вешается на список целиком (@rulesForArray), ошибки уходят под
 * ключом price_tiers. Порог и цена не больше нуля размечаются поэлементно
 * правилами input PriceTierInput (ключи price_tiers.N.min_quantity и
 * price_tiers.N.value), поэтому здесь не дублируются.
 */
class PriceTiersRule implements InvokableRule
{
    public const MESSAGE_DISABLED = 'Модуль «Цены от количества» выключен.';
    public const MESSAGE_DUPLICATE = 'Пороги количества не должны повторяться.';
    public const MESSAGE_NOT_DECREASING = 'Каждая следующая ступень должна быть дешевле предыдущей.';
    public const MESSAGE_INVALID = 'Некорректная лесенка цен от количества.';

    /** normalize() отбрасывает лесенку длиннее PriceTierLadder::MAX_TIERS (MID-308). */
    public const MESSAGE_TOO_MANY_TIERS = 'Не больше 50 ступеней.';

    /** Лесенка требует цены: бросает ProductShopPriceService::assertLadderHasPrice(), не само правило. */
    public const MESSAGE_PRICE_REQUIRED = 'Сначала задайте цену товара в этом магазине: цены от количества действуют только вместе с обычной ценой.';

    /** Магазин не резолвится: бросает Product\ProductUpdate::__invoke(), не само правило (MID-308). */
    public const MESSAGE_SHOP_REQUIRED = 'Не удалось определить магазин товара: цены от количества не сохранены.';

    public function __invoke($attribute, $value, $fail)
    {
        if ($value === null) {
            return;
        }

        if (!ModuleConfigService::isPriceTiersEnabled()) {
            $fail(self::MESSAGE_DISABLED);

            return;
        }

        if (!is_array($value) || $value === []) {
            return;
        }

        $ladder = PriceTierLadder::normalize($value, 'value');

        if ($ladder === null) {
            if (count($value) > PriceTierLadder::MAX_TIERS) {
                $fail(self::MESSAGE_TOO_MANY_TIERS);

                return;
            }

            if ($this->hasDuplicateThresholds($value)) {
                $fail(self::MESSAGE_DUPLICATE);
            }

            return;
        }

        if (!PriceTierLadder::isStrictlyDecreasing($ladder)) {
            $fail(self::MESSAGE_NOT_DECREASING);
        }
    }

    /**
     * @param  array<int, mixed>  $tiers
     */
    private function hasDuplicateThresholds(array $tiers): bool
    {
        $seen = [];

        foreach ($tiers as $tier) {
            $minQuantity = is_array($tier) ? ($tier['min_quantity'] ?? null) : null;

            if (!is_numeric($minQuantity)) {
                continue;
            }

            $key = number_format(round((float) $minQuantity, 3), 3, '.', '');

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }
}
