<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GraphQL\Validators\Delivery\DeliveryTakeOrderValidator;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use ReflectionClass;
use Tests\TestCase;

final class DeliveryTakeOrderValidatorTest extends TestCase
{
    public function test_pickup_order_is_rejected_with_clear_message(): void
    {
        $order = new Order();
        $order->id = 277;
        $order->type_id = Type::DELIVERY_PICKUP;
        $order->status_id = Status::ORDER_ASSEMBLED;

        $errors = $this->invokeOrderTakeableCheck($order);

        self::assertCount(1, $errors, 'Pickup-заказ должен быть отвергнут одной ошибкой');
        self::assertStringContainsStringIgnoringCase('самовывоз', $errors[0]);
    }

    public function test_courier_order_passes_when_assembled_and_not_taken(): void
    {
        $order = new Order();
        $order->id = 278;
        $order->type_id = Type::DELIVERY_COURIER;
        $order->status_id = Status::ORDER_ASSEMBLED;

        $errors = $this->invokeOrderTakeableCheck($order);

        self::assertSame([], $errors, 'Курьерский собранный заказ должен проходить проверку');
    }

    public function test_missing_order_is_rejected(): void
    {
        $errors = $this->invokeOrderTakeableCheck(null);

        self::assertCount(1, $errors);
        self::assertStringContainsString('не найден', $errors[0]);
    }

    public function test_unassembled_order_is_rejected(): void
    {
        $order = new Order();
        $order->id = 279;
        $order->type_id = Type::DELIVERY_COURIER;
        $order->status_id = Status::ORDER_PREPARING;

        $errors = $this->invokeOrderTakeableCheck($order);

        self::assertCount(1, $errors);
        self::assertStringContainsString('готов', $errors[0]);
    }

    public function test_already_taken_order_is_rejected(): void
    {
        $order = new Order();
        $order->id = 280;
        $order->type_id = Type::DELIVERY_COURIER;
        $order->status_id = Status::ORDER_ASSEMBLED;

        $errors = $this->invokeOrderTakeableCheck($order, alreadyTaken: true);

        self::assertCount(1, $errors);
        self::assertStringContainsString('уже', $errors[0]);
    }

    /**
     * @return array<int, string>
     */
    private function invokeOrderTakeableCheck(?Order $order, bool $alreadyTaken = false): array
    {
        $validator = new class($alreadyTaken) extends DeliveryTakeOrderValidator {
            public function __construct(private readonly bool $stubAlreadyTaken)
            {
            }

            protected function orderAlreadyTaken(Order $order): bool
            {
                return $this->stubAlreadyTaken;
            }
        };

        $reflection = new ReflectionClass(DeliveryTakeOrderValidator::class);
        $method = $reflection->getMethod('ensureOrderTakeable');
        $method->setAccessible(true);

        $errors = [];
        $fail = static function (string $message) use (&$errors): void {
            $errors[] = $message;
        };

        $method->invoke($validator, $order, $fail);

        return $errors;
    }
}
