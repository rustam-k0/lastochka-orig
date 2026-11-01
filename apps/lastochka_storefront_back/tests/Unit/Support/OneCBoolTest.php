<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OneCBool;
use PHPUnit\Framework\TestCase;

final class OneCBoolTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function test_parses_bool(mixed $raw, bool $valid, ?bool $value): void
    {
        $this->assertSame([$valid, $value], OneCBool::parse($raw));
    }

    public static function values(): array
    {
        return [
            'null' => [null, true, null],
            'empty' => ['', true, null],
            'spaces' => ['   ', true, null],
            'true' => [true, true, true],
            'false' => [false, true, false],
            'int 1' => [1, true, true],
            'int 0' => [0, true, false],
            'string true' => ['true', true, true],
            'string FALSE' => [' FALSE ', true, false],
            'string 1' => ['1', true, true],
            'string 0' => ['0', true, false],
            'int 2' => [2, false, null],
            'float' => [1.0, false, null],
            'yes' => ['yes', false, null],
            'cyrillic' => ['да', false, null],
            'array' => [[true], false, null],
        ];
    }
}
