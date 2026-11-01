<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeliveryPriceRanges;
use App\Models\Address;
use App\Models\Store;
use App\Services\Delivery\DeliveryService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Group('Delivery', weight: 7)]
final class DeliveryCalculateController extends Controller
{
    /**
     * Рассчитать стоимость доставки
     *
     * Рассчитывает стоимость и параметры доставки для указанного способа.
     *
     * - `own_courier` — использует механизм слотов (как `/v1/stores/{store}/delivery-slots`),
     *   но всегда на сегодняшнюю дату. Требует `shopId` и `addressId`. Ошибки зоны
     *   (`zone_not_found`, 422) и недоступности сервиса доставки (503) отдаются так же,
     *   как в `/v1/stores/{store}/delivery-slots`.
     * - `cdek` / `russian_post` — требует `externalId` и возвращает тарифы точки выдачи.
     *   Если передан `tariffCode`, список фильтруется по нему; при отсутствии совпадений
     *   возвращается пустой список, а не ошибка.
     *
     * @throws ValidationException
     */
    #[ScrambleResponse(200, type: 'array{data: array{shippingMethod: string, deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom: int|null, slots: array<array{id: int, timeSlot: string}>}}|array{data: array{shippingMethod: string, tariffs: array<array<string, mixed>>}}', description: 'Расчёт для own_courier либо список тарифов для cdek/russian_post')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Магазин или адрес не найден (только own_courier)')]
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, array<string>>}|array{error: string, message: string}', description: 'Ошибка валидации, отсутствие `addressId` для own_courier или `externalId` для cdek/russian_post, недоступная зона доставки (`error: zone_not_found`)')]
    #[ScrambleResponse(503, type: 'array{message: string}', description: 'Сервис доставки недоступен (только own_courier)')]
    #[QueryParameter('shippingMethod', description: 'Способ доставки: own_courier|cdek|russian_post', type: 'string', required: true, example: 'cdek')]
    #[QueryParameter('shopId', description: 'ID магазина', type: 'int', required: true, example: 1)]
    #[QueryParameter('externalId', description: 'Код ПВЗ СДЭК или индекс ОПС. Обязателен для cdek/russian_post', type: 'string', required: false, example: 'MKHCH19')]
    #[QueryParameter('tariffCode', description: 'Код тарифа для фильтрации списка (для cdek/russian_post). Без него возвращаются все тарифы точки', type: 'string', required: false, example: 'TARIFF_137')]
    #[QueryParameter('addressId', description: 'ID адреса доставки. Обязателен для own_courier', type: 'int', required: false, example: 5)]
    public function index(Request $request, SlotsApiClientInterface $client, DeliveryService $deliveryService): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'shippingMethod' => ['required', 'string', 'in:own_courier,cdek,russian_post'],
            'shopId' => ['required', 'integer', 'min:1'],
            'externalId' => ['nullable', 'string', 'max:64'],
            'tariffCode' => ['nullable', 'string', 'max:64'],
            'addressId' => ['nullable', 'integer', 'min:1'],
        ], [
            'shippingMethod.required' => 'Укажите способ доставки',
            'shippingMethod.in' => 'Способ доставки должен быть own_courier, cdek или russian_post',
            'shopId.required' => 'Укажите ID магазина',
        ])->validate();

        $shippingMethod = $validated['shippingMethod'];
        $shopId = (int) $validated['shopId'];

        if ($shippingMethod === 'own_courier') {
            return $this->calculateOwnCourier(
                $request,
                $validated,
                $deliveryService,
            );
        }

        return $this->calculateThirdPartyTariff(
            $shippingMethod,
            $shopId,
            $validated,
            $client,
        );
    }

    /**
     * Расчёт для курьерской доставки магазина.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function calculateOwnCourier(
        Request $request,
        array $validated,
        DeliveryService $deliveryService,
    ): JsonResponse {
        if (empty($validated['addressId'])) {
            throw ValidationException::withMessages([
                'addressId' => ['Для own_courier необходимо указать addressId'],
            ]);
        }

        $store = Store::find((int) $validated['shopId']);

        if (! $store) {
            return response()->json(['message' => 'Магазин не найден'], 404);
        }

        $address = Address::find((int) $validated['addressId']);

        if (! $address) {
            return response()->json(['message' => 'Адрес не найден'], 404);
        }

        $products = $request->user()
            ? app(\App\Services\Delivery\CartProductsMapper::class)->mapUserStoreItems($request->user(), $store->id)
            : [];

        $info = $deliveryService->getDeliveryInfo($store, $address, now()->toDateString(), $products);

        return response()->json([
            'data' => [
                'shippingMethod' => 'own_courier',
                'deliveryPrice' => $info->deliveryPrice,
                'minOrderAmount' => $info->minOrderAmount,
                'freeDeliveryFrom' => $info->freeDeliveryFrom,
                // Ступени стоимости доставки зоны
                'deliveryPriceRanges' => DeliveryPriceRanges::ranges($info->ladder()->ranges()),
                'slots' => collect($info->slots)->map(fn ($slot) => [
                    'id' => $slot->id,
                    'timeSlot' => $slot->timeSlot,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * Расчёт для СДЭК или Почты России — возвращает конкретный тариф по tariffCode.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function calculateThirdPartyTariff(
        string $shippingMethod,
        int $shopId,
        array $validated,
        SlotsApiClientInterface $client,
    ): JsonResponse {
        $externalId = $validated['externalId'] ?? null;
        $tariffCode = $validated['tariffCode'] ?? null;

        if (! $externalId) {
            throw ValidationException::withMessages([
                'externalId' => ['Для ' . $shippingMethod . ' необходимо указать externalId (код ПВЗ/индекс ОПС)'],
            ]);
        }

        $tariffs = match ($shippingMethod) {
            'cdek' => $client->getCdekTariffs([
                'shopId' => $shopId,
                'code' => $externalId,
            ]),
            'russian_post' => $client->getRpTariffs([
                'shopId' => $shopId,
                'index' => $externalId,
            ]),
            default => [],
        };

        if ($tariffCode !== null) {
            $tariffs = array_values(
                array_filter($tariffs, fn (mixed $t) => is_array($t) && ($t['code'] ?? null) === $tariffCode)
            );
        }

        return response()->json([
            'data' => [
                'shippingMethod' => $shippingMethod,
                'tariffs' => $tariffs,
            ],
        ]);
    }
}
