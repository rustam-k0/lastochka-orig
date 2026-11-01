<?php

namespace App\Services\Quantum;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Price;
use App\Models\Product;
use App\Models\Type;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт цены товара в цену «кванта» (LASTOCHKI-JAMI-11200), адаптивно по
 * единицам измерения: масса / объём / штуки.
 *
 * Квант задаётся менеджером на витринной листовой категории: quantum_step (в
 * наименьшей единице семейства — грамм/мл/штука) + quantum_unit_type_id.
 * Применяется только к товарам совпадающего семейства (по base_unit_type_id).
 *
 * Сырая цена 1С нормализуется к цене за наименьшую единицу семейства и хранится
 * в prices.price_per_small_unit, поэтому пересчёт тривиален:
 *   ЦенаКванта = round(price_per_small_unit * quantum_step, 2).
 *
 * Если товар в нескольких витринных категориях с квантом своего семейства —
 * берётся МИНИМАЛЬНЫЙ шаг (меньшая порция, клиент не переплачивает).
 */
class CategoryQuantumService
{
    /** Точность price_per_small_unit: DECIMAL(16,6), цена в копейках/1000 — точно. */
    public const PER_SMALL_PRECISION = 6;

    /** Семейство товара по его сырой единице из 1С (fallback — info_weight_type_id). */
    public function productFamily(Product $product): ?string
    {
        return UnitFamily::family($product->base_unit_type_id ?? $product->info_weight_type_id);
    }

    /**
     * Квант витринных категорий товара (минимальный шаг среди категорий того же
     * семейства): ['step' => int, 'unit' => int] либо null.
     */
    public function resolveCategoryQuantum(Product $product): ?array
    {
        $family = $this->productFamily($product);

        if ($family === null) {
            return null;
        }

        $step = $this->categoryQuantumStep($product, $family);

        return $step !== null
            ? ['step' => $step, 'unit' => UnitFamily::smallestUnit($family)]
            : null;
    }

    /** Минимальный шаг кванта среди витринных категорий товара того же семейства. */
    public function categoryQuantumStep(Product $product, string $family): ?int
    {
        $allowedUnits = array_values(array_filter(
            UnitFamily::allowedQuantumUnits(),
            fn ($u) => UnitFamily::family($u) === $family
        ));

        if ($allowedUnits === []) {
            return null;
        }

        $min = Category::query()
            ->whereIn('categories.id', $product->categories()->select('categories.id'))
            ->whereNotNull('quantum_step')
            ->where('quantum_step', '>', 0)
            ->whereIn('quantum_unit_type_id', $allowedUnits)
            ->min('quantum_step');

        return $min !== null ? (int) $min : null;
    }

    /**
     * Эффективный квант товара: ['step' => int, 'unit' => int] либо null.
     * Приоритет: ручной квант товара (manual_quantum_step + manual_quantum_unit_type_id,
     * того же семейства) — ИСКЛЮЧЕНИЕ → квант витринной категории.
     */
    public function resolveQuantum(Product $product): ?array
    {
        $family = $this->productFamily($product);

        // Ручной квант товара — исключение, приоритетнее витринной категории.
        $manualStep = (int) ($product->manual_quantum_step ?? 0);
        $manualUnit = (int) ($product->manual_quantum_unit_type_id ?? 0);

        if ($family !== null && $manualStep > 0 && UnitFamily::family($manualUnit) === $family) {
            return ['step' => $manualStep, 'unit' => UnitFamily::smallestUnit($family)];
        }

        return $this->resolveCategoryQuantum($product);
    }

