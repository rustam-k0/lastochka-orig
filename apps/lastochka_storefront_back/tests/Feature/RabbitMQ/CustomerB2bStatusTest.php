<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\User;
use App\RabbitMQ\Transformers\CustomerB2bStatusTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerB2bStatusTest extends TestCase
{
    use RefreshDatabase;

    private CustomerB2bStatusTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new CustomerB2bStatusTransformer;
    }

    public function test_updates_existing_user_b2b_status(): void
    {
        $user = User::factory()->create(['phone' => '+79001234567', 'is_b2b' => false]);

        $eventData = [
            'phone' => '+79001234567',
            'is_b2b' => true,
        ];

        $entity = $this->transformer->findExistingEntity($eventData, []);
        $this->assertNotNull($entity);
        $this->assertEquals($user->id, $entity->id);

        $transformed = $this->transformer->fromEvent($eventData);
        $entity->update($transformed);

        $this->assertTrue($entity->fresh()->is_b2b);
    }

    public function test_creates_user_if_not_exists(): void
    {
        $eventData = [
            'phone' => '+79009999999',
            'is_b2b' => true,
        ];

        // Пользователя с таким телефоном нет: findExistingEntity заводит
        // минимальную запись сам (withoutEvents, чтобы не публиковать обратно
        // customer.created с пустыми полями), а не возвращает null.
        $entity = $this->transformer->findExistingEntity($eventData, []);

        $this->assertNotNull($entity);
        $this->assertTrue($entity->wasRecentlyCreated);

        $transformed = $this->transformer->fromEvent($eventData);
        $entity->update($transformed);

        $this->assertDatabaseHas('users', [
            'phone' => '+79009999999',
            'is_b2b' => true,
        ]);
        $this->assertSame(1, User::where('phone', '+79009999999')->count());
    }

    public function test_removes_b2b_status(): void
    {
        $user = User::factory()->b2b()->create(['phone' => '+79001234567']);
        $this->assertTrue($user->is_b2b);

        $eventData = [
            'phone' => '+79001234567',
            'is_b2b' => false,
        ];

        $entity = $this->transformer->findExistingEntity($eventData, []);
        $transformed = $this->transformer->fromEvent($eventData);
        $entity->update($transformed);

        $this->assertFalse($entity->fresh()->is_b2b);
    }

    public function test_validates_required_fields(): void
    {
        $this->assertTrue($this->transformer->validate([
            'phone' => '+79001234567',
            'is_b2b' => true,
        ]));

        $this->assertFalse($this->transformer->validate([
            'phone' => '+79001234567',
        ]));

        $this->assertFalse($this->transformer->validate([
            'is_b2b' => true,
        ]));
    }
}
