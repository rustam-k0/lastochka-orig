<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\MeasurementUnit;
use App\Models\Product;
use App\Models\ProductPriceTier;

/**
 * Лесенки «цены от количества» (MID-308) для чтения: пачечная загрузка из
 * product_price_tiers и перевод из базовых единиц товара в порции.
 *
 * Регистрируется scoped: кеш лесенок живёт один запрос, резолвер и ресурсы
 * делят один экземпляр. Флаг модуля здесь не проверяется: это делает
 * {@see ProductPriceResolver}.
 */
final class ProductTierLadderProvider
{
    /** @var array<string, array<int, array{min_quantity: float, price: float}>> ключ «store_id:product_id», базовые единицы */
    private array $memo = [];

    /**
     * Размеры единиц товара для PriceTierLadder::toPortions().
     *
     * Правило то же, каким цена карточки переводится в цену кванта
     * ({@see \App\RabbitMQ\Transformers\ProductEventTransformer::reconcileQuantumUnits()}):
     * перевод нужен только товару с шагом продажи (quantity_step > 1). У такого
     * товара порция = шаг (см. CartItem::getPortionsAttribute()), а базовая
     * единица события: кг/литр для массы и объёма (1000 наименьших) либо штука.
     * Весовой без кванта продаётся целыми килограммами: порция = базовая
     * единица, перевода нет (CreateOrderAction::storedQuantity()).
     *
     * @return array{0: float, 1: float} [baseSize, stepSmallest]
     */
    public static function unitSizes(Product $product): array
    {
        $step = max(1, (int) ($product->quantity_step ?? 1));

        $unit = $product->measurement_unit;
        $unitValue = $unit instanceof MeasurementUnit ? $unit->value : (string) ($unit ?? 'piece');

        $isLarge = in_array($unitValue, ['kilogram', 'liter'], true);
        $isMeasured = $isLarge || in_array($unitValue, ['gram', 'milliliter'], true);

        if ($step > 1) {
            return [$isMeasured ? 1000.0 : 1.0, (float) $step];
        }

        if ($isLarge) {
            return [1000.0, 1000.0];
        }

        return [1.0, 1.0];
    }

    /**
     * Загрузить лесенки пачкой одним запросом.
     *
     * @param  iterable<int, array{0: int, 1: int}>  $pairs  пары [store_id, product_id]
     */
    public function preload(iterable $pairs): void
    {
        $missing = [];
        $storeIds = [];
        $productIds = [];

        foreach ($pairs as [$storeId, $productId]) {
            $storeId = (int) $storeId;
            $productId = (int) $productId;

            if ($storeId <= 0 || $productId <= 0) {
                continue;
            }

            $key = $storeId.':'.$productId;

            if (array_key_exists($key, $this->memo)) {
                continue;
            }

            $missing[$key] = true;
            $storeIds[$storeId] = $storeId;
            $productIds[$productId] = $productId;
        }

        if ($missing === []) {
            return;
        }

        $loaded = ProductPriceTier::laddersFor(array_values($storeIds), array_values($productIds));

        foreach (array_keys($missing) as $key) {
            $this->memo[$key] = $loaded[$key] ?? [];
        }
    }

    /**
     * Лесенка товара в магазине в базовых единицах ([] если её нет).
     *
     * @return array<int, array{min_quantity: float, price: float}>
     */
    public function baseLadder(int $productId, int $storeId): array
    {
        $key = $storeId.':'.$productId;

        if (! array_key_exists($key, $this->memo)) {
            $this->preload([[$storeId, $productId]]);
        }

        return $this->memo[$key] ?? [];
    }

    /**
     * Лесенка товара в магазине в порциях и ценах за порцию.
     *
     * @return array<int, array{min_quantity: float, price: float}>
     */
    public function portionLadder(Product $product, int $storeId): array
    {
        $ladder = $this->baseLadder((int) $product->id, $storeId);

        if ($ladder === []) {
            return [];
        }

        [$baseSize, $stepSmallest] = self::unitSizes($product);

        return PriceTierLadder::toPortions($ladder, $baseSize, $stepSmallest);
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
