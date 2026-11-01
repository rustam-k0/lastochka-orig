<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\ProductIssueReport;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductIssueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'test@example.com']);
        $this->store = Store::factory()->create();
        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);
    }

    private function enableIssueReports(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(['product_issue_reports' => ['label' => 'Сообщить о неточности', 'enabled' => true]]),
                'type' => 'json',
            ]
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    // ==================== POST /products/{product}/report-issue ====================

    public function test_create_report_success(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => 'Ошибка в описании товара',
                'type' => 'description_error',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.productId', $this->product->id)
            ->assertJsonPath('data.email', 'test@example.com')
            ->assertJsonPath('data.type', 'description_error')
            ->assertJsonPath('data.message', 'Ошибка в описании товара');

        $this->assertDatabaseHas('product_issue_reports', [
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'type' => 'description_error',
        ]);
    }

    public function test_create_report_missing_characteristics_type(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => 'Не хватает характеристики "Вес"',
                'type' => 'missing_characteristics',
            ]
        );

        $response->assertCreated()
            ->assertJsonPath('data.type', 'missing_characteristics');
    }

    public function test_create_report_module_disabled_returns_403(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => 'Ошибка',
                'type' => 'description_error',
            ]
        );

        $response->assertForbidden();
    }

    public function test_create_report_unauthenticated_returns_401(): void
    {
        $this->enableIssueReports();

        $response = $this->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => 'Ошибка',
                'type' => 'description_error',
            ]
        );

        $response->assertUnauthorized();
    }

    public function test_create_report_validation_errors(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            []
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'message', 'type']);
    }

    public function test_create_report_invalid_type_returns_validation_error(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => 'Ошибка',
                'type' => 'invalid_type',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_create_report_message_max_200_chars(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'test@example.com',
                'message' => str_repeat('A', 201),
                'type' => 'description_error',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_create_report_invalid_email(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/products/{$this->product->id}/report-issue",
            [
                'email' => 'not-an-email',
                'message' => 'Ошибка',
                'type' => 'description_error',
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_create_report_nonexistent_product_returns_404(): void
    {
        $this->enableIssueReports();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/products/99999/report-issue',
            [
                'email' => 'test@example.com',
                'message' => 'Ошибка',
                'type' => 'description_error',
            ]
        );

        $response->assertNotFound();
    }
}
