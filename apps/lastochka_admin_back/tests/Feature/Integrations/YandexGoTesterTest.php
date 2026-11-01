<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Testers\YandexGoTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexGoTesterTest extends TestCase
{
    public function test_sends_skip_door_to_door_true_and_reports_success(): void
    {
        Http::fake([
            '*/delivery-methods' => Http::response(['express_delivery' => ['allowed' => true]], 200),
            '*/check-price' => Http::response(['price' => '500.0', 'zone_id' => 'moscow'], 200),
        ]);

        $result = (new YandexGoTester())->test(['oauth_token' => 'test_token']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('moscow', (string) $result->message);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/check-price')
                && $request['skip_door_to_door'] === true;
        });
    }

    public function test_treats_409_suitable_offer_not_found_as_connection_ok(): void
    {
        // delivery-methods 200 = связь подтверждена. check-price 409 — реал-тайм отсутствие
        // курьерского предложения для тестового маршрута, НЕ ошибка подключения.
        Http::fake([
            '*/delivery-methods' => Http::response(['express_delivery' => ['allowed' => true]], 200),
            '*/check-price' => Http::response(['code' => 'errors.suitable_offer_not_found', 'message' => 'errors.suitable_offer_not_found'], 409),
        ]);

        $result = (new YandexGoTester())->test(['oauth_token' => 'test_token']);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Подключение успешно', (string) $result->message);
    }

    public function test_invalid_token_on_delivery_methods_fails(): void
    {
        Http::fake([
            '*/delivery-methods' => Http::response(['code' => 'unauthorized'], 401),
        ]);

        $result = (new YandexGoTester())->test(['oauth_token' => 'bad_token']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Невалидный', (string) $result->message);
    }

    public function test_delivery_methods_server_error_fails(): void
    {
        Http::fake([
            '*/delivery-methods' => Http::response(['code' => 'internal'], 500),
        ]);

        $result = (new YandexGoTester())->test(['oauth_token' => 'test_token']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('delivery-methods', (string) $result->message);
    }

    public function test_missing_token_fails_fast(): void
    {
        $result = (new YandexGoTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('OAuth', (string) $result->message);
    }
}
