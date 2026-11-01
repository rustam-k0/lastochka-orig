<?php

namespace App\Integrations;

class TestResult
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
    ) {}
}
