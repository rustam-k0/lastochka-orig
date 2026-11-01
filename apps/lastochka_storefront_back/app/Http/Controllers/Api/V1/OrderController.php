<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\CreateOrderAction;
use App\Actions\Order\SupplementOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\CheckSupplementRequest;
use App\Http\Requests\Api\V1\Order\CreateOrderRequest;
use App\Http\Requests\Api\V1\Order\SupplementOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\Order\DraftOrderPaymentSync;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Orders', weight: 6)]
final class OrderController extends Controller
{
    /**
     * Список заказов
     *
     * Возвращает историю заказов текущего пользователя с пагинацией.
     *
     * @response array{hasMore: bool, data: OrderResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (max: 100)', type: 'int', default: 20, example: 20)]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $orders = Order::query()
            ->forUser($request->user())
            // Черновики неподтверждённой оплаты и брошенные на странице ЮKassa
            // заказы в историю не попадают — клиент их не оформлял.
            ->visibleToClient()
            ->with(['store', 'items.product', 'items.originProduct.images', 'items.supplements.supplement', 'items.supplements.values', 'items.childItems.sourceSupplementValue.product.images', 'address', 'promocode', 'bonus', 'declinedHoldTransaction'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'hasMore' => $orders->hasMorePages(),
            'data' => OrderResource::collection($orders),
            'total' => $orders->total(),
        ]);
    }

    /**
     * Создать заказ
     *
     * Создаёт заказ из товаров корзины для указанного магазина.
     * Товары резервируются, корзина очищается для этого магазина.
     *
     * Если выбрана безналичная оплата, в ответе возвращается `confirmationUrl`
     * для редиректа в платёжный шлюз. Для оплаты при получении `confirmationUrl` = null.
     *
     * При включённом модуле `draft_orders_until_payment` оплата новой картой
     * создаёт заказ черновиком (`status` = `draft`): корзина сохраняется,
     * промокод не списывается, в работу заказ не уходит. Настоящим он станет
     * после подтверждения платежа — статус отслеживается через
     * `GET /orders/{order}/payment-status`. Если клиент не завершил оплату,
     * черновик закрывается сам, а корзина остаётся нетронутой.
     * Ответ ручки при этом прежний: `orderId` + `confirmationUrl`.
     *
     * `returnUrl` — куда вернуть клиента после оплаты. Адрес проверяется по
     * белому списку доменов, недопустимый — 422 с ключом `errors.returnUrl`
     * (заказ при этом не создаётся). Не передан — адрес по умолчанию из настроек.
     * Возврат на этот адрес не означает успешную оплату: финальный статус
     * приходит вебхуком, клиент обязан перечитать заказ.
     *
     * Повтор с тем же `idempotencyKey` возвращает ранее созданный заказ, `confirmationUrl` = null.
     *
     * При отказе банка по сохранённой карте (pre-auth) заказ не создаётся, ответ 422
     * с машинным кодом причины `reason` (напр. `insufficient_funds`) и текстом `message`
     * (`error` = `payment_declined`). Клиент идентифицирует кейс «недостаточно средств»
     * по `reason`, а не по тексту. В том же формате `{error, message}` приходят отказы
     * `bonus_spend_failed`, `insufficient_bonus_balance` и `yandex_delivery_unavailable`.
     * Остальные ошибки оформления (пустая корзина, минимальная сумма, остатки, слот,
     * самовывоз, товар «только самовывоз») возвращаются в формате `{message, errors}`,
     * недоступный магазин для B2C-клиента — 403.
     */
    #[ScrambleResponse(201, type: 'array{orderId: int, confirmationUrl: string|null}')]
    #[ScrambleResponse(422, description: 'Отказ оплаты или бонусов: error = payment_declined | bonus_spend_failed | insufficient_bonus_balance | yandex_delivery_unavailable', type: 'array{error: string, reason: string|null, message: string}')]
    public function store(
        CreateOrderRequest $request,
        CreateOrderAction $action,
    ): JsonResponse {
        $result = $action->execute(
            CreateOrderData::fromRequest($request)
        );

        // MENDEV-7: оформление заказа = согласие с перс. данными и офертой
        app(\App\Services\Consent\ConsentService::class)->record(
            $request->user(),
            [\App\Enums\ConsentDocument::PersonalData, \App\Enums\ConsentDocument::PublicOffer],
            \App\Enums\ConsentContext::Order,
            ip: \App\Support\ClientIp::fromRequest($request),
            meta: ['order_id' => $result['orderId'] ?? null],
        );

        return response()->json($result, 201);
    }

