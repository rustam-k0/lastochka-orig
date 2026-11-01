<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\CancelClientOrderAction;
use App\Actions\Order\CreateClientOrderAction;
use App\DTOs\Order\CreateClientOrderData;
use App\Enums\ShippingMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\CreateClientOrderRequest;
use App\Http\Resources\Api\V1\ClientOrderResource;
use App\Models\Order;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

#[Group('Client Orders (marketplace)', weight: 6)]
final class ClientOrderController extends Controller
{
    /**
     * Eager-load relations нужные ClientOrderResource'у.
     *
     * Загружаются один раз и используются во всех методах контроллера, чтобы
     * избежать N+1 в `index()` (где итерация по client_order_id'ам).
     *
     * @var array<int, string>
     */
    private const SHOW_RELATIONS = [
        'items.product.images',
        'items.originProduct.images',
        'items.supplements',
        'address',
        'promocode',
        'bonus',
        'store',
        'paymentCard',
    ];

    public function __construct(
        private readonly CreateClientOrderAction $createClientOrderAction,
        private readonly CancelClientOrderAction $cancelClientOrderAction,
    ) {
    }

    /**
     * Список клиентских заказов пользователя (marketplace режим).
     *
     * Заказы с непустым `client_order_id` группируются по нему и отдаются
     * агрегатами в порядке убывания id.
     *
     * @response array{data: ClientOrderResource[]}
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $orders = Order::query()
            ->where('user_id', $userId)
            ->whereNotNull('client_order_id')
            ->with(self::SHOW_RELATIONS)
            ->orderByDesc('id')
            ->get();

        $byClientOrder = $orders->groupBy(fn (Order $o) => (string) $o->client_order_id);

        $data = $byClientOrder
            ->map(fn (Collection $group, string $clientOrderId): array => (new ClientOrderResource($clientOrderId, $group))
                ->toArray($request))
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    /**
     * Создать клиентский заказ (marketplace режим).
     *
     * Корзина делится на sub-order'ы по магазинам, все они получают общий `clientOrderId`.
     * Ответ отдаётся со статусом 200. `confirmationUrl` заполняется при `paymentType` = card
     * (консолидированный холд YooKassa на сумму всех sub-order'ов); для `cash` и для
     * идемпотентного повтора — null. `consolidatedStatus` считается по правилу
     * «минимальный прогресс» среди sub-order'ов.
     *
     * Ошибка при создании любого sub-order'а откатывает весь клиентский заказ.
     *
     * `returnUrl` — куда вернуть клиента после оплаты. Адрес проверяется по
     * белому списку доменов, недопустимый — 422 с ключом `errors.returnUrl`.
     * Не передан — адрес по умолчанию из настроек.
     *
     * @response array{clientOrderId: string, subOrderIds: int[], totalAmount: float, consolidatedStatus: string, confirmationUrl: string|null}
     */
    #[ScrambleResponse(422, description: 'Недоступный способ доставки или оплаты, отсутствующий тариф, пустая корзина либо невозможное списание бонусов', type: 'array{message: string, errors: array<string, string[]>}')]
    public function store(CreateClientOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $shippingMethod = ShippingMethod::from(
            (string) ($validated['shippingMethod'] ?? ShippingMethod::Courier->value),
        );

        $shippingTariffCodes = $validated['shippingTariffCodes'] ?? null;
        if (is_array($shippingTariffCodes)) {
            $shippingTariffCodes = array_map(
                static fn ($v) => (string) $v,
                $shippingTariffCodes,
            );
        } elseif ($shippingTariffCodes !== null) {
            $shippingTariffCodes = (string) $shippingTariffCodes;
        }

        $data = new CreateClientOrderData(
            user: $request->user(),
            addressId: (int) $validated['addressId'],
            paymentType: (string) $validated['paymentType'],
            deliverySlots: array_map(
                fn (array $slot): array => [
                    'storeId' => (int) $slot['storeId'],
                    'slotId' => (int) $slot['slotId'],
                ],
                (array) ($validated['deliverySlots'] ?? []),
            ),
            promocodeIds: array_map(
                fn ($id) => (int) $id,
                (array) ($validated['promocodeIds'] ?? []),
            ),
            comment: $validated['comment'] ?? null,
            idempotencyKey: $validated['idempotencyKey'] ?? null,
            paymentCardId: isset($validated['paymentCardId']) ? (int) $validated['paymentCardId'] : null,
            shippingMethod: $shippingMethod,
            shippingTariffCodes: $shippingTariffCodes,
            bonusAction: $validated['bonusAction'] ?? null,
            bonusSpendAmount: isset($validated['bonusSpendAmount']) ? (int) $validated['bonusSpendAmount'] : null,
            returnUrl: $request->returnUrl(),
        );

        $result = $this->createClientOrderAction->execute($data);

        return response()->json($result);
    }

    /**
     * Агрегированный просмотр клиентского заказа.
     *
     * Ответ отдаётся без обёртки `data`. Если клиентского заказа нет или он
     * принадлежит другому пользователю — 404 с телом `{"error": "not_found"}`.
     *
     * @response ClientOrderResource
     */
    #[ScrambleResponse(404, description: 'Клиентский заказ не найден либо принадлежит другому пользователю', type: 'array{error: string}')]
    public function show(Request $request, string $clientOrderId): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('client_order_id', $clientOrderId)
            ->with(self::SHOW_RELATIONS)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $payload = (new ClientOrderResource($clientOrderId, $orders))->toArray($request);

        return response()->json($payload);
    }

    /**
     * Полная отмена клиентского заказа пользователем (marketplace режим).
     *
     * - отменяет все sub-orders (cancelSubOrder path, bonuses не трогает); sub-orders
     *   в терминальных статусах пропускаются
     * - освобождает бонусные резервы sub-orders
     * - агрегированный refund фактически списанных бонусов одной транзакцией
     * - отмена consolidated YooKassa hold (если был)
     *
     * Авторизация: sub-orders фильтруются по user_id в CancelClientOrderAction,
     * чужой client_order_id даст 404.
     *
     * @response array{status: string, clientOrderId: string}
     */
    #[ScrambleResponse(404, description: 'Клиентский заказ не найден либо принадлежит другому пользователю', type: 'array{message: string}')]
    public function destroy(Request $request, string $clientOrderId): JsonResponse
    {
        $this->cancelClientOrderAction->execute($clientOrderId, $request->user());

        return response()->json(['status' => 'cancelled', 'clientOrderId' => $clientOrderId]);
    }
}
