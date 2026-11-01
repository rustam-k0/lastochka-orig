<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery\Pickup;

use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PickupRussianPostTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeSlotsApiClient
    {
        /** @var FakeSlotsApiClient */
        return app(SlotsApiClientInterface::class);
    }

    // ==================== Russian Post Offices List ====================

    public function test_returns_rp_offices_list(): void
    {
        $this->fake()->setRpOffices([
            [
                'provider' => 'russian_post',
                'external_id' => '367000',
                'code' => '367000',
                'title' => 'Почта России 367000',
                'address' => 'пр. Гамидова, 33',
                'city' => 'Махачкала',
                'postalCode' => '367000',
                'lat' => 42.97,
                'lng' => 47.49,
                'workTime' => '08:00-20:00',
                'phone' => '+78722123456',
                'type' => 'ГОПС',
            ],
        ]);

        $response = $this->getJson('/api/v1/pickup/russianpost?q=Махачкала');

        $response->assertOk()
            ->assertJsonPath('data.0.code', '367000')
            ->assertJsonPath('data.0.provider', 'russian_post');
    }

    public function test_returns_empty_rp_offices_list(): void
    {
        $this->fake()->setRpOffices([]);

        $response = $this->getJson('/api/v1/pickup/russianpost');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rp_offices_degradation_on_failure(): void
    {
        $this->fake()->setShouldFail(true);

        $response = $this->getJson('/api/v1/pickup/russianpost');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ==================== Russian Post Office Show ====================

    public function test_returns_rp_office_by_index(): void
    {
        $this->fake()->setRpOffice([
            'provider' => 'russian_post',
            'code' => '367000',
            'title' => 'Почта России 367000',
            'lat' => 42.97,
            'lng' => 47.49,
        ]);

        $response = $this->getJson('/api/v1/pickup/russianpost/367000');

        $response->assertOk()
            ->assertJsonPath('data.code', '367000');
    }

    public function test_returns_404_when_rp_office_not_found(): void
    {
        $this->fake()->setRpOffice(null, notFound: true);

        $response = $this->getJson('/api/v1/pickup/russianpost/999999');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Отделение Почты России не найдено');
    }

    public function test_rp_index_route_accepts_only_6_digits(): void
    {
        $response = $this->getJson('/api/v1/pickup/russianpost/12345');

        $response->assertNotFound();
    }
}