    /**
     * Детали заказа
     *
     * Возвращает полную информацию о заказе, включая товары и адрес доставки.
     *
     * Заказ в статусе `draft` — оформленный, но не подтверждённый онлайн-платёж
     * (модуль draft_orders_until_payment). Перед ответом его состояние сверяется
     * с платёжным шлюзом, поэтому клиент, вернувшийся со страницы оплаты раньше
     * вебхука, сразу получает актуальный статус.
     */
    public function show(Request $request, Order $order, DraftOrderPaymentSync $draftSync): OrderResource
    {
        $this->authorize('view', $order);

        if ($order->status === OrderStatus::Draft) {
            $order = $draftSync->sync($order);
        }

        $order->load(['store', 'items.product', 'items.originProduct.images', 'items.supplements.supplement', 'items.supplements.values', 'items.childItems.sourceSupplementValue.product.images', 'address', 'promocode', 'bonus', 'declinedHoldTransaction']);

        return new OrderResource($order);
    }

    /**
     * Статус оплаты заказа
     *
     * Лёгкая ручка для поллинга после возврата со страницы оплаты: сверяет
     * состояние платежа со шлюзом и отдаёт результат без состава заказа.
     *
     * `paymentState`:
     *  - `awaiting` — оплата ещё не подтверждена, заказ остаётся черновиком
     *    (клиенту показывать ожидание, ссылку на повторную оплату брать в
     *    `POST /orders/{order}/pay`);
     *  - `confirmed` — холд подтверждён, заказ оформлен и уехал в работу;
     *  - `failed` — оплата не состоялась, черновик закрыт, товары возвращены
     *    в остаток, корзина клиента при этом сохранена;
     *  - `cancelled` — заказ был оформлен и отменён уже после оплаты.
     *
     * @response array{orderId: int, status: string, paymentState: string, isPaid: bool, awaitingPaymentUntil: string|null}
     */
    public function paymentStatus(
        Request $request,
        Order $order,
        DraftOrderPaymentSync $draftSync,
    ): JsonResponse {
        $this->authorize('view', $order);

        if ($order->status === OrderStatus::Draft) {
            $order = $draftSync->sync($order);
        }

        $paymentState = match (true) {
            $order->status === OrderStatus::Draft => 'awaiting',
            // Черновик, умерший неоплаченным: awaiting_payment_until остаётся
            // заполненным именно как маркер такого исхода.
            $order->status === OrderStatus::Cancelled && $order->awaiting_payment_until !== null => 'failed',
            $order->status === OrderStatus::Cancelled => 'cancelled',
            default => 'confirmed',
        };

        return response()->json([
            'orderId' => (int) $order->id,
            'status' => $order->status->value,
            'paymentState' => $paymentState,
            'isPaid' => (bool) $order->is_paid,
            'awaitingPaymentUntil' => $order->status === OrderStatus::Draft && $order->awaiting_payment_until
                ? $order->awaiting_payment_until->toIso8601String()
                : null,
        ]);
    }

    /**
     * Отменить заказ
     *
     * Отменяет заказ, если он ещё может быть отменён (статусы pending и assembling).
     * Зарезервированные товары возвращаются в наличие, бонусы возвращаются на баланс,
     * при оплате картой списанные средства возвращаются рефандом, а незакрытый холд снимается.
     *
     * Права проверяются по владельцу заказа: чужой заказ — 403. Недопустимый статус
     * (в том числе гонка) — 422 с ключом `errors.status`; прибывший курьер Яндекс-доставки —
     * 422 с ключом `errors.order`.
     */
    #[ScrambleResponse(422, description: 'Статус заказа не допускает отмену либо курьер Яндекс-доставки уже прибыл', type: 'array{message: string, errors: array<string, string[]>}')]
    public function cancel(
        Request $request,
        Order $order,
        CancelOrderAction $action,
    ): OrderResource {
        $this->authorize('view', $order);

        $order = $action->execute($order);

        $order->load(['store', 'items.product', 'items.originProduct.images', 'items.supplements.supplement', 'items.supplements.values', 'items.childItems.sourceSupplementValue.product.images', 'address', 'promocode', 'bonus', 'declinedHoldTransaction']);

        return new OrderResource($order);
    }

