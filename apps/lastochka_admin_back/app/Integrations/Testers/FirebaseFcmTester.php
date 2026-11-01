<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseFcmTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $json = $credentials['service_account_json'] ?? null;

        if (!$json) {
            return new TestResult(success: false, message: 'Service Account JSON не указан');
        }

        $decoded = json_decode($json, true);

        if (!$decoded || !is_array($decoded)) {
            return new TestResult(success: false, message: 'Невалидный JSON');
        }

        $requiredKeys = ['type', 'project_id', 'private_key', 'client_email'];
        $missing = array_diff($requiredKeys, array_keys($decoded));

        if (!empty($missing)) {
            return new TestResult(
                success: false,
                message: 'Отсутствуют обязательные поля: ' . implode(', ', $missing)
            );
        }

        if ($decoded['type'] !== 'service_account') {
            return new TestResult(success: false, message: 'Поле type должно быть "service_account"');
        }

        try {
            $messaging = (new Factory)
                ->withServiceAccount($decoded)
                ->withHttpClientOptions(HttpClientOptions::default()->withTimeout(10))
                ->createMessaging();

            // Попытка отправить dry-run (validate_only) для проверки доступа
            $message = CloudMessage::new()
                ->withNotification(Notification::create('test', 'test'));

            $messaging->send($message, validateOnly: true);

            return new TestResult(
                success: true,
                message: "Подключение успешно. Проект: {$decoded['project_id']}"
            );
        } catch (MessagingException $e) {
            // INVALID_ARGUMENT — ожидаемо для dry-run без target, значит auth прошла
            if (str_contains($e->getMessage(), 'INVALID_ARGUMENT') || str_contains($e->getMessage(), 'exactly one of') || str_contains($e->getMessage(), 'missing a target')) {
                return new TestResult(
                    success: true,
                    message: "Подключение успешно. Проект: {$decoded['project_id']}"
                );
            }

            return new TestResult(success: false, message: 'FCM ошибка: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return new TestResult(success: false, message: 'Невалидный Service Account: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return new TestResult(success: false, message: 'Ошибка: ' . $e->getMessage());
        }
    }
}
