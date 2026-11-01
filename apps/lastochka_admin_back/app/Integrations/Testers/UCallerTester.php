<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class UCallerTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $secretKey = $credentials['secret_key'] ?? '';
        $serviceId = $credentials['service_id'] ?? '';

        if (empty($secretKey) || empty($serviceId)) {
            return new TestResult(
                success: false,
                message: 'Не указаны Secret Key или Service ID',
            );
        }

        try {
            // Проверяем авторизацию через getAccount
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$secretKey}.{$serviceId}",
                ])
                ->get('https://api.ucaller.ru/v1.0/getAccount');

            if (!$response->successful()) {
                return new TestResult(
                    success: false,
                    message: 'Ошибка авторизации uCaller: ' . ($response->json('error') ?? "HTTP {$response->status()}"),
                );
            }

            $data = $response->json();

            // getAccount возвращает данные аккаунта без поля status при успехе,
            // но status=false + error при ошибке
            if (isset($data['status']) && $data['status'] === false) {
                return new TestResult(
                    success: false,
                    message: 'uCaller вернул ошибку: ' . ($data['error'] ?? 'неизвестная ошибка'),
                );
            }

            if (!isset($data['id'])) {
                return new TestResult(
                    success: false,
                    message: 'Неожиданный ответ uCaller: ' . json_encode($data),
                );
            }

            // Проверяем баланс через getBalance
            $balanceResponse = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$secretKey}.{$serviceId}",
                ])
                ->get('https://api.ucaller.ru/v1.0/getBalance');

            $balance = null;
            if ($balanceResponse->successful()) {
                $balanceData = $balanceResponse->json();
                $balance = $balanceData['rub_balance'] ?? $balanceData['balance'] ?? null;
            }

            $balanceInfo = $balance !== null ? " Баланс: {$balance} руб." : '';

            if ($balance !== null && (float) $balance <= 0) {
                return new TestResult(
                    success: false,
                    message: "Авторизация успешна, но баланс: {$balance} руб. Пополните баланс в личном кабинете uCaller.",
                );
            }

            return new TestResult(
                success: true,
                message: "Авторизация успешна.{$balanceInfo}",
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(
                success: false,
                message: 'Таймаут: uCaller не ответил за 10 сек',
            );
        } catch (\Throwable $e) {
            return new TestResult(
                success: false,
                message: 'Ошибка подключения: ' . $e->getMessage(),
            );
        }
    }
}
