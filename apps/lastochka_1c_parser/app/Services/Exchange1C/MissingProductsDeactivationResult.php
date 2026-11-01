<?php

declare(strict_types=1);

namespace App\Services\Exchange1C;

/**
 * Итог одного прогона MissingProductsDeactivator.
 */
final class MissingProductsDeactivationResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly int $alive = 0,
        public readonly int $seen = 0,
        public readonly int $missing = 0,
        public readonly int $deleted = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'alive' => $this->alive,
            'seen' => $this->seen,
            'missing' => $this->missing,
            'deleted' => $this->deleted,
        ];
    }
}
