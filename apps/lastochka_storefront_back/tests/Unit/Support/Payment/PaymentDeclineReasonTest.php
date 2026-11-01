<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Payment;

use App\Support\Payment\PaymentDeclineReason;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentDeclineReasonTest extends TestCase
{
    #[Test]
    public function it_maps_insufficient_funds_to_human_message(): void
    {
        $this->assertSame(
            'Недостаточно средств на карте',
            PaymentDeclineReason::message('insufficient_funds'),
        );
    }

    #[Test]
    public function it_maps_known_reasons(): void
    {
        $this->assertSame('Срок действия карты истёк', PaymentDeclineReason::message('card_expired'));
        $this->assertSame('Неверный номер карты', PaymentDeclineReason::message('invalid_card_number'));
        $this->assertSame('Платёж отклонён банком', PaymentDeclineReason::message('general_decline'));
        $this->assertSame('Платёж отклонён банком', PaymentDeclineReason::message('call_issuer'));
        $this->assertSame('Операции по карте ограничены банком', PaymentDeclineReason::message('payment_method_restricted'));
        $this->assertSame('Платёж отклонён: подозрение в мошенничестве', PaymentDeclineReason::message('fraud_suspected'));
    }

    #[Test]
    public function it_falls_back_for_unknown_or_null_reason(): void
    {
        $this->assertSame('Платёж отклонён', PaymentDeclineReason::message('some_new_reason'));
        $this->assertSame('Платёж отклонён', PaymentDeclineReason::message(null));
    }

    #[Test]
    public function it_normalizes_blank_reason_to_null_code(): void
    {
        // Пустая строка от ЮKassa = причина не указана → код null (мобилке нечего матчить).
        $this->assertNull(PaymentDeclineReason::normalize(''));
        $this->assertNull(PaymentDeclineReason::normalize(null));
        $this->assertSame('insufficient_funds', PaymentDeclineReason::normalize('insufficient_funds'));
    }
}
