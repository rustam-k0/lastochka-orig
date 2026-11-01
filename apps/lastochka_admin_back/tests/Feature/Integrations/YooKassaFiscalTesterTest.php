<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Testers\YooKassaFiscalTester;
use App\Models\IntegrationConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YooKassaFiscalTesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        Schema::create('integration_configs', function (Blueprint $t) {
            $t->id();
            $t->string('integration_type');
            $t->string('driver')->nullable();
            $t->text('credentials')->nullable();
            $t->boolean('is_active')->default(false);
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->text('test_error')->nullable();
            $t->boolean('sync_categories_enabled')->default(false);
            $t->boolean('sync_images_enabled')->default(false);
            $t->timestamps();
        });
    }

    private function createPaymentConfig(array $yooKassaCredentials, bool $isActive = true): IntegrationConfig
    {
        return IntegrationConfig::create([
            'integration_type' => 'payment',
            'driver' => 'yookassa',
            'credentials' => ['yookassa' => $yooKassaCredentials],
            'is_active' => $isActive,
        ]);
    }

    public function test_no_active_payment_integration_fails(): void
    {
        Http::fake();

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertSame('Подключите оплату ЮKassa', $result->message);
        Http::assertNothingSent();
    }

    public function test_inactive_payment_integration_is_ignored(): void
    {
        Http::fake();

        $this->createPaymentConfig(['mode' => 'live', 'live_shop_id' => 'shop1', 'live_secret_key' => 'secret1'], isActive: false);

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertSame('Подключите оплату ЮKassa', $result->message);
        Http::assertNothingSent();
    }

    public function test_test_mode_fails_without_http_call(): void
    {
        Http::fake();

        $this->createPaymentConfig(['mode' => 'test', 'live_shop_id' => 'shop1', 'live_secret_key' => 'secret1']);

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertSame('Включите боевой режим ЮKassa', $result->message);
        Http::assertNothingSent();
    }

    public function test_fiscalization_disabled_on_cashbox_fails(): void
    {
        Http::fake([
            '*/v3/me' => Http::response(['fiscalization_enabled' => false], 200),
        ]);

        $this->createPaymentConfig(['mode' => 'live', 'live_shop_id' => 'shop1', 'live_secret_key' => 'secret1']);

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertSame('Фискализация не активирована в кассе ЮKassa', $result->message);
    }

    public function test_all_checks_pass_returns_success(): void
    {
        Http::fake([
            '*/v3/me' => Http::response(['fiscalization_enabled' => true], 200),
        ]);

        $this->createPaymentConfig(['mode' => 'live', 'live_shop_id' => 'shop1', 'live_secret_key' => 'secret1']);

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertTrue($result->success);
        $this->assertSame('Фискализация ЮKassa активна', $result->message);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.yookassa.ru/v3/me';
        });
    }

    public function test_hits_v3_me_with_live_credentials_basic_auth(): void
    {
        Http::fake([
            '*/v3/me' => Http::response(['fiscalization_enabled' => true], 200),
        ]);

        $this->createPaymentConfig(['mode' => 'live', 'live_shop_id' => 'shop_live_1', 'live_secret_key' => 'secret_live_1']);

        (new YooKassaFiscalTester())->test([]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('shop_live_1:secret_live_1'));
        });
    }

    public function test_connection_exception_is_caught_as_failure(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $this->createPaymentConfig(['mode' => 'live', 'live_shop_id' => 'shop1', 'live_secret_key' => 'secret1']);

        $result = (new YooKassaFiscalTester())->test([]);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }
}