    /**
     * Применить вручную заданную в карточке цену как АВТОРИТЕТНУЮ.
     *
     * Введённое менеджером значение — это ОТОБРАЖАЕМАЯ цена товара: за квант
     * (если он задан категорией/ручным исключением) либо за базовую единицу
     * (если кванта нет). Восстанавливаем из неё сырую цену за базовую единицу и
     * прогоняем через applyToProduct — так price_per_small_unit / price_per_kg и
     * цена кванта становятся согласованы с введённым значением.
     *
     * Без этого ручная цена откатывалась к нулю: applyToProduct(null) берёт
     * perSmall из сохранённого price_per_small_unit, а у весового/1С-товара без
     * цены из 1С там лежит 0 (perSmall=0 → value=0), и только что введённая
     * менеджером цена молча затиралась нулём при каждом сохранении.
     *
     * Защита от удвоения базовой цены при смене ШАГА: если пересохранение вызвано
     * сменой шага кванта (фасовки), а не цены, фронт присылает прежнюю
     * отображаемую цену (за СТАРЫЙ шаг). Реконструкция perSmall = enteredValue /
     * новый_шаг исказила бы базовую цену 1С пропорц. старый/новый шаг. Поэтому
     * если введённая цена совпадает с прежней отображаемой ($previousDisplayed),
     * perSmall сохраняем как есть, пересчитывая лишь цену порции по новому шагу.
     *
     * @param  bool       $dispatchEvent     Опубликовать ProductUpdated (outbox → storefront).
     * @param  float|null $previousDisplayed Отображаемая цена товара ДО сохранения
     *                                       (prices.value до мутаций). null — не сверять.
     * @return bool true — цена/шаг/единица изменились.
     */
    public function applyManualPrice(Product $product, int $shopId, float $enteredValue, bool $dispatchEvent = true, ?float $previousDisplayed = null): bool
    {
        // Цена не менялась (введённое == прежней отображаемой), изменился лишь
        // шаг/квант → базовую цену 1С не реконструируем, пересчитываем порцию по
        // новому шагу из сохранённого price_per_small_unit. Только при наличии
        // сохранённого perSmall — для ручного товара без цены 1С корректнее
        // реконструкция (perSmall создаётся из введённой цены за текущий шаг).
        if ($previousDisplayed !== null && abs(round($previousDisplayed, 2) - round($enteredValue, 2)) < 0.005) {
            $hasPerSmall = Price::where('product_id', $product->id)
                ->where('shop_id', $shopId)
                ->where('type_id', Type::PRICE_DEFAULT)
                ->whereNotNull('price_per_small_unit')
                ->exists();

            if ($hasPerSmall) {
                return $this->applyToProduct($product, $shopId, null, $dispatchEvent);
            }
        }

        $baseUnit = $product->base_unit_type_id ?? $product->info_weight_type_id;
        $baseSize = max(1, UnitFamily::sizeInSmallest($baseUnit));

        $quantum = $this->resolveQuantum($product);

        // Делитель отображаемой цены: шаг кванта (цена за порцию) либо размер
        // базовой единицы в наименьших (цена за базовую единицу, когда кванта нет).
        $divisor = $quantum !== null ? max(1, (int) $quantum['step']) : $baseSize;

        // Сырая цена за базовую единицу, из которой applyToProduct воспроизведёт
        // ровно введённое отображаемое значение.
        $rawPerBase = ($enteredValue / $divisor) * $baseSize;

        return $this->applyToProduct($product, $shopId, $rawPerBase, $dispatchEvent);
    }

    /**
     * Пересчитать и сохранить цену кванта товара по его витринным категориям.
     *
     * @param  int|null   $shopId    Магазин цены. null — берётся product->shop_id.
     * @param  float|null $rawPrice  Сырая цена 1С за БАЗОВУЮ единицу. null — берётся
     *                               сохранённая price_per_small_unit, иначе value.
     * @param  bool       $dispatchEvent  Опубликовать ProductUpdated (outbox → storefront).
     * @return bool  true — цена/шаг/единица изменились.
     */
    public function applyToProduct(Product $product, ?int $shopId = null, ?float $rawPrice = null, bool $dispatchEvent = true): bool
    {
        $shopId ??= (int) $product->shop_id;

        if (!$shopId) {
            return false;
        }

        $price = Price::where('product_id', $product->id)
            ->where('shop_id', $shopId)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->first();

        if (!$price) {
            return false;
        }

        $baseUnit = $product->base_unit_type_id ?? $product->info_weight_type_id;
        $baseSize = UnitFamily::sizeInSmallest($baseUnit);

        // Цена за наименьшую единицу: из аргумента (сырая 1С за базовую) →
        // сохранённая price_per_small_unit → нормализованное value.
        // 6 знаков: цена в копейках за кг / 1000 укладывается точно (199.99 →
        // 0.19999); с 4 знаками пересчёт терял копейки (→ 200.00).
        if ($rawPrice !== null) {
            $perSmall = round($rawPrice / $baseSize, self::PER_SMALL_PRECISION);
        } elseif ($price->price_per_small_unit !== null) {
            $perSmall = (float) $price->price_per_small_unit;
        } else {
            $perSmall = round((float) $price->value / $baseSize, self::PER_SMALL_PRECISION);
        }

        // Цена за базовую единицу: сырая из аргумента точнее восстановленной.
        $perBase = $rawPrice ?? $perSmall * $baseSize;

        $quantum = $this->resolveQuantum($product);

        if ($quantum !== null) {
            $newValue = round($perBase * $quantum['step'] / $baseSize, 2);
            $newStep = $quantum['step'];
            $infoWeight = $quantum['step'];
            $infoWeightType = $quantum['unit'];
        } else {
            // Квант не задан — продажа за базовую единицу 1С (как v2: цена за
            // базовую единицу, step=1, info_weight=null; для кг/литра storefront
            // хранит количество в наименьших единицах и делит на 1000).
            $newValue = round($perBase, 2);
            $newStep = 1;
            $infoWeight = null;
            $infoWeightType = $baseUnit;
        }

        $changed = (float) $price->value !== (float) $newValue
            || (int) $price->step !== (int) $newStep
            || (int) $product->info_weight_type_id !== (int) $infoWeightType
            || (int) ($product->info_weight ?? 0) !== (int) ($infoWeight ?? 0);

        // price_per_kg (за кг) — shared-колонка для purchase_back/delivery_back;
        // поддерживаем только для весовых (масса), как и раньше.
        $priceFill = [
            'value' => $newValue,
            'step' => $newStep,
            'price_per_small_unit' => round($perSmall, self::PER_SMALL_PRECISION),
        ];
        if (UnitFamily::family($baseUnit) === UnitFamily::MASS) {
            $priceFill['price_per_kg'] = round($perBase * 1000 / $baseSize, 2);
        }

        Price::withoutEvents(function () use ($price, $priceFill) {
            $price->forceFill($priceFill)->save();
        });

        Product::withoutEvents(function () use ($product, $infoWeight, $infoWeightType) {
            $product->forceFill([
                'info_weight' => $infoWeight,
                'info_weight_type_id' => $infoWeightType,
            ])->save();
        });

        if ($changed && $dispatchEvent) {
            event(new ProductUpdated($product->fresh()));
        }

        return $changed;
    }

