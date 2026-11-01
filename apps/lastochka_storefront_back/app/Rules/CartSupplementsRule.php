<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Product;
use App\Models\Supplement;
use App\Services\Cart\CartStoreOffers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CartSupplementsRule implements ValidationRule
{
    /**
     * @param  int  $storeId  магазин покупки (сетевой каталог): остаток и режим
     *                        учёта товарной добавки берутся из его оффера,
     *                        а не из нулевой глобальной карточки. 0 — легаси.
     */
    public function __construct(
        private int $productId,
        private int $storeId = 0,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Добавки должны быть массивом');

            return;
        }

        $productSupplements = Supplement::where('product_id', $this->productId)
            ->with('values.product')
            ->get();

        // Сетевой каталог: остаток и режим учёта товарной добавки живут в
        // офферах магазина покупки — навешиваем их карточкам добавок, иначе
        // проверка ниже читает нулевую колонку глобальной карточки и не даёт
        // добавить в корзину ни один товар с каталожной добавкой.
        $storeOffers = app(CartStoreOffers::class);
        $network = $storeOffers->isNetworkMode();

        if ($network) {
            $storeOffers->attachToSupplementValues(
                $productSupplements->flatMap(fn (Supplement $supplement) => $supplement->values),
                $this->storeId
            );
        }

        // Проверяем обязательные добавки
        $requiredSupplements = $productSupplements->where('is_required', true);
        $submittedIds = collect($value)->pluck('supplementId')->toArray();

        foreach ($requiredSupplements as $required) {
            if (! in_array($required->id, $submittedIds)) {
                $fail('Не все обязательные добавки выбраны');

                return;
            }

            $submitted = collect($value)->firstWhere('supplementId', $required->id);
            $normalized = $submitted ? $this->normalizeValues($submitted) : [];
            if (! $submitted || empty($normalized)) {
                $fail('Не все обязательные добавки выбраны');

                return;
            }
        }

        $mainProduct = Product::find($this->productId);
        $mainPortions = $this->resolvePortions($mainProduct, (int) request()->input('quantity', 1));

        foreach ($value as $item) {
            $supplementId = $item['supplementId'] ?? null;
            $supplement = $productSupplements->firstWhere('id', $supplementId);

            if (! $supplement) {
                $fail('Добавка не принадлежит данному товару');

                return;
            }

            // Нормализуем значения: [{valueId, quantity}]
            $normalized = $this->normalizeValues($item);
            if (empty($normalized)) {
                $fail("Выберите значение добавки «{$supplement->title}»");

                return;
            }

            if ($supplement->type === Supplement::TYPE_ONE && count($normalized) > 1) {
                $fail("Для добавки «{$supplement->title}» можно выбрать только одно значение");

                return;
            }

            $validValues = $supplement->values->keyBy('id');
            foreach ($normalized as $sel) {
                $sv = $validValues->get($sel['valueId']);
                if (! $sv) {
                    $fail('Добавка не принадлежит данному товару');

                    return;
                }
                if ($sv->max_quantity !== null && $sel['quantity'] > $sv->max_quantity) {
                    $fail("Для добавки «{$sv->title}» максимум {$sv->max_quantity} шт.");

                    return;
                }
                if ($sv->isFromCatalog()) {
                    // Магазин покупки не определился — остаток добавки проверить
                    // не по чему; запрос всё равно отклонит Action с внятной
                    // причиной («передайте storeId или выберите адрес»).
                    if ($network && $this->storeId <= 0) {
                        continue;
                    }

                    // Добавка продаётся там же, где основной товар: без оффера
                    // магазина покупки её нельзя ни оценить, ни списать.
                    if ($network && $sv->product?->storeContext() === null) {
                        $fail("Добавка «{$sv->title}» недоступна в выбранном магазине");

                        return;
                    }

                    if ($sv->tracksStock()) {
                        $needed = $sel['quantity'] * $mainPortions;
                        if ($needed > $sv->product->stockQuantity()) {
                            $fail("Добавки «{$sv->title}» недостаточно на складе");

                            return;
                        }
                    }
                }
            }
        }
    }

    /**
     * Нормализует входные значения добавки в единый формат [{valueId, quantity}].
     * Принимает либо valueQuantities (новый формат), либо valueIds (старый формат).
     *
     * @return array<array{valueId:int, quantity:int}>
     */
    private function normalizeValues(array $item): array
    {
        if (! empty($item['valueQuantities']) && is_array($item['valueQuantities'])) {
            return array_map(
                fn ($v) => ['valueId' => (int) $v['valueId'], 'quantity' => max(1, (int) $v['quantity'])],
                $item['valueQuantities']
            );
        }

        $ids = $item['valueIds'] ?? $item['values'] ?? [];

        return array_map(fn ($id) => ['valueId' => (int) $id, 'quantity' => 1], $ids);
    }

    /**
     * Вычисляет количество порций основного товара.
     * Для товаров с шагом quantity_step > 1 — количество делится на шаг.
     */
    private function resolvePortions(?Product $product, int $quantity): int
    {
        $step = (int) ($product?->quantity_step ?? 1);

        return $step > 1 ? intdiv($quantity, $step) : $quantity;
    }
}
