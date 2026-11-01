<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\CannotReviewException;
use App\Exceptions\DeliverySlotUnavailableException;
use App\Exceptions\DeliverySystemException;
use App\Exceptions\DeliveryZoneNotFoundException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\FavoriteLimitException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOtpException;
use App\Exceptions\InvalidPromocodeException;
use App\Exceptions\InvalidShippingAddressException;
use App\Exceptions\MinOrderAmountException;
use App\Exceptions\OrderAlreadyHasReviewException;
use App\Exceptions\OrderCannotBeCancelledException;
use App\Exceptions\OrderCannotBeSupplementedException;
use App\Exceptions\OrderReviewNotOwnedException;
use App\Exceptions\OrderSupplementSlotExpiredException;
use App\Exceptions\OtpAttemptsExceededException;
use App\Exceptions\OtpCodeMismatchException;
use App\Exceptions\OtpExpiredOrUsedException;
use App\Exceptions\OtpRateLimitException;
use App\Exceptions\ProductHasNoCategoryException;
use App\Exceptions\ReviewAlreadyExistsException;
use App\Exceptions\StoreAccessDeniedException;
use App\Exceptions\StoreInactiveException;
use App\Exceptions\YandexDeliveryException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Tests\TestCase;

final class BusinessExceptionsNotReportableTest extends TestCase
{
    /**
     * Ожидаемые бизнес-ошибки (4xx для клиента) — не должны попадать в GlitchTip.
     *
     * @var list<class-string>
     */
    private const BUSINESS_EXCEPTIONS = [
        CannotReviewException::class,
        DeliverySlotUnavailableException::class,
        DeliveryZoneNotFoundException::class,
        EmptyCartException::class,
        FavoriteLimitException::class,
        InsufficientStockException::class,
        InvalidOtpException::class,
        InvalidPromocodeException::class,
        InvalidShippingAddressException::class,
        MinOrderAmountException::class,
        OrderAlreadyHasReviewException::class,
        OrderCannotBeCancelledException::class,
        OrderCannotBeSupplementedException::class,
        OrderReviewNotOwnedException::class,
        OrderSupplementSlotExpiredException::class,
        OtpAttemptsExceededException::class,
        OtpCodeMismatchException::class,
        OtpExpiredOrUsedException::class,
        OtpRateLimitException::class,
        ReviewAlreadyExistsException::class,
        StoreAccessDeniedException::class,
        StoreInactiveException::class,
    ];

    /**
     * Инфраструктурные ошибки и рассинхрон данных — обязаны репортиться.
     *
     * @var list<class-string>
     */
    private const REPORTABLE_EXCEPTIONS = [
        DeliverySystemException::class,
        ProductHasNoCategoryException::class,
        YandexDeliveryException::class,
    ];

    public function test_business_exceptions_implement_shouldnt_report(): void
    {
        foreach (self::BUSINESS_EXCEPTIONS as $class) {
            $this->assertTrue(
                is_subclass_of($class, ShouldntReport::class),
                "{$class} должен реализовывать ShouldntReport, чтобы не шуметь в GlitchTip",
            );
        }
    }

    public function test_infrastructure_exceptions_stay_reportable(): void
    {
        foreach (self::REPORTABLE_EXCEPTIONS as $class) {
            $this->assertFalse(
                is_subclass_of($class, ShouldntReport::class),
                "{$class} — инфраструктурная ошибка и должен репортиться в GlitchTip",
            );
        }
    }

    public function test_handler_does_not_report_min_order_amount_exception(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse(
            $handler->shouldReport(new MinOrderAmountException(minAmount: 200, currentAmount: 132.0)),
        );
    }

    public function test_handler_does_not_report_delivery_zone_not_found_exception(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse(
            $handler->shouldReport(new DeliveryZoneNotFoundException),
        );
    }
}
