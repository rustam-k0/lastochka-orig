<?php

namespace App\Services;

use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;

class VacancyService
{
    /**
     * Перестановка вакансии на заданное место в списке (нумерация с 1).
     *
     * Порядок пересчитывается целиком и становится плотным (1..N) —
     * это снимает проблему пустых и повторяющихся позиций.
     * Обновление идёт по одной модели, чтобы сработал outbox-observer
     * и новые позиции доехали в storefront (bulk update событий не шлёт).
     */
    public function changePosition(Vacancy $vacancy, int $newPosition): Vacancy
    {
        DB::transaction(function () use ($vacancy, $newPosition) {
            $ordered = Vacancy::query()
                ->orderByRaw('position is null, position')
                ->orderBy('id')
                ->get();

            $ids = $ordered->pluck('id')->all();
            $currentIndex = array_search($vacancy->id, $ids, true);

            if ($currentIndex === false) {
                return;
            }

            array_splice($ids, $currentIndex, 1);

            $targetIndex = max(1, min($newPosition, count($ids) + 1)) - 1;
            array_splice($ids, $targetIndex, 0, [$vacancy->id]);

            foreach ($ids as $index => $id) {
                $model = $ordered->firstWhere('id', $id);
                $position = $index + 1;

                if ($model && $model->position !== $position) {
                    $model->update(['position' => $position]);
                }
            }
        });

        return $vacancy->fresh();
    }

    /**
     * Позиция для новой вакансии, если админ её не задал — в конец списка.
     */
    public function nextPosition(): int
    {
        return (int) Vacancy::max('position') + 1;
    }
}
