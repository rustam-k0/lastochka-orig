<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Pickup\PickupSlotService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Pickup', weight: 3)]
final class PickupSlotController extends Controller
{
    /**
     * Слоты самовывоза
     *
     * Возвращает доступные временные слоты самовывоза для указанного магазина
     * на выбранную дату. Длительность слота равна `slot_duration` магазина
     * (по умолчанию 60 минут), разделитель в `timeSlot` — короткое тире `–`.
     * Прошедшие слоты (для сегодняшнего дня) и слоты раньше готовности товаров
     * корзины не возвращаются. Пустой список слотов отдаётся со статусом 200.
     *
     * `minOrderAmount` берётся из `min_pickup_order_amount` магазина, при его
     * отсутствии — из `min_order_amount`, иначе 0.
     */
    #[ScrambleResponse(200, type: 'array{data: array{slots: array<array{id: int, timeSlot: string}>, minOrderAmount: int}}', description: 'Слоты самовывоза на выбранную дату')]
    #[ScrambleResponse(404, description: 'Магазин не найден или неактивен')]
    #[ScrambleResponse(422, type: 'array{error: string, message: string}|array{message: string, errors: array<string, array<string>>}', description: 'Самовывоз недоступен для магазина (`error: pickup_not_available`) либо ошибка валидации `date`')]
    #[QueryParameter('date', description: 'Дата для получения слотов (формат Y-m-d)', type: 'string', required: true, example: '2026-04-10')]
    public function __invoke(
        Request $request,
        Store $store,
        PickupSlotService $pickupSlotService,
    ): JsonResponse {
        $request->validate([
            /**
             * Дата для получения слотов.
             *
             * @example 2026-04-10
             */
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $store = Store::query()->active()->findOrFail($store->getKey());

        if (! $store->is_pickup_enabled) {
            return response()->json([
                'error'   => 'pickup_not_available',
                'message' => 'Самовывоз недоступен для этого магазина',
            ], 422);
        }

        // Fixed-готовность ежедневная: резолвим её на запрошенную дату,
        // чтобы утро будущих дней тоже фильтровалось (как в slots-api).
        $onDate = \Carbon\Carbon::createFromFormat(
            'Y-m-d',
            $request->input('date'),
            config('app.timezone'),
        )->startOfDay();

        $readyFrom = $request->user()
            ? app(\App\Services\ReadyTime\ReadyTimeResolver::class)->forUserStore($request->user(), $store->id, $onDate)
            : null;

        $slots = $pickupSlotService->getSlots($store, $request->input('date'), $readyFrom);

        $minOrderAmount = $store->min_pickup_order_amount ?? $store->min_order_amount ?? 0;

        return response()->json([
            'data' => [
                'slots'          => $slots,
                'minOrderAmount' => $minOrderAmount,
            ],
        ]);
    }
}
