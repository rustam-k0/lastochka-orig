<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\DTOs\Payment\ReceiptData;
use App\Models\Order;
use App\Services\IntegrationConfigService;
use App\Support\Payment\PaymentDeclineReason;
use App\Support\Payment\PaymentGatewayError;
use App\Support\Payment\ReturnUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class YooKassaService implements PaymentGateway
{
    private const API_URL = 'https://api.yookassa.ru/v3';

    public function hold(
        Order $order,
        ?string $paymentMethodId = null,
        ?ReceiptData $receipt = null,
        ?string $idempotenceKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $returnUrl = null,
    ): PaymentResult {
        $numericOrderId = is_numeric($order->id) && (int) $order->id > 0;

        $body = [
            'amount' => [
                'value' => number_format((float) $order->total, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'capture' => false,
            'save_payment_method' => true,
            // Описание/metadata можно передать явно (pre-auth: заказа ещё нет).
            // Иначе инферим по order->id: реальный заказ vs привязка карты.
            'description' => $description ?? ($numericOrderId ? "Заказ #{$order->id}" : 'Привязка карты'),
            'metadata' => $metadata ?? ($numericOrderId ? ['order_id' => $order->id] : ['bind_card' => true]),
        ];

        if ($receipt !== null && $receipt->hasItems()) {
            $body['receipt'] = $receipt->toArray();
        }

        if ($paymentMethodId) {
            $body['payment_method_id'] = $paymentMethodId;
        } else {
            $body['confirmation'] = [
                'type' => 'redirect',
                // Адрес возврата клиента уже проверен по белому списку в
                // валидации запроса; resolve — вторая линия и подстановка
                // дефолта, когда клиент ничего не передал.
                'return_url' => ReturnUrl::resolve($returnUrl),
            ];
            $body['payment_method_data'] = [
                'type' => 'bank_card',
            ];
        }

        // Явный idempotence-key (pre-auth) имеет приоритет; иначе — по order->id.
        $idempotenceKey ??= $numericOrderId ? "hold-{$order->id}" : Str::uuid()->toString();

        $response = $this->request('POST', '/payments', $body, $idempotenceKey);

        if (! $response->successful()) {
            Log::error('YooKassa hold failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return new PaymentResult(
                success: false,
                errorMessage: PaymentGatewayError::message($response->json('description'), 'Ошибка создания платежа'),
            );
        }

        $data = $response->json();

        // Автоплатёж по сохранённой карте ЮKassa отклоняет СИНХРОННО: HTTP 200,
        // но status='canceled' + cancellation_details.reason (insufficient_funds и т.п.).
        // Без этой проверки отказ банка трактовался бы как успех (success=true).
        if (($data['status'] ?? null) === 'canceled') {
            $reason = $data['cancellation_details']['reason'] ?? null;

            Log::warning('YooKassa hold declined synchronously', [
                'order_id' => $order->id,
                'payment_id' => $data['id'] ?? null,
                'reason' => $reason,
            ]);

            return new PaymentResult(
                success: false,
                paymentId: $data['id'] ?? null,
                status: 'canceled',
                paymentMethodId: $data['payment_method']['id'] ?? null,
                errorMessage: PaymentDeclineReason::message($reason),
                declineReason: PaymentDeclineReason::normalize($reason),
            );
        }

        return new PaymentResult(
            success: true,
            paymentId: $data['id'],
            confirmationUrl: $data['confirmation']['confirmation_url'] ?? null,
            status: $data['status'],
            paymentMethodId: $data['payment_method']['id'] ?? null,
        );
    }


    public function capture(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $body = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
        ];

        if ($receipt !== null && $receipt->hasItems()) {
            $body['receipt'] = $receipt->toArray();
        }

        $response = $this->request('POST', "/payments/{$yookassaPaymentId}/capture", $body, "capture-{$yookassaPaymentId}");

        if (! $response->successful()) {
            Log::error('YooKassa capture failed', [
                'payment_id' => $yookassaPaymentId,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return new PaymentResult(
                success: false,
                paymentId: $yookassaPaymentId,
                errorMessage: PaymentGatewayError::message($response->json('description'), 'Ошибка подтверждения платежа'),
            );
        }

        $data = $response->json();

        return new PaymentResult(
            success: true,
            paymentId: $data['id'],
            status: $data['status'],
        );
    }

    public function cancel(string $yookassaPaymentId): PaymentResult
    {
        $response = $this->request('POST', "/payments/{$yookassaPaymentId}/cancel", [], "cancel-{$yookassaPaymentId}");

        if (! $response->successful()) {
            Log::error('YooKassa cancel failed', [
                'payment_id' => $yookassaPaymentId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return new PaymentResult(
                success: false,
                paymentId: $yookassaPaymentId,
                errorMessage: PaymentGatewayError::message($response->json('description'), 'Ошибка отмены платежа'),
            );
        }

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: $response->json('status'),
        );
    }

    public function refund(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $body = [
            'payment_id' => $yookassaPaymentId,
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
        ];

        if ($receipt !== null && $receipt->hasItems()) {
            $body['receipt'] = $receipt->toArray();
        }

        $response = $this->request('POST', '/refunds', $body, "refund-{$yookassaPaymentId}-{$amount}");

        if (! $response->successful()) {
            Log::error('YooKassa refund failed', [
                'payment_id' => $yookassaPaymentId,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return new PaymentResult(
                success: false,
                paymentId: $yookassaPaymentId,
                errorMessage: PaymentGatewayError::message($response->json('description'), 'Ошибка возврата'),
            );
        }

        $data = $response->json();

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: $data['status'],
            refundId: $data['id'],
        );
    }

    public function charge(Order $order, float $amount, string $paymentMethodId, ?ReceiptData $receipt = null): PaymentResult
    {
        $body = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'capture' => true,
            'payment_method_id' => $paymentMethodId,
            'description' => "Досписание по заказу #{$order->id}",
            'metadata' => [
                'order_id' => $order->id,
                'type' => 'extra_charge',
            ],
        ];

        if ($receipt !== null && $receipt->hasItems()) {
            $body['receipt'] = $receipt->toArray();
        }

        $response = $this->request('POST', '/payments', $body, "charge-{$order->id}-{$amount}");

        if (! $response->successful()) {
            Log::error('YooKassa charge failed', [
                'order_id' => $order->id,
                'amount' => $amount,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return new PaymentResult(
                success: false,
                errorMessage: PaymentGatewayError::message($response->json('description'), 'Ошибка досписания'),
            );
        }

        $data = $response->json();

        return new PaymentResult(
            success: true,
            paymentId: $data['id'],
            status: $data['status'],
        );
    }

    public function getPaymentInfo(string $yookassaPaymentId): PaymentInfo
    {
        $response = $this->request('GET', "/payments/{$yookassaPaymentId}");

        if (! $response->successful()) {
            Log::error('YooKassa getPaymentInfo failed', [
                'payment_id' => $yookassaPaymentId,
                'status' => $response->status(),
            ]);

            return new PaymentInfo(
                paymentId: $yookassaPaymentId,
                status: 'unknown',
                amount: '0',
                currency: 'RUB',
            );
        }

        $data = $response->json();
        $method = $data['payment_method'] ?? [];
        $card = $method['card'] ?? [];

        return new PaymentInfo(
            paymentId: $data['id'],
            status: $data['status'],
            amount: $data['amount']['value'],
            currency: $data['amount']['currency'],
            paymentMethodId: $method['id'] ?? null,
            cardType: $card['card_type'] ?? null,
            lastFour: $card['last4'] ?? null,
            expiryMonth: $card['expiry_month'] ?? null,
            expiryYear: $card['expiry_year'] ?? null,
            paid: $data['paid'] ?? false,
        );
    }

    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array
    {
        $query = [];
        if ($paymentId !== null && $paymentId !== '') {
            $query['payment_id'] = $paymentId;
        }
        if ($refundId !== null && $refundId !== '') {
            $query['refund_id'] = $refundId;
        }
        if ($query === []) {
            return [];
        }

        $receipts = [];
        $cursor = null;

        do {
            $params = $query;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $response = $this->request('GET', '/receipts?'.http_build_query($params));

            if (! $response->successful()) {
                Log::error('YooKassa getReceipts failed', [
                    'query' => $query,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                break;
            }

            $data = $response->json();
            foreach ($data['items'] ?? [] as $item) {
                $receipts[] = $item;
            }

            $cursor = $data['next_cursor'] ?? null;
        } while ($cursor !== null);

        return $receipts;
    }

    public function createIndependentReceipt(array $payload): array
    {
        $response = $this->request('POST', '/receipts', $payload, 'independent-receipt-'.Str::uuid()->toString());

        if (! $response->successful()) {
            Log::error('YooKassa createIndependentReceipt failed', [
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return $response->json() ?? [];
        }

        return $response->json();
    }

    private function getCredentials(): array
    {
        $driverCreds = IntegrationConfigService::getDriverCredentials('payment', 'yookassa');

        $mode = $driverCreds['mode'] ?? 'test';

        return [
            'shop_id' => $mode === 'live'
                ? ($driverCreds['live_shop_id'] ?? '')
                : ($driverCreds['test_shop_id'] ?? ''),
            'secret_key' => $mode === 'live'
                ? ($driverCreds['live_secret_key'] ?? '')
                : ($driverCreds['test_secret_key'] ?? ''),
        ];
    }

    private function request(string $method, string $path, array $body = [], ?string $idempotenceKey = null): \Illuminate\Http\Client\Response
    {
        $creds = $this->getCredentials();

        // Обрыв соединения (cURL 28 «Failed to connect», прод 08.10.2026, GlitchTip 857)
        // повторяем один раз: ключ идемпотентности тот же, поэтому повтор не
        // создаст второй платёж/capture, даже если первый запрос дошёл до ЮKassa.
        // throw: false — ответы 4xx/5xx возвращаются как Response, их разбирают
        // вызывающие методы. Не прошедший повтор бросает ConnectionException.
        $request = Http::connectTimeout(5)
            ->timeout(15)
            ->retry(2, 500, fn (\Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            ->withBasicAuth($creds['shop_id'], $creds['secret_key'])
            ->withHeaders([
                'Idempotence-Key' => $idempotenceKey ?? Str::uuid()->toString(),
            ]);

        $url = self::API_URL.$path;

        return match ($method) {
            'GET' => $request->get($url),
            // Пустое тело сериализуем как JSON-объект {}, а не массив [].
            // YooKassa на [] отвечает 400 "Unable to parse the response body"
            // (касается POST /payments/{id}/cancel — там тело пустое).
            'POST' => $request->post($url, $body === [] ? (object) [] : $body),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }
}
