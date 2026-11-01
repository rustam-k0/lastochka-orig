<?php

namespace Tests\Feature;

use App\GraphQL\Validators\Delivery\DeliveryTakeOrderValidator;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeliveryTakeOrderHeavyValidatorTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Сабкласс с контролируемыми хуками: taken/assigned/joint задаются тестом,
     * ensureOrderTakeable — публичный проброс.
     */
    private function validator(bool $taken, bool $assigned, bool $jointAllowed): object
    {
        return new class($taken, $assigned, $jointAllowed) extends DeliveryTakeOrderValidator {
            public function __construct(
                private bool $taken,
                private bool $assigned,
                private bool $jointAllowed,
            ) {}

            public function check(?Order $order): array
            {
                $errors = [];
                $this->ensureOrderTakeable($order, function ($message) use (&$errors) {
                    $errors[] = $message;
                });

                return $errors;
            }

            protected function orderAlreadyTaken(Order $order): bool { return $this->taken; }
            protected function courierAlreadyAssigned(Order $order): bool { return $this->assigned; }
            protected function isJointDeliveryAllowed(Order $order): bool { return $this->jointAllowed; }
        };
    }

    private function makeOrder(int $statusId, int $typeId = Type::DELIVERY_COURIER): Order
    {
        $order = new Order();
        $order->status_id = $statusId;
        $order->type_id = $typeId;

        return $order;
    }

    /** @test */
    public function regular_taken_order_is_rejected()
    {
        $errors = $this->validator(taken: true, assigned: false, jointAllowed: false)
            ->check($this->makeOrder(Status::ORDER_ASSEMBLED));

        $this->assertSame(['Заказ уже был взят в доставку'], $errors);
    }

    /** @test */
    public function heavy_taken_order_allows_second_courier()
    {
        $errors = $this->validator(taken: true, assigned: false, jointAllowed: true)
            ->check($this->makeOrder(Status::ORDER_ASSEMBLED));

        $this->assertSame([], $errors);
    }

    /** @test */
    public function heavy_order_rejects_same_courier_twice()
    {
        $errors = $this->validator(taken: true, assigned: true, jointAllowed: true)
            ->check($this->makeOrder(Status::ORDER_ASSEMBLED));

        $this->assertSame(['Вы уже назначены на этот заказ'], $errors);
    }

    /** @test */
    public function heavy_order_in_delivery_can_be_joined()
    {
        $errors = $this->validator(taken: true, assigned: false, jointAllowed: true)
            ->check($this->makeOrder(Status::ORDER_IN_DELIVERY));

        $this->assertSame([], $errors);
    }

    /** @test */
    public function regular_order_in_delivery_is_rejected()
    {
        $errors = $this->validator(taken: true, assigned: false, jointAllowed: false)
            ->check($this->makeOrder(Status::ORDER_IN_DELIVERY));

        $this->assertSame(['Заказ еще не готов к доставке'], $errors);
    }

    /** @test */
    public function pickup_is_rejected_even_for_heavy()
    {
        $errors = $this->validator(taken: false, assigned: false, jointAllowed: true)
            ->check($this->makeOrder(Status::ORDER_ASSEMBLED, Type::DELIVERY_PICKUP));

        $this->assertSame(['Заказ оформлен на самовывоз и не назначается курьеру'], $errors);
    }
}
