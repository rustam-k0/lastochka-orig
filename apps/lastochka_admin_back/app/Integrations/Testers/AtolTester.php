<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class AtolTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $login = $credentials['login'] ?? '';
        $password = $credentials['password'] ?? '';
        $groupCode = $credentials['group_code'] ?? '';

        if (empty($login) || empty($password) || empty($groupCode)) {
            return new TestResult(
                success: false,
                message: 'Не указаны логин/пароль/код группы касс',
            );
        }

        $mode = $credentials['mode'] ?? 'test';
        // ФФД 1.05 → APIv4, ФФД 1.2 → APIv5 (у getToken формат одинаковый, различается только базовый URL)
        $apiVersion = ($credentials['ffd_version'] ?? '1.05') === '1.2' ? 'v5' : 'v4';
        $baseUrl = ($mode === 'live'
            ? 'https://online.atol.ru/possystem/'
            : 'https://testonline.atol.ru/possystem/') . $apiVersion;

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout(10)
                ->post('/getToken', [
                    'login' => $login,
                    'pass' => $password,
                ]);

            $token = $response->json('token');

            if (!empty($token)) {
                return new TestResult(
                    success: true,
                    message: 'Авторизация успешна',
                );
            }

            $errorText = $response->json('error.text');

            return new TestResult(
                success: false,
                message: $errorText ?: 'Ошибка авторизации АТОЛ Онлайн',
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(
                success: false,
                message: 'Таймаут: АТОЛ Онлайн не ответил за 10 сек',
            );
        } catch (\Throwable $e) {
            return new TestResult(
                success: false,
                message: 'Ошибка подключения: ' . $e->getMessage(),
            );
        }
    }
}
