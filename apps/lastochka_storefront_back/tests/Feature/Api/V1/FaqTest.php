<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_faq_list(): void
    {
        // Arrange
        Faq::factory()->create(['question' => 'Второй вопрос', 'position' => 2]);
        Faq::factory()->create(['question' => 'Первый вопрос', 'position' => 1]);

        // Act
        $response = $this->getJson('/api/v1/faq');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.question', 'Первый вопрос')
            ->assertJsonPath('data.1.question', 'Второй вопрос')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'question', 'answer'],
                ],
            ]);
    }

    public function test_get_faq_excludes_inactive(): void
    {
        // Arrange
        Faq::factory()->create();
        Faq::factory()->inactive()->create();

        // Act
        $response = $this->getJson('/api/v1/faq');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_null_position_goes_last(): void
    {
        // Arrange
        Faq::factory()->create(['question' => 'Без позиции', 'position' => null]);
        Faq::factory()->create(['question' => 'С позицией', 'position' => 5]);

        // Act
        $response = $this->getJson('/api/v1/faq');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.question', 'С позицией')
            ->assertJsonPath('data.1.question', 'Без позиции');
    }
}
