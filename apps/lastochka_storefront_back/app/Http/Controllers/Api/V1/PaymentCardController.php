<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\BindPaymentCardRequest;
use App\Http\Resources\Api\V1\PaymentCardResource;
use App\Models\PaymentCard;
use App\Services\Payment\PaymentCardSync;
use App\Services\Payment\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Payment Cards', weight: 7)]
final class PaymentCardController extends Controller
{
    /**
     * Список карт пользователя
     *
     * Сортировка: сначала карта по умолчанию, затем по дате создания по убыванию.
     *
     * Перед ответом с платёжным шлюзом сверяются незавершённые привязки карт
     * и оплаты заказов новой картой: карта, подтверждённая на странице ЮKassa,
     * попадает в список при первом же запросе после возврата клиента, не
     * дожидаясь уведомления шлюза. Пока клиент не подтвердил карту, её в
     * списке нет.
     *
     * @response array{data: PaymentCardResource[]}
     */
    public function index(Request $request, PaymentCardSync $cardSync): JsonResponse
    {
        $cardSync->syncForUser($request->user());

        $cards = PaymentCard::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => PaymentCardResource::collection($cards),
        ]);
    }

    /**
     * Удалить карту
     *
     * Мягкое удаление. Карта другого пользователя: 403.
     */
    #[ScrambleResponse(403, description: 'Карта принадлежит другому пользователю', type: 'array{message: string}')]
    public function destroy(Request $request, PaymentCard $paymentCard): \Illuminate\Http\Response
    {
        if ($paymentCard->user_id !== $request->user()->id) {
            abort(403);
        }

        $paymentCard->delete();

        return response()->noContent();
    }

    /**
     * Привязать карту (холд 1₽)
     *
     * Создаётся холд 1₽ по новой карте. После подтверждения холда карта
     * сохраняется пользователю, а холд снимается: списания не происходит.
     * `confirmationUrl` отдаётся как есть из ответа ЮKassa и может быть null.
     * После возврата клиента со страницы ЮKassa карту брать из
     * `GET /payment-cards`: список сам сверяет привязку со шлюзом.
     *
     * `returnUrl` — куда вернуть клиента после подтверждения карты. Адрес
     * проверяется по белому списку доменов, недопустимый — 422 с ключом
     * `errors.returnUrl`. Не передан — адрес по умолчанию из настроек.
     *
     * @response array{confirmationUrl: string|null}
     */
    #[ScrambleResponse(422, description: 'Не удалось создать платёж привязки карты либо недопустимый адрес возврата', type: 'array{message: string}')]
    public function bind(BindPaymentCardRequest $request, PaymentService $paymentService): JsonResponse
    {
        $result = $paymentService->bindCard($request->user(), $request->returnUrl());

        if (!$result->success) {
            return response()->json([
                'message' => $result->errorMessage ?? 'Не удалось привязать карту',
            ], 422);
        }

        return response()->json([
            'confirmationUrl' => $result->confirmationUrl,
        ]);
    }
}
