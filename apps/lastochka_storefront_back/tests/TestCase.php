<?php

namespace Tests;

use App\Jobs\FiscalizeRefundJob;
use App\Jobs\FiscalizeSaleJob;
use App\Jobs\PollAtolReceiptJob;
use App\Jobs\SendReceiptReadyNotification;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Фискализация — асинхронна; в тестах не исполняем её инлайн (sync-queue),
        // иначе cash+delivered (дефолт фабрики) бил бы реальный HTTP к ЮKassa/АТОЛ.
        // Тесты фискализации вызывают handle()/assertPushed() и переопределяют это сами.
        Queue::fake([
            FiscalizeSaleJob::class,
            FiscalizeRefundJob::class,
            PollAtolReceiptJob::class,
            SendReceiptReadyNotification::class,
        ]);
    }

    /**
     * Получить валидный слот доставки для тестов.
     * FakeDeliveryClient генерирует слоты с 8:00 до 22:00 каждые 2 часа.
     */
    protected function getValidDeliverySlotId(): int
    {
        return now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->timestamp;
    }
}
