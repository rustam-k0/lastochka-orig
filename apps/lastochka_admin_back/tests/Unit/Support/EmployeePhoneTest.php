<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\EmployeePhone;
use PHPUnit\Framework\TestCase;

class EmployeePhoneTest extends TestCase
{
    /** @dataProvider phones */
    public function test_normalizes_phone(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, EmployeePhone::normalize($raw));
    }

    public static function phones(): array
    {
        return [
            'plus seven formatted' => ['+7 (999) 000-11-22', '+79990001122'],
            'eight' => ['89990001122', '+79990001122'],
            'seven without plus' => ['79990001122', '+79990001122'],
            'ten digits' => ['9990001122', '+79990001122'],
            'int' => [79990001122, '+79990001122'],
            'short' => ['12345', null],
            'foreign' => ['+380501234567', null],
            'eleven digits wrong prefix' => ['19990001122', null],
            'null' => [null, null],
            'array' => [['89990001122'], null],
        ];
    }
}
