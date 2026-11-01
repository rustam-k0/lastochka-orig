<?php

declare(strict_types=1);

namespace App\DTOs\Store;

final readonly class WorkingHours
{
    public function __construct(
        public int $openingHour,
        public int $openingMinute,
        public int $closingHour,
        public int $closingMinute,
    ) {}
}
