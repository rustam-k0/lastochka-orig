<?php

namespace App\Services\RetailCRM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetailCRMApiService
{
    /**
     * API URL
     * @var string
     */
    protected $apiUrl;

    /**
     * API Token
     * @var string
     */
    protected $apiToken;

    /**
     * API Version
     * @var string
     */
    protected $apiVersion;

    /**
     * Максимальное количество повторов запроса
     * @var int
     */
    protected $maxRetries = 3;

    /**
     * Начальная задержка между повторами в секундах
     * @var int
     */
    protected $retryDelay = 1;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->apiUrl = config('retailcrm.url');
        $this->apiToken = config('retailcrm.api_key');
        $this->apiVersion = config('retailcrm.version');
    }

    /**
     * Получение заказов из RetailCRM
     */
    public function getOrders(array $filter = [], int $page = 1, int $limit = 100): array
    {
        $response = $this->makeRequest('orders', [
            'filter' => $filter,
            'page' => $page,
            'limit' => $limit
        ]);

        if (!$response || !isset($response['orders'])) {
            return [];
        }

        return $response['orders'];
    }

    /**
     * Получение заказа по ID
     */
    public function getOrder(string $id): ?array
    {
        \Log::info('RetailCRM: Запрос заказа по ID', [
            'order_id' => $id,
            'api_url' => $this->apiUrl,
        ]);

        $response = $this->makeRequest('orders/' . $id, ['by' => 'id']);

        if (!$response) {
            \Log::error('RetailCRM: Ошибка при получении заказа', [
                'order_id' => $id,
                'response' => 'null',
            ]);
            return null;
        }

        if (!isset($response['order'])) {
            \Log::error('RetailCRM: Ответ не содержит данных о заказе', [
                'order_id' => $id,
                'response' => $response,
            ]);
            return null;
        }

        \Log::info('RetailCRM: Заказ успешно получен', [
            'order_id' => $id,
        ]);

        return $response['order'];
    }

    /**
     * Обновление статуса заказа в RetailCRM
     */
    public function updateOrderStatus(string $id, string $status): bool
    {
        Log::info('RetailCRM: Попытка обновления статуса заказа', ['id' => $id, 'status' => $status]);

        $response = $this->makeRequest('orders/' . $id . '/edit', [
            'order' => json_encode(['status' => $status]),
            'by' => 'id'
        ], 'POST');

        $success = $response && isset($response['success']) && $response['success'];

        if ($success) {
            Log::info('RetailCRM: Статус заказа успешно обновлен', ['id' => $id, 'status' => $status]);
        } else {
            Log::error('RetailCRM: Не удалось обновить статус заказа', [
                'id' => $id,
                'status' => $status,
                'response' => $response
            ]);
        }

        return $success;
    }

    /**
     * Обновление статуса заказа в RetailCRM
     */
    public function updateOrder(string $id, array $data): bool
    {
        Log::info('RetailCRM: Попытка обновления заказа', ['id' => $id]);

        $response = $this->makeRequest('orders/' . $id . '/edit', [
            'order' => json_encode($data),
            'by' => 'id'
        ], 'POST');

        $success = $response && isset($response['success']) && $response['success'];

        if ($success) {
            Log::info('RetailCRM: Заказ успешно обновлен', ['id' => $id]);
        } else {
            Log::error('RetailCRM: Не удалось обновить заказ', [
                'id' => $id,
                'data' => $data,
                'response' => $response
            ]);
        }

        return $success;
    }

    /**
     * Выполнение запроса к API с retry логикой
     */
    public function makeRequest(string $endpoint, array $params = [], string $method = 'GET', int $retryCount = 0): ?array
    {
        $url = $this->apiUrl . '/api/' . $this->apiVersion . '/' . $endpoint;

        $params['apiKey'] = $this->apiToken;

        try {
            $response = null;

            if ($method === 'GET') {
                $response = Http::timeout(30)->get($url, $params);
            } else {
                $response = Http::timeout(30)->post($url, $params);
            }

            // Успешный ответ
            if ($response->successful()) {
                $jsonResponse = $response->json();
                return $jsonResponse;
            }

            // Обработка ошибок API
            $statusCode = $response->status();
            $responseBody = $response->body();

            // Логируем ошибку API
            Log::error('RetailCRM API Error', [
                'url' => $url,
                'method' => $method,
                'status_code' => $statusCode,
                'response_body' => $responseBody,
                'params' => $this->sanitizeParams($params),
                'retry_count' => $retryCount
            ]);

            // Повторяем попытку для определенных ошибок
            if ($this->shouldRetry($statusCode) && $retryCount < $this->maxRetries) {
                $delay = $this->retryDelay * pow(2, $retryCount);
                Log::info('RetailCRM API Retry', [
                    'url' => $url,
                    'retry_count' => $retryCount + 1,
                    'delay' => $delay
                ]);

                sleep($delay);
                return $this->makeRequest($endpoint, $params, $method, $retryCount + 1);
            }

            return null;

        } catch (\Exception $e) {
            Log::error('RetailCRM API Exception', [
                'url' => $url,
                'method' => $method,
                'exception' => $e->getMessage(),
                'retry_count' => $retryCount,
                'trace' => $e->getTraceAsString()
            ]);

            // Повторяем попытку при сетевых ошибках
            if ($retryCount < $this->maxRetries) {
                $delay = $this->retryDelay * pow(2, $retryCount);
                Log::info('RetailCRM API Exception Retry', [
                    'url' => $url,
                    'retry_count' => $retryCount + 1,
                    'delay' => $delay,
                    'exception' => $e->getMessage()
                ]);

                sleep($delay);
                return $this->makeRequest($endpoint, $params, $method, $retryCount + 1);
            }

            return null;
        }
    }

    /**
     * Определяет, нужно ли повторять запрос при данном коде ответа
     */
    protected function shouldRetry(int $statusCode): bool
    {
        // Повторяем при временных ошибках сервера, таймаутах и сетевых ошибках
        return in_array($statusCode, [408, 429, 500, 502, 503, 504]);
    }

    /**
     * Очищает параметры от чувствительных данных для логирования
     */
    protected function sanitizeParams(array $params): array
    {
        $sanitized = $params;
        if (isset($sanitized['apiKey'])) {
            $sanitized['apiKey'] = '***masked***';
        }
        return $sanitized;
    }
}
