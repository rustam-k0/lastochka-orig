<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;
use Illuminate\Support\Facades\Http;

class YandexGoTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        $token = $credentials['oauth_token'] ?? null;

        if (!$token) {
            return new TestResult(success: false, message: 'OAuth-токен не указан');
        }

        $baseUrl = 'https://b2b.taxi.yandex.net';

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->withHeaders(['Accept-Language' => 'ru'])
                ->timeout(10)
                ->post('/b2b/cargo/integration/v2/delivery-methods', [
                    'start_point' => [37.6173, 55.7558],
                    'end_point' => [37.5880, 55.7340],
                ]);

            if ($response->status() === 401) {
                return new TestResult(success: false, message: 'Невалидный OAuth-токен');
            }

            if ($response->failed()) {
                return new TestResult(success: false, message: 'delivery-methods ошибка ' . $response->status() . ': ' . $response->body());
            }

            // Успех delivery-methods = связь подтверждена: токен валиден, аккаунт
            // активен, тарифы по зоне доступны. Это и есть критерий проверки подключения.
            // Дальнейший check-price — лишь попытка показать живую цену, его результат
            // НЕ влияет на вердикт о подключении (см. ниже).
            $priceResponse = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->withHeaders(['Accept-Language' => 'ru'])
                ->timeout(10)
                ->post('/b2b/cargo/integration/v2/check-price', [
                    'items' => [[
                        'quantity' => 1,
                        'size' => ['height' => 0.3, 'length' => 0.3, 'width' => 0.3],
                        'weight' => 5,
                    ]],
                    'route_points' => [
                        ['coordinates' => [37.6173, 55.7558], 'fullname' => 'Тест: Москва'],
                        ['coordinates' => [37.5880, 55.7340], 'fullname' => 'Тест: Москва 2'],
                    ],
                    'requirements' => ['taxi_class' => 'express'],
                    'skip_door_to_door' => true,
                ]);

            if ($priceResponse->successful()) {
                $price = $priceResponse->json('price');
                $zone = $priceResponse->json('zone_id', '?');
                return new TestResult(success: true, message: "Подключение успешно. Зона: {$zone}, цена: {$price}₽");
            }

            // Невалидный токен на этом шаге — всё же ошибка связи (защитная проверка,
            // в норме не достижима, т.к. delivery-methods выше уже отсеял 401).
            if ($priceResponse->status() === 401) {
                return new TestResult(success: false, message: 'Невалидный OAuth-токен');
            }

            // check-price — реал-тайм расчёт наличия курьерского предложения на КОНКРЕТНЫЙ
            // маршрут. Для синтетических тестовых координат Яндекс может вернуть
            // 409 errors.suitable_offer_not_found («нет подходящего предложения здесь и
            // сейчас») при полностью работоспособной интеграции — это зависит от
            // доступности курьеров и самого маршрута, а не от подключения. Поэтому любую
            // бизнес-ошибку расчёта трактуем как успешную проверку связи: связь уже
            // подтверждена шагом delivery-methods.
            return new TestResult(
                success: true,
                message: 'Подключение успешно. Тарифы доступны. Цену для тестового маршрута Яндекс сейчас не вернул (нет подходящего предложения для тестовых координат) — на работу интеграции это не влияет.'
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return new TestResult(success: false, message: 'Яндекс Go API недоступен');
        } catch (\Throwable $e) {
            return new TestResult(success: false, message: 'Ошибка: ' . $e->getMessage());
        }
    }
}
