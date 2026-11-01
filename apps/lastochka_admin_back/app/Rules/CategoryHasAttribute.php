<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Attribute;
use App\Models\AttributeFamily;
use App\Models\Category;
use Illuminate\Contracts\Validation\Rule;

class CategoryHasAttribute implements Rule
{
    /**
     * @var list<int>
     */
    private array $categoryIds;

    /**
     * @param  int|array<int>|null  $categoryIds  Один id (legacy) или массив id (multi-category).
     */
    public function __construct($categoryIds)
    {
        if ($categoryIds === null) {
            $this->categoryIds = [];
        } elseif (is_array($categoryIds)) {
            $this->categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
        } else {
            $this->categoryIds = [(int) $categoryIds];
        }
    }

    public function passes($attribute, $value): bool
    {
        if ($this->categoryIds === []) {
            return true;
        }

        // Атрибут из системного семейства — всегда разрешён.
        $inSystemFamily = AttributeFamily::query()
            ->where('is_system', true)
            ->whereHas('attributes', fn($q) => $q->where('attributes.id', $value))
            ->exists();

        if ($inSystemFamily) {
            return true;
        }

        // Атрибут из семейства любой из назначенных категорий.
        $familyIds = Category::query()
            ->whereIn('id', $this->categoryIds)
            ->whereNotNull('attribute_family_id')
            ->pluck('attribute_family_id')
            ->unique()
            ->all();

        if ($familyIds !== []) {
            $inAnyFamily = AttributeFamily::query()
                ->whereIn('id', $familyIds)
                ->whereHas('attributes', fn($q) => $q->where('attributes.id', $value))
                ->exists();

            if ($inAnyFamily) {
                return true;
            }
        }

        // Fallback: legacy default-атрибут или атрибут привязанный к любой из категорий через withCategory.
        return Attribute::query()
            ->where(function ($query) {
                $query
                    ->where(function ($q) { $q->default(); })
                    ->orWhere(function ($q) {
                        foreach ($this->categoryIds as $categoryId) {
                            $q->orWhere(function ($qq) use ($categoryId) {
                                $qq->withCategory($categoryId);
                            });
                        }
                    });
            })
            ->whereId($value)
            ->exists();
    }

    public function message(): string
    {
        return 'Атрибут не принадлежит ни одной из выбранных категорий';
    }
}
