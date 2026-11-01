<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

/**
 * «Проверить подключение» для ручки CheckCIS 1С: отправляем заведомо
 * тестовый код. Нас интересует только достижимость и авторизация —
 * содержательный ответ (разрешён/не разрешён) здесь не важен.
 */
class HonestSignCheckCisTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $url = trim((string) ($credentials['url'] ?? ''));
        $login = (string) ($credentials['login'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new TestResult(success: false, message: 'Укажите корректный URL ручки проверки');
        }

        if ($login === '' || $password === '') {
            return new TestResult(success: false, message: 'Логин и пароль обязательны');
        }

        try {
            $response = Http::withBasicAuth($login, $password)
                ->asJson()
                ->acceptJson()
                ->timeout(10)
                ->post($url, [
                    'barcode' => '0000000000000',
                    'cis' => 'TEST',
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(success: false, message: 'Не удалось подключиться к 1С: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new TestResult(success: false, message: 'Ошибка: ' . $e->getMessage());
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            return new TestResult(success: false, message: 'Ошибка авторизации: 1С отклонила логин или пароль');
        }

        if ($status === 200) {
            $message = is_array($response->json()) ? ($response->json('message') ?? null) : null;

            return new TestResult(
                success: true,
                message: 'Соединение установлено, 1С отвечает' . ($message ? ": {$message}" : ''),
            );
        }

        return new TestResult(success: false, message: "1С вернула HTTP {$status}: " . mb_substr(trim($response->body()), 0, 200));
    }
}
