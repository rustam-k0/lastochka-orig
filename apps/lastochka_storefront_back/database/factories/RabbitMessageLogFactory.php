<?php

declare(strict_types=1);

namespace Database\Factories;

use App\RabbitMQ\Debug\RabbitMessageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RabbitMessageLog>
 */
final class RabbitMessageLogFactory extends Factory
{
    protected $model = RabbitMessageLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => $this->faker->randomElement(['inbound', 'outbound']),
            'routing_key' => $this->faker->randomElement([
                'product.created',
                'product.updated',
                'category.created',
                'order.order_created',
                'customer.created',
            ]),
            'queue_name' => $this->faker->optional()->randomElement([
                'storefront_products',
                'storefront_categories',
                'storefront_orders',
            ]),
            'payload' => [
                'id' => $this->faker->uuid(),
                'event_id' => $this->faker->uuid(),
                'data' => ['key' => $this->faker->word()],
            ],
            'entity_type' => $this->faker->optional()->randomElement([
                'products',
                'categories',
                'orders',
                'customers',
            ]),
            'entity_id' => $this->faker->optional()->numberBetween(1, 1000),
            'external_id' => $this->faker->uuid(),
            'status' => $this->faker->randomElement(['pending', 'processed', 'failed']),
            'error_message' => null,
            'error_trace' => null,
            'db_snapshot' => null,
            'processing_time_ms' => $this->faker->optional()->numberBetween(10, 500),
            'created_at' => $this->faker->dateTimeBetween('-1 day', 'now'),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'inbound',
            'queue_name' => 'storefront_products',
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'outbound',
            'queue_name' => null,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processed',
            'error_message' => null,
            'error_trace' => null,
            'db_snapshot' => [
                'table' => 'products',
                'id' => $this->faker->numberBetween(1, 1000),
                'data' => ['title' => $this->faker->words(3, true)],
            ],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => $this->faker->sentence(),
            'error_trace' => $this->faker->paragraphs(3, true),
            'db_snapshot' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'error_message' => null,
            'error_trace' => null,
            'db_snapshot' => null,
            'processing_time_ms' => null,
        ]);
    }
}