    /**
     * Проверить возможность дополнения
     *
     * Проверяет наличие заказа в статусе pending или assembling для указанного магазина,
     * время слота доставки которого (если слот задан) ещё не истекло.
     * Возвращается последний по дате создания подходящий заказ.
     *
     * Snake_case `store_id` принимается как устаревший алиас `storeId`.
     *
     * @response array{order_id: int|null, can_supplement: bool}
     */
    #[QueryParameter('storeId', description: 'ID магазина', type: 'int', required: true, example: 1)]
    public function checkSupplement(CheckSupplementRequest $request): JsonResponse
    {
        $order = Order::query()
            ->forUser($request->user())
            ->visibleToClient()
            ->where('store_id', $request->validated('storeId'))
            ->whereIn('status', OrderStatus::supplementable())
            ->deliverySlotNotExpired()
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'order_id' => $order?->id,
            'can_supplement' => $order !== null,
        ]);
    }

    /**
     * Дополнить заказ
     *
     * Добавляет товары из корзины к существующему заказу в статусе pending или assembling.
     * Товары резервируются, корзина очищается для этого магазина.
     *
     * Ключ идемпотентности принимается как `idempotencyKey`; snake_case `idempotency_key` —
     * устаревший алиас. Повтор с тем же ключом в течение 24 часов возвращает заказ без
     * повторного добавления товаров.
     *
     * Чужой заказ, статус, не допускающий дополнения, либо истёкшее время слота
     * доставки, — 403. Если статус или слот изменились между проверкой прав и
     * транзакцией (гонка) — 409 с ключом `errors.status` либо `errors.deliverySlot`.
     */
    #[ScrambleResponse(409, description: 'Статус заказа изменился либо истёк слот доставки между проверкой прав и транзакцией', type: 'array{message: string, errors: array<string, string[]>}')]
    #[ScrambleResponse(422, description: 'Корзина пуста, не хватает остатка либо товар доступен только для самовывоза', type: 'array{message: string, errors: array<string, string[]>}')]
    public function supplement(
        SupplementOrderRequest $request,
        Order $order,
        SupplementOrderAction $action,
    ): OrderResource {
        $this->authorize('supplement', $order);

        $order = $action->execute(
            $order,
            $request->user(),
            $request->validated('idempotencyKey'),
        );

        return new OrderResource($order);
    }

    /**
     * Получить последний активный заказ
     *
     * Возвращает последний заказ пользователя, который не отменён и не доставлен,
     * а также общее количество активных заказов (totalActive).
     * Если активного заказа нет, возвращается null с totalActive = 0.
     *
     * @response array{data: OrderResource|null, totalActive: int}
     */
    public function getActive(Request $request): JsonResponse
    {
        $activeQuery = Order::query()
            ->forUser($request->user())
            ->visibleToClient()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Delivered]);

        $totalActive = $activeQuery->count();

        $order = $activeQuery
            ->orderByDesc('created_at')
            ->with(['store', 'items', 'address', 'promocode', 'bonus', 'declinedHoldTransaction'])
            ->first();

        return response()->json([
            'data' => $order ? new OrderResource($order) : null,
            'totalActive' => $totalActive,
        ]);
    }

    /**
     * Повторная ссылка на оплату
     *
     * Создаёт новый холд, если предыдущий истёк.
     *
     * При отказе банка — 422 с машинным кодом причины `reason`
     * (напр. `insufficient_funds`) для идентификации кейса клиентом.
     *
     * `returnUrl` — куда вернуть клиента после оплаты. Адрес проверяется по
     * белому списку доменов, недопустимый — 422 с ключом `errors.returnUrl`.
     * Не передан — адрес по умолчанию из настроек.
     *
     * @response array{confirmationUrl: string|null}
     */
    #[ScrambleResponse(422, description: 'Платёж не создан (reason — машинный код отказа банка) либо недопустимый адрес возврата', type: 'array{message: string, reason: string|null}')]
    public function pay(
        \App\Http\Requests\Api\V1\Payment\PayOrderRequest $request,
        Order $order,
        \App\Services\Payment\PaymentService $paymentService,
    ): JsonResponse {
        $this->authorize('view', $order);

        $result = $paymentService->retryPayment($order, $request->returnUrl());

        if (!$result->success) {
            return response()->json([
                'message' => $result->errorMessage ?? 'Не удалось создать платёж',
                // Машинный код причины отказа — клиент опознаёт кейс по коду, не по тексту.
                'reason' => $result->declineReason,
            ], 422);
        }

        return response()->json([
            'confirmationUrl' => $result->confirmationUrl,
        ]);
    }

    /**
     * Возврат по заказу
     *
     * Создаёт возврат на указанную сумму по оплаченному заказу.
     * Если возврат невозможен — 422 с текстом причины от платёжного сервиса
     * (`message` может быть null, если сервис причину не вернул).
     *
     * @response array{success: bool, message: string}
     */
    #[ScrambleResponse(422, description: 'Возврат невозможен: message — причина от платёжного сервиса', type: 'array{message: string|null}')]
    public function refund(
        \App\Http\Requests\Api\V1\Payment\RefundOrderRequest $request,
        Order $order,
        \App\Services\Payment\PaymentService $paymentService,
    ): JsonResponse {
        $this->authorize('view', $order);

        $result = $paymentService->refundPayment($order, (float) $request->validated('amount'));

        if (!$result->success) {
            return response()->json([
                'message' => $result->errorMessage,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Возврат создан',
        ]);
    }

}
