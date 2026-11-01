<?php

namespace App\GraphQL\Mutations\Category;

use App\Models\Category;
use App\Models\Type;
use App\Services\Quantum\CategoryQuantumService;
use App\Services\Quantum\UnitFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Установка/снятие кванта продажи на витринной листовой категории
 * (LASTOCHKI-JAMI-11200), адаптивно по единицам: грамм / мл / штука.
 *
 * Квант задаётся строго на листовых категориях. После сохранения
 * пересчитываются цены товаров категории совпадающего семейства (цена за квант
 * от нормализованной цены 1С) и публикуются в storefront.
 */
final class SetCategoryQuantum
{
    public const MAX_QUANTUM_MASS_VOLUME = 100000; // грамм / мл
    public const MAX_QUANTUM_PIECE = 10000;        // штук

    /**
     * @param  null  $_
     * @param  array{category_id: int, quantum_step?: int|null, quantum_unit_type_id?: int|null}  $args
     */
    public function __invoke($_, array $args): Category
    {
        $category = Category::find($args['category_id']);

        if (!$category) {
            throw ValidationException::withMessages([
                'category_id' => ['Категория не найдена.'],
            ]);
        }

        if ($category->childCategories()->exists()) {
            throw ValidationException::withMessages([
                'category_id' => [
                    sprintf('Квант задаётся только на листовых категориях, «%s» имеет подкатегории.', $category->title),
                ],
            ]);
        }

        $step = $args['quantum_step'] ?? null;
        $unit = $args['quantum_unit_type_id'] ?? null;

        // Снятие кванта — шаг null.
        if ($step === null) {
            DB::transaction(fn () => $category->forceFill([
                'quantum_step' => null,
                'quantum_unit_type_id' => null,
            ])->save());

            app(CategoryQuantumService::class)->recalcCategory($category->fresh());

            return $category->fresh();
        }

        if ($unit === null || !in_array((int) $unit, UnitFamily::allowedQuantumUnits(), true)) {
            throw ValidationException::withMessages([
                'quantum_unit_type_id' => ['Единица кванта должна быть: грамм или штука.'],
            ]);
        }

        $max = (int) $unit === Type::INFO_WEIGHT_PIECE ? self::MAX_QUANTUM_PIECE : self::MAX_QUANTUM_MASS_VOLUME;

        if ($step < 1 || $step > $max) {
            throw ValidationException::withMessages([
                'quantum_step' => [
                    sprintf('Размер кванта должен быть от 1 до %d.', $max),
                ],
            ]);
        }

        DB::transaction(fn () => $category->forceFill([
            'quantum_step' => $step,
            'quantum_unit_type_id' => (int) $unit,
        ])->save());

        // Пересчёт цен товаров категории совпадающего семейства (вне транзакции —
        // публикует ProductUpdated на каждый товар → outbox → storefront).
        app(CategoryQuantumService::class)->recalcCategory($category->fresh());

        return $category->fresh();
    }
}