    /**
     * Пересчитать товары витринной категории совпадающего семейства
     * (после смены её кванта). Цена пересчитывается в магазине каждого товара.
     */
    /**
     * Сетевой каталог: цена у товара своя в каждом магазине, а размер порции —
     * общий на карточке. Пересчитываем каждую ценовую строку и публикуем оффер
     * магазина (price_updated/stock_updated): карточное событие офферных цен не
     * несёт, и без этого витрина держала старую цену порции при новом размере.
     */
    private function applyToNetworkProduct(Product $product): bool
    {
        $shopIds = Price::where('product_id', $product->id)
            ->where('type_id', Type::PRICE_DEFAULT)
            ->whereNotNull('shop_id')
            ->pluck('shop_id')
            ->unique();

        $changed = false;
        foreach ($shopIds as $shopId) {
            $changed = $this->applyToProduct($product->fresh(), (int) $shopId, null, dispatchEvent: false) || $changed;
        }

        if (!$changed) {
            return false;
        }

        $offers = app(\App\Services\ProductShopPrice\ProductShopPriceService::class);
        $fresh = $product->fresh();
        foreach (\App\Models\Shop::whereIn('id', $shopIds)->get() as $shop) {
            $price = (float) Price::where('product_id', $product->id)->where('shop_id', $shop->id)
                ->where('type_id', Type::PRICE_DEFAULT)->value('value');
            $quantity = (float) (\App\Models\Remain::where('product_id', $product->id)
                ->where('shop_id', $shop->id)->value('quantity') ?? 0);
            $offers->publishOfferEvents($fresh, $shop, $price, $quantity);
        }

        event(new ProductUpdated($fresh));

        return true;
    }

    public function recalcCategory(Category $category): int
    {
        $count = 0;
        $family = UnitFamily::family($category->quantum_unit_type_id);

        $query = $category->products();

        if ($family !== null) {
            $units = array_values(array_filter(
                [
                    Type::INFO_WEIGHT_GRAM,
                    Type::INFO_WEIGHT_KILOGRAM,
                    Type::INFO_WEIGHT_LITER,
                    Type::INFO_WEIGHT_M_LITER,
                    Type::INFO_WEIGHT_PIECE,
                ],
                fn ($u) => UnitFamily::family($u) === $family
            ));

            $query->where(function ($q) use ($units) {
                $q->whereIn('products.base_unit_type_id', $units)
                    ->orWhere(function ($q2) use ($units) {
                        $q2->whereNull('products.base_unit_type_id')
                            ->whereIn('products.info_weight_type_id', $units);
                    });
            });
        }

        $network = app(\App\NetworkCatalog\Support\NetworkCatalogMode::class)->isEnabled();

        $query->chunkById(200, function ($products) use (&$count, $network) {
            foreach ($products as $product) {
                try {
                    $changed = $network
                        ? $this->applyToNetworkProduct($product)
                        : $this->applyToProduct($product);

                    if ($changed) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Quantum recalc failed for product', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Category quantum recalc finished', [
            'category_id' => $category->id,
            'quantum_step' => $category->quantum_step,
            'quantum_unit_type_id' => $category->quantum_unit_type_id,
            'recalculated' => $count,
        ]);

        return $count;
    }
}
