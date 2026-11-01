<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class SmsAeroTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $login = $credentials['login'] ?? '';
        $apiKey = $credentials['api_key'] ?? '';
        $sign = $credentials['sign'] ?? '';

        if (empty($login) || empty($apiKey)) {
            return new TestResult(
                success: false,
                message: 'Не указаны логин или API ключ',
            );
        }

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($login, $apiKey)
                ->get('https://gate.smsaero.ru/v2/auth');

            if (!$response->successful()) {
                return new TestResult(
                    success: false,
                    message: 'Ошибка авторизации SmsAero: ' . ($response->json('message') ?? "HTTP {$response->status()}"),
                );
            }

            // Проверить баланс
            $balanceResponse = Http::timeout(10)
                ->withBasicAuth($login, $apiKey)
                ->get('https://gate.smsaero.ru/v2/balance');

            $balance = $balanceResponse->successful() ? $balanceResponse->json('data.balance') : null;
            $balanceInfo = $balance !== null ? " Баланс: {$balance} руб." : '';

            if ($balance !== null && (float) $balance <= 0) {
                return new TestResult(
                    success: false,
                    message: "Авторизация успешна, но баланс: {$balance} руб. Пополните баланс в личном кабинете SmsAero.",
                );
            }

            // Проверить имя отправителя
            if (!empty($sign)) {
                $signResponse = Http::timeout(10)
                    ->withBasicAuth($login, $apiKey)
                    ->get('https://gate.smsaero.ru/v2/sign/list');

                if ($signResponse->successful()) {
                    $data = $signResponse->json('data');
                    $totalCount = (int) ($data['totalCount'] ?? 0);

                    if ($totalCount === 0) {
                        return new TestResult(
                            success: false,
                            message: "В SmsAero нет зарегистрированных имён отправителя. Добавьте \"{$sign}\" в личном кабинете SmsAero → раздел \"Имена\".",
                        );
                    }

                    // data: {"totalCount":"2", "0": {"name":"x","status":1,"extendStatus":"active"}, ...}
                    // status=1 + extendStatus="active" = активное имя
                    $signItems = collect($data)
                        ->filter(fn($item) => is_array($item) && isset($item['name']));

                    $approvedSigns = $signItems
                        ->filter(fn($item) => ($item['status'] ?? 0) == 1 && ($item['extendStatus'] ?? '') === 'active')
                        ->pluck('name')
                        ->toArray();

                    $allSigns = $signItems->pluck('name')->toArray();

                    if (empty($approvedSigns) && !empty($allSigns)) {
                        return new TestResult(
                            success: false,
                            message: "Ни одно имя отправителя не имеет статус Active. Ожидайте одобрения в SmsAero.",
                        );
                    }

                    if (!empty($approvedSigns) && !in_array(mb_strtolower($sign), array_map('mb_strtolower', $approvedSigns), true)) {
                        $available = implode(', ', $approvedSigns);
                        return new TestResult(
                            success: false,
                            message: "Имя отправителя \"{$sign}\" не найдено среди активных в SmsAero. Доступные: {$available}",
                        );
                    }
                }
            }

            // Если указан телефон для теста — отправить тестовое SMS
            $phone = $testParams['phone'] ?? null;
            if ($phone) {
                $smsResponse = Http::timeout(10)
                    ->withBasicAuth($login, $apiKey)
                    ->post('https://gate.smsaero.ru/v2/sms/send', [
                        'number' => preg_replace('/\D/', '', $phone),
                        'text' => 'Тестовое сообщение из админки',
                        'sign' => $sign,
                    ]);

                if (!$smsResponse->successful()) {
                    return new TestResult(
                        success: false,
                        message: 'Авторизация успешна, но отправка SMS не удалась: ' . ($smsResponse->json('message') ?? "HTTP {$smsResponse->status()}"),
                    );
                }

                return new TestResult(
                    success: true,
                    message: "Авторизация успешна, тестовое SMS отправлено.{$balanceInfo}",
                );
            }

            return new TestResult(
                success: true,
                message: "Авторизация успешна.{$balanceInfo}",
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(
                success: false,
                message: 'Таймаут: SmsAero не ответил за 10 сек',
            );
        } catch (\Throwable $e) {
            return new TestResult(
                success: false,
                message: 'Ошибка подключения: ' . $e->getMessage(),
            );
        }
    }
}
