<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Синхронные вызовы storefront_back (модуль collector_payment).
 * Внутренняя авторизация — статик-токен X-Internal-Token
 * (storefront: middleware internal.token, env INTERNAL_API_TOKEN).
 */
class StorefrontPaymentClient
{
  /**
   * Списать итоговую стоимость заказа (доплата разницы + capture холда).
   *
   * $orderEvent — payload order.updated по итогам сборки (OrderEventTransformer::toEvent):
   * storefront применяет из него позиции ДО списания, т.к. чек прихода строится
   * в момент capture, а само событие доходит до витрины позже.
   *
   * @return array{ok: bool, status: int, body: array}
   */
  public function settleOrderPayment(int $orderId, float $amount, ?array $orderEvent = null): array
  {
    $base = rtrim((string) config('services.storefront.url'), '/');

    try {
      $response = Http::asJson()
        ->withHeaders(['X-Internal-Token' => (string) config('services.storefront.internal_token')])
        ->timeout(30)
        ->post("{$base}/api/v1/internal/orders/{$orderId}/settle-payment", array_filter([
          'amount' => $amount,
          'order_event' => $orderEvent,
        ], fn ($value) => $value !== null));

      return [
        'ok' => $response->successful(),
        'status' => $response->status(),
        'body' => $response->json() ?? [],
      ];
    } catch (\Throwable $e) {
      Log::error('StorefrontPaymentClient: settle request failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
      ]);

      return ['ok' => false, 'status' => 0, 'body' => []];
    }
  }
}
