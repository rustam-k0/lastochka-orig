<?php

namespace App\Integrations\Testers;

use App\Integrations\Contracts\IntegrationTester;
use App\Integrations\TestResult;

class OtpSmsTester implements IntegrationTester
{
    public function test(array $credentials, ?array $testParams = null, ?int $shopId = null): TestResult
    {
        return new TestResult(
            success: true,
            message: 'Режим разработки: OTP-код будет возвращаться в ответе API',
        );
    }
}
