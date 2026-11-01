<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class YooKassaTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $mode = $credentials['mode'] ?? 'test';
        $shopIdKey = $mode === 'live' ? 'live_shop_id' : 'test_shop_id';
        $secretKey = $mode === 'live' ? 'live_secret_key' : 'test_secret_key';

        $yooShopId = $credentials[$shopIdKey] ?? '';
        $yooSecret = $credentials[$secretKey] ?? '';

        if (empty($yooShopId) || empty($yooSecret)) {
            $modeLabel = $mode === 'live' ? 'боевого' : 'тестового';
            return new TestResult(
                success: false,
                message: "Не указаны Shop ID или секретный ключ для {$modeLabel} режима",
            );
        }

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($yooShopId, $yooSecret)
                ->get('https://api.yookassa.ru/v3/me');

            if ($response->status() === 401) {
                return new TestResult(
                    success: false,
                    message: 'Ошибка авторизации: неверный Shop ID или секретный ключ',
                );
            }

            if (!$response->successful()) {
                return new TestResult(
                    success: false,
                    message: 'Ошибка YooKassa API: HTTP ' . $response->status(),
                );
            }

            $data = $response->json();
            $accountId = $data['account_id'] ?? 'unknown';
            $status = $data['status'] ?? 'unknown';
            $modeLabel = $mode === 'live' ? 'боевой' : 'тестовый';

            if ($status !== 'enabled') {
                return new TestResult(
                    success: false,
                    message: "Магазин #{$accountId} ({$modeLabel}) имеет статус: {$status}. Требуется статус 'enabled'.",
                );
            }

            // Проверить поддержку автоплатежей (save_payment_method)
            $recurringWarning = '';
            try {
                $testPayment = Http::timeout(10)
                    ->withBasicAuth($yooShopId, $yooSecret)
                    ->withHeaders(['Idempotence-Key' => \Illuminate\Support\Str::uuid()->toString()])
                    ->post('https://api.yookassa.ru/v3/payments', [
                        'amount' => ['value' => '1.00', 'currency' => 'RUB'],
                        'capture' => false,
                        'save_payment_method' => true,
                        'confirmation' => ['type' => 'redirect', 'return_url' => 'https://example.com'],
                        'description' => 'Проверка автоплатежей',
                    ]);

                if ($testPayment->successful()) {
                    // Сразу отменяем тестовый платёж
                    $paymentId = $testPayment->json('id');
                    Http::timeout(10)
                        ->withBasicAuth($yooShopId, $yooSecret)
                        ->withHeaders(['Idempotence-Key' => \Illuminate\Support\Str::uuid()->toString()])
                        ->post("https://api.yookassa.ru/v3/payments/{$paymentId}/cancel", []);
                } elseif ($testPayment->json('code') === 'forbidden') {
                    $recurringWarning = ' ⚠️ Автоплатежи НЕ подключены — обратитесь к менеджеру ЮKassa для активации сохранения карт.';
                }
            } catch (\Throwable $e) {
                // Не блокируем основной результат
            }

            return new TestResult(
                success: true,
                message: "Магазин #{$accountId} ({$modeLabel}) — активен.{$recurringWarning}",
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(
                success: false,
                message: 'Таймаут: YooKassa не ответила за 10 сек',
            );
        } catch (\Throwable $e) {
            return new TestResult(
                success: false,
                message: 'Ошибка подключения: ' . $e->getMessage(),
            );
        }
    }
}
