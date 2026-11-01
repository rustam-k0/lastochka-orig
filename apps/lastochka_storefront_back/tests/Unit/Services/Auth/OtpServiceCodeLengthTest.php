<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\OtpService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OtpServiceCodeLengthTest extends TestCase
{
    #[DataProvider('smsCodeLengthProvider')]
    public function test_sms_code_length_comes_from_config(mixed $configured, int $expected): void
    {
        config(['services.auth.sms_code_length' => $configured]);

        $this->assertSame($expected, app(OtpService::class)->codeLength('sms'));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function smsCodeLengthProvider(): array
    {
        return [
            'не задано: 6 по умолчанию' => [null, 6],
            'пустая строка из .env: 6 по умолчанию' => ['', 6],
            'мусор: 6 по умолчанию' => ['abc', 6],
            '4 цифры' => [4, 4],
            '5 цифр строкой из .env' => ['5', 5],
            '6 цифр' => [6, 6],
            'меньше минимума прижимается к 4' => [2, 4],
            'больше максимума прижимается к 6' => [8, 6],
        ];
    }

    public function test_call_code_length_is_always_four(): void
    {
        config(['services.auth.sms_code_length' => 6]);

        $this->assertSame(4, app(OtpService::class)->codeLength('call'));
    }
}
