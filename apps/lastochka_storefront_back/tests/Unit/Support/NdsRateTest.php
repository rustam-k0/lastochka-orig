<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NdsRate;
use PHPUnit\Framework\TestCase;

final class NdsRateTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('rates')]
    public function test_maps_rate_to_vat_code(mixed $raw, int|false|null $expected): void
    {
        $this->assertSame($expected, NdsRate::toVatCode($raw));
    }

    public static function rates(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'spaces' => ['   ', null],
            'без НДС' => ['Без НДС', 1],
            'не облагается' => ['Не облагается', 1],
            'zero int' => [0, 2],
            'zero string' => ['0%', 2],
            '10' => [10, 3],
            '10 percent' => ['10%', 3],
            '18' => ['18', 4],
            '20' => ['20', 4],
            '22 int' => [22, 11],
            '22 percent nbsp' => ["22\u{00A0}%", 11],
            '22.0' => ['22.0', 11],
            '22,0' => ['22,0', 11],
            '10/110' => ['10/110', 5],
            '20/120' => ['20/120', 6],
            '18/118' => ['18/118', 6],
            '22/122' => ['22/122', 12],
            '5 unsupported' => ['5', false],
            '7 unsupported' => ['7%', false],
            'garbage' => ['НДС', false],
            'bool' => [true, false],
            'array' => [[22], false],
        ];
    }
}
