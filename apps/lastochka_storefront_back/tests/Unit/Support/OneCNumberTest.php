<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OneCNumber;
use PHPUnit\Framework\TestCase;

final class OneCNumberTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function test_parses_number(mixed $raw, float|false|null $expected): void
    {
        $this->assertSame($expected, OneCNumber::parse($raw));
    }

    public static function values(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'int' => [12, 12.0],
            'comma string' => ['12,5', 12.5],
            'thousands space' => ['1 234,5', 1234.5],
            'text' => ['много', false],
            'bool' => [true, false],
        ];
    }
}
