<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OneCNumber;
use PHPUnit\Framework\TestCase;

class OneCNumberTest extends TestCase
{
    /** @dataProvider values */
    public function test_parses_number(mixed $raw, float|false|null $expected): void
    {
        $this->assertSame($expected, OneCNumber::parse($raw));
    }

    public static function values(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'spaces' => ['   ', null],
            'int' => [12, 12.0],
            'float' => [12.5, 12.5],
            'dot string' => ['12.5', 12.5],
            'comma string' => ['12,5', 12.5],
            'thousands space' => ['1 234,5', 1234.5],
            'thousands nbsp' => ["1\u{00A0}234", 1234.0],
            'negative' => ['-3', -3.0],
            'text' => ['много', false],
            'bool' => [true, false],
            'array' => [[1], false],
        ];
    }
}
