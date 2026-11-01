<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Payment\PaymentService;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Внутренний endpoint для delivery_back (модуль collector_payment):
 * синхронное списание итоговой стоимости заказа при завершении сборки.
 */
final class InternalOrderPaymentController extends Controller
{
    /**
     * Синхронное списание оплаты при завершении сборки
     *
     * Требует заголовок `X-Internal-Token` (middleware `internal.token`),
     * при отсутствии или несовпадении токена: 403. Ответ 200 отдаётся и когда
     * списание уже было выполнено ранее, и для заказа с оплатой наличными.
     * Ответ 402 означает отклонённую доплату (заказ ожидает доплату), 503:
     * доплата прошла или не требовалась, а capture холда не удался, либо
     * платёжный сервис недоступен по сети; повторный вызов безопасен.
     *
     * @response array{status: string, payment_status: string}
     */
    #[ScrambleResponse(402, description: 'Доплата отклонена, заказ ожидает доплату', type: 'array{status: string, message: string, surcharge_amount: float}')]
    #[ScrambleResponse(503, description: 'Capture холда не удался или платёжный сервис недоступен, повторный вызов безопасен', type: 'array{status: string, message: string}')]
    #[ScrambleResponse(403, description: 'Отсутствует или не совпадает X-Internal-Token', type: 'array{message: string}')]
    public function settle(Request $request, Order $order, PaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            // Payload order.updated курьерки по итогам сборки (тот же, что уйдёт
            // в событие). Позиции применяются ДО списания: capture сразу ставит
            // чек прихода, и без них чек строился по заказанным количествам.
            // Необязателен — delivery_back форков без этого поля работает как раньше.
            'order_event' => ['sometimes', 'array'],
            'order_event.items' => ['sometimes', 'array'],
        ]);

        if (! empty($validated['order_event']['items'])) {
            try {
                app(OrderEventTransformer::class)->applyAssemblyItems($order, $request->input('order_event'));
                $order->refresh();
            } catch (\Throwable $e) {
                // Списание важнее чека: сбой синхронизации позиций не должен
                // блокировать сборщика. Позиции догонит событие order.updated.
                Log::error('Internal settle-payment: assembly items not applied', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $result = $paymentService->settleAssemblyPayment($order, (float) $validated['amount']);
        } catch (ConnectionException) {
            // ЮKassa недоступна по сети: ничего не списано либо списание
            // подхватит повтор с тем же ключом идемпотентности (лог в сервисе)
            return response()->json([
                'status' => 'retry',
                'message' => 'Платёжный сервис временно недоступен, попробуйте ещё раз',
            ], 503);
        }

        if ($result->success) {
            return response()->json(['status' => 'ok', 'payment_status' => 'paid']);
        }

        Log::info('Internal settle-payment failed', [
            'order_id' => $order->id,
            'amount' => $validated['amount'],
            'error' => $result->errorMessage,
        ]);

        $fresh = $order->fresh();

        // 402 — доплата не прошла (заказ помечен «ожидает доплату»);
        // 503 — доплата прошла/не требовалась, но capture холда упал:
        // транзиентный сбой, повторный вызов безопасен и НЕ дублирует доплату
        if ($fresh->surcharge_amount !== null) {
            return response()->json([
                'status' => 'failed',
                'message' => $result->errorMessage ?? 'Платёж отклонён',
                'surcharge_amount' => (float) $fresh->surcharge_amount,
            ], 402);
        }

        return response()->json([
            'status' => 'retry',
            'message' => 'Техническая ошибка списания, попробуйте ещё раз',
        ], 503);
    }
}
