<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Delivery\GetDeliverySlotsRequest;
use App\Http\Resources\Api\V1\DeliveryInfoResource;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\CartProductsMapper;
use App\Services\Delivery\DeliveryService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;

#[Group('Delivery', weight: 7)]
final class DeliveryController extends Controller
{
    /**
     * Слоты доставки
     *
     * Возвращает доступные слоты доставки для указанного магазина и адреса.
     * Адрес должен принадлежать текущему пользователю и иметь корректные координаты
     * (координаты 0,0 считаются некорректными).
     *
     * Слоты запрашиваются с учётом состава корзины пользователя по этому магазину:
     * слоты, на которые не успевает сборка, не возвращаются. Пустой список слотов
     * отдаётся со статусом 200.
     *
     * По умолчанию параметр `date` = сегодня.
     */
    // Тип задан явно: автовывод по DeliveryInfoResource ошибается на двух полях —
    // availablePaymentMethods описывался кортежем ровно из ["card", "cash"] (вариант
    // ["card"] для зоны Яндекса терялся), а yandexDelivery получал шесть строковых
    // полей и попадал в required, хотя блок условный.
    #[ScrambleResponse(200, type: 'array{slots: array<array{id: int, timeSlot: string, date: string}>, minOrderAmount: int, freeDeliveryFrom: int|null, deliveryPrice: int, availablePaymentMethods: array<string>, yandexDelivery?: array{available: bool, price: int|null, eta: float|null, description: string|null, availableSlots: array<array{slotId: int, start: string, end: string}>, maxDueHours: int}}', description: 'Информация о слотах доставки для выбранного магазина и адреса. Ответ отдаётся без обёртки `data`. Поле `availablePaymentMethods` равно `["card", "cash"]` для собственной доставки и `["card"]` для зоны Яндекс-доставки; блок `yandexDelivery` присутствует только для зоны Яндекса')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, array<string>>}|array{error: string, message: string}', description: 'Ошибка валидации `addressId`/`date`, отсутствие корректных координат у адреса (`errors.addressId`) либо недоступность зоны доставки (`error: zone_not_found`)')]
    #[ScrambleResponse(503, type: 'array{message: string}', description: 'Сервис доставки недоступен, и в кеше нет данных для отката')]
    #[QueryParameter('addressId', description: 'ID адреса доставки (с координатами), принадлежащего текущему пользователю', type: 'int', required: true, example: 1)]
    #[QueryParameter('date', description: 'Дата для получения слотов (формат Y-m-d). По умолчанию — сегодня.', type: 'string', required: false, example: '2026-04-15')]
    public function slots(
        GetDeliverySlotsRequest $request,
        Store $store,
        DeliveryService $deliveryService,
        CartProductsMapper $cartProductsMapper,
    ): DeliveryInfoResource|JsonResponse {
        $address = Address::findOrFail($request->validated('addressId'));

        $hasCoords = $address->latitude !== null
            && $address->longitude !== null
            && ((float) $address->latitude !== 0.0 || (float) $address->longitude !== 0.0);

        if (! $hasCoords) {
            return response()->json([
                'message' => 'У адреса отсутствуют корректные координаты. Укажите адрес с координатами.',
                'errors' => [
                    'addressId' => ['У адреса отсутствуют корректные координаты.'],
                ],
            ], 422);
        }

        $info = $deliveryService->getDeliveryInfo(
            $store,
            $address,
            $request->resolvedDate(),
            $cartProductsMapper->mapUserStoreItems($request->user(), $store->id),
        );

        return new DeliveryInfoResource($info);
    }
}
