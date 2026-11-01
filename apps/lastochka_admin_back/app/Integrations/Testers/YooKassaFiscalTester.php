<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use App\Models\IntegrationConfig;
use Illuminate\Support\Facades\Http;

class YooKassaFiscalTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $paymentConfig = IntegrationConfig::where('integration_type', 'payment')
            ->where('is_active', true)
            ->first();

        if (!$paymentConfig) {
            return new TestResult(false, 'Подключите оплату ЮKassa');
        }

        $yooKassaCredentials = $paymentConfig->credentials['yookassa'] ?? [];

        if (($yooKassaCredentials['mode'] ?? null) !== 'live') {
            return new TestResult(false, 'Включите боевой режим ЮKassa');
        }

        $liveShopId = $yooKassaCredentials['live_shop_id'] ?? '';
        $liveSecretKey = $yooKassaCredentials['live_secret_key'] ?? '';

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($liveShopId, $liveSecretKey)
                ->get('https://api.yookassa.ru/v3/me');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(false, 'Фискализация не активирована в кассе ЮKassa');
        } catch (\Throwable $e) {
            return new TestResult(false, 'Фискализация не активирована в кассе ЮKassa');
        }

        if (!$response->successful() || $response->json('fiscalization_enabled') !== true) {
            return new TestResult(false, 'Фискализация не активирована в кассе ЮKassa');
        }

        return new TestResult(true, 'Фискализация ЮKassa активна');
    }
}
