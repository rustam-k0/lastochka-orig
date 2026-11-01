<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class CommerceMLTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $parserUrl = config('services.1c_parser.url');

        if (empty($parserUrl)) {
            return new TestResult(
                success: false,
                message: 'URL парсера не настроен (ONEC_PARSER_URL)',
            );
        }

        $login = $credentials['login'] ?? '';
        $password = $credentials['password'] ?? '';

        if (empty($login) || empty($password)) {
            return new TestResult(
                success: false,
                message: 'Логин и пароль обязательны',
            );
        }

        $url = rtrim($parserUrl, '/') . '/1c-exchange';
        if ($shopId) {
            $shop = \App\Models\Shop::find($shopId);
            if ($shop?->code) {
                $url .= '/' . $shop->code;
            }
        }

        try {
            $response = Http::withBasicAuth($login, $password)
                ->timeout(10)
                ->get($url, [
                    'type' => 'catalog',
                    'mode' => 'checkauth',
                ]);

            $body = trim($response->body());
            $firstLine = strtok($body, "\n");

            if ($response->status() === 401) {
                return new TestResult(
                    success: false,
                    message: 'Ошибка авторизации: парсер отклонил credentials',
                );
            }

            if ($firstLine === 'success') {
                return new TestResult(
                    success: true,
                    message: 'Авторизация на парсере прошла успешно',
                );
            }

            return new TestResult(
                success: false,
                message: "Парсер вернул: {$firstLine}",
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(
                success: false,
                message: 'Не удалось подключиться к парсеру: ' . $e->getMessage(),
            );
        } catch (\Throwable $e) {
            return new TestResult(
                success: false,
                message: 'Ошибка: ' . $e->getMessage(),
            );
        }
    }
}
