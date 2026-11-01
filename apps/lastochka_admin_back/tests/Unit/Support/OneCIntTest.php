<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OneCInt;
use PHPUnit\Framework\TestCase;

class OneCIntTest extends TestCase
{
    /** @dataProvider values */
    public function test_parses_int(mixed $raw, bool $valid, ?int $value): void
    {
        $this->assertSame([$valid, $value], OneCInt::parse($raw, 0, 100));
    }

    public static function values(): array
    {
        return [
            'null' => [null, true, null],
            'empty' => ['', true, null],
            'spaces' => ['  ', true, null],
            'int' => [5, true, 5],
            'min' => [0, true, 0],
            'max' => [100, true, 100],
            'string' => [' 42 ', true, 42],
            'integral float' => [7.0, true, 7],
            'below min' => [-1, false, null],
            'above max' => ['101', false, null],
            'fraction' => [12.5, false, null],
            'fraction string' => ['12.5', false, null],
            'text' => ['пять', false, null],
            'bool' => [true, false, null],
            'array' => [[1], false, null],
        ];
    }

    public function test_without_upper_bound(): void
    {
        $this->assertSame([true, 4294967295], OneCInt::parse('4294967295', 0));
        $this->assertSame([false, null], OneCInt::parse(-5, 0));
    }
}
