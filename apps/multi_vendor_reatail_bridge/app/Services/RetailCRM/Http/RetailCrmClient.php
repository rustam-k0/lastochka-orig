<?php

namespace App\Services\RetailCRM\Http;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\RetailCRM\RetailCrmException;
use App\Exceptions\RetailCRM\RetailCrmConnectionException;
use App\Exceptions\RetailCRM\RetailCrmApiResponseException;
use App\Exceptions\RetailCRM\RetailCrmNotFoundException;

class RetailCrmClient
{
    protected string $apiUrl;
    protected string $apiKey;
    protected int $timeout;

    public function __construct(string $apiUrl, string $apiKey, int $timeout = 30)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Выполнить GET запрос
     *
     * @param string $endpoint
     * @param array $query
     * @return Response
     * @throws RetailCrmException
     */
    public function get(string $endpoint, array $query = []): Response
    {
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * Выполнить POST запрос
     *
     * @param string $endpoint
     * @param array $data
     * @return Response
     * @throws RetailCrmException
     */
    public function post(string $endpoint, array $data = []): Response
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Выполнить запрос к API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return Response
     * @throws RetailCrmException
     */
    protected function request(string $method, string $endpoint, array $data = []): Response
    {
        $url = "{$this->apiUrl}/api/v5/" . ltrim($endpoint, '/');

        // Prepare data for logging: decode JSON strings if present
        $logData = $data;
        foreach ($logData as $key => $value) {
            if (is_string($value) && ($json = json_decode($value, true)) !== null) {
                $logData[$key] = $json;
            }
        }

        Log::info("RetailCRM Request: {$method} {$url}", ['payload' => $logData]);

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
            ])
            ->timeout($this->timeout)
            ->retry(3, 500) // 3 попытки, 500мс пауза
            ->send($method, $url, $method === 'GET' ? ['query' => $data] : ['form_params' => $data]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RetailCrmConnectionException("Ошибка соединения с RetailCRM: {$e->getMessage()}", 0, $e);
        } catch (\Exception $e) {
             throw new RetailCrmException("Неизвестная ошибка при запросе к RetailCRM: {$e->getMessage()}", 0, $e);
        }

        $body = $response->json();

        Log::info("RetailCRM Response: {$response->status()}", ['body' => $body]);

        if ($response->failed()) {
            $this->handleError($response, $body);
        }

        if (empty($body) || !isset($body['success']) || !$body['success']) {
             // Иногда API возвращает 200 OK, но success: false
             $this->handleError($response, $body);
        }

        return new Response($body);
    }

    /**
     * Обработка ошибок API
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param array|null $body
     * @throws RetailCrmApiResponseException
     */
    protected function handleError($response, $body)
    {
        $statusCode = $response->status();
        $errorMsg = $body['errorMsg'] ?? $response->reason();
        $errors = $body['errors'] ?? [];

        if ($statusCode === 404) {
             throw new RetailCrmNotFoundException($errorMsg, $statusCode, null, $body);
        }

        throw new RetailCrmApiResponseException(
            "RetailCRM API Error: {$errorMsg}",
            $statusCode,
            null,
            $body
        );
    }
}
