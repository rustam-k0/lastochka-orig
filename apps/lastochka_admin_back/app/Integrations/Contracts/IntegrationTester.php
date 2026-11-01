<?php

namespace App\Integrations\Contracts;

use App\Integrations\TestResult;

interface IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult;
}
