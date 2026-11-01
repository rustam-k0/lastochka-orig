<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeChangesLog;
use App\Models\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfigurableProductService
{
    /**
     * Статусы заказа, при которых variant считается занятым — удаление блокируется.
     */
    private const ACTIVE_ORDER_STATUS_IDS = [
        Status::ORDER_CREATED,
        Status::ORDER_PREPARING,
        Status::ORDER_ASSEMBLED,
        Status::ORDER_IN_DELIVERY,
    ];

    public function generateParentSku(int $parentId): string
    {
        return 'CONF-' . $parentId;
    }

    /**
     * Формирует SKU по маске {parent.articul}-{code1}-{code2}-...
     * Если parent.articul пуст — используется "CONF-{parent.id}".
     *
     * @param  list<string>  $attributeValueCodes
     */
    public function generateVariantSku(Product $parent, array $attributeValueCodes): string
    {
        $base = $parent->articul ?: $this->generateParentSku((int) $parent->id);
        $codes = array_values(array_filter(array_map(
            fn ($code) => $code === null ? null : (string) $code,
            $attributeValueCodes
        ), static fn ($code) => $code !== null && $code !== ''));

        if (empty($codes)) {
            return $base;
        }

        return $base . '-' . implode('-', $codes);
    }

    /**
     * Проверяет, что комбинация attribute_value_ids уникальна для вариантов parent'а.
     *
     * @param  list<int>  $attributeValueIds
     */
    public function ensureCombinationUnique(int $parentId, array $attributeValueIds, ?int $excludeVariantId = null): void
    {
        if (empty($attributeValueIds)) {
            return;
        }

        $normalized = $this->normalizeCombination($attributeValueIds);

        $siblings = Product::query()
            ->where('parent_id', $parentId)
            ->when($excludeVariantId, fn ($q) => $q->where('id', '!=', $excludeVariantId))
            ->pluck('id');

        foreach ($siblings as $siblingId) {
            $siblingValues = ProductAttribute::query()
                ->where('product_id', $siblingId)
                ->whereNotNull('attribute_value_id')
                ->pluck('attribute_value_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($this->normalizeCombination($siblingValues) === $normalized) {
                throw ValidationException::withMessages([
                    'attribute_values' => ['Комбинация атрибутов уже существует для варианта #' . $siblingId],
                ]);
            }
        }
    }

    public function ensureNoActiveOrders(Product $variant): void
    {
        $blocked = $this->hasActiveOrders((int) $variant->id);
        if ($blocked) {
            throw ValidationException::withMessages([
                'variant_id' => ['Нельзя удалить вариант #' . $variant->id . ' — он используется в активных заказах'],
            ]);
        }
    }

    public function hasActiveOrders(int $variantId): bool
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $variantId)
            ->whereIn('orders.status_id', self::ACTIVE_ORDER_STATUS_IDS)
            ->exists();
    }

    public function logChange(int $productId, string $operation, array $payload, ?int $adminId = null): void
    {
        ProductAttributeChangesLog::create([
            'product_id' => $productId,
            'operation' => $operation,
            'payload' => $payload,
            'admin_id' => $adminId ?? optional(auth()->user())->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Для B1: возвращает список комбинаций, которые нужно создать при добавлении нового значения атрибута.
     *
     * @return list<array{attribute_values: list<array{attribute_id:int, attribute_value_id:int}>}>
     */
    public function previewNewCombinations(Product $parent, int $attributeId, int $newAttributeValueId): array
    {
        $children = Product::query()
            ->where('parent_id', $parent->id)
            ->with('attributes')
            ->get();

        if ($children->isEmpty()) {
            return [];
        }

        $configurableAttributeIds = $this->extractConfigurableAttributeIds($children);

        if (!in_array($attributeId, $configurableAttributeIds, true)) {
            throw ValidationException::withMessages([
                'attribute_id' => ['Атрибут не является вариативным для этого товара'],
            ]);
        }

        $otherAttributeIds = array_values(array_diff($configurableAttributeIds, [$attributeId]));

        $otherCombinations = [];
        foreach ($children as $child) {
            $values = [];
            foreach ($child->attributes as $pa) {
                if (in_array((int) $pa->attribute_id, $otherAttributeIds, true)) {
                    $values[(int) $pa->attribute_id] = (int) $pa->attribute_value_id;
                }
            }
            ksort($values);
            $key = json_encode($values);
            $otherCombinations[$key] = $values;
        }

        $existingKeys = [];
        foreach ($children as $child) {
            $values = [];
            foreach ($child->attributes as $pa) {
                if (in_array((int) $pa->attribute_id, $configurableAttributeIds, true)) {
                    $values[(int) $pa->attribute_id] = (int) $pa->attribute_value_id;
                }
            }
            ksort($values);
            $existingKeys[json_encode($values)] = true;
        }

        $missing = [];
        foreach ($otherCombinations as $otherValues) {
            $candidate = $otherValues;
            $candidate[$attributeId] = $newAttributeValueId;
            ksort($candidate);
            $key = json_encode($candidate);

            if (isset($existingKeys[$key])) {
                continue;
            }

            $attributeValuesPayload = [];
            foreach ($candidate as $attrId => $valueId) {
                $attributeValuesPayload[] = [
                    'attribute_id' => (int) $attrId,
                    'attribute_value_id' => (int) $valueId,
                ];
            }

            $missing[] = ['attribute_values' => $attributeValuesPayload];
        }

        return $missing;
    }

    public function assertFamilyLocked(int $familyId): void
    {
        $hasProducts = DB::table('products')
            ->join('category_product', 'products.id', '=', 'category_product.product_id')
            ->join('categories', 'category_product.category_id', '=', 'categories.id')
            ->where('categories.attribute_family_id', $familyId)
            ->whereNull('products.deleted_at')
            ->exists();

        if ($hasProducts) {
            throw ValidationException::withMessages([
                'attribute_family_id' => ['Состав вариативных атрибутов семейства заморожен — в семействе уже есть товары'],
            ]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalizeCombination(array $ids): array
    {
        $int = array_map('intval', $ids);
        sort($int, SORT_NUMERIC);

        return array_values($int);
    }

    /**
     * Определяет attribute_id, являющиеся вариативными для parent'а по существующим children'ам.
     *
     * @return list<int>
     */
    private function extractConfigurableAttributeIds(iterable $children): array
    {
        $ids = [];
        foreach ($children as $child) {
            foreach ($child->attributes as $pa) {
                if ($pa->attribute_value_id !== null) {
                    $ids[(int) $pa->attribute_id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

}
