<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AttributeFamilyService
{
    /**
     * Можно ли ещё редактировать состав is_configurable в семействе.
     * false — если у категорий этого семейства уже есть товары.
     */
    public function canChangeConfigurableFlag(int $familyId): bool
    {
        if (!Schema::hasTable('categories') || !Schema::hasTable('products')) {
            return true;
        }

        if (Schema::hasTable('category_product')) {
            return !DB::table('category_product')
                ->join('categories', 'category_product.category_id', '=', 'categories.id')
                ->join('products', 'category_product.product_id', '=', 'products.id')
                ->where('categories.attribute_family_id', $familyId)
                ->whereNull('products.deleted_at')
                ->exists();
        }

        if (Schema::hasColumn('products', 'category_id')) {
            return !DB::table('products')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('categories.attribute_family_id', $familyId)
                ->whereNull('products.deleted_at')
                ->exists();
        }

        return true;
    }

    /**
     * Можно ли удалить атрибут из семейства (если ни один товар семейства его не использует).
     */
    public function canDeleteAttributeFromFamily(int $familyId, int $attributeId): bool
    {
        if (!Schema::hasTable('product_attributes') || !Schema::hasTable('categories')) {
            return true;
        }

        $query = DB::table('product_attributes')
            ->join('products', 'product_attributes.product_id', '=', 'products.id');

        if (Schema::hasTable('category_product')) {
            $query->join('category_product', 'products.id', '=', 'category_product.product_id')
                ->join('categories', 'category_product.category_id', '=', 'categories.id');
        } elseif (Schema::hasColumn('products', 'category_id')) {
            $query->join('categories', 'products.category_id', '=', 'categories.id');
        } else {
            return true;
        }

        return !$query
            ->where('categories.attribute_family_id', $familyId)
            ->where('product_attributes.attribute_id', $attributeId)
            ->whereNull('products.deleted_at')
            ->exists();
    }
}
