<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Fiscal;

use App\Contracts\Fiscalizer;
use App\Models\IntegrationConfig;
use App\Services\Payment\Fiscal\AtolFiscalizer;
use App\Services\Payment\Fiscal\YooKassaFiscalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalizerFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_atol_when_fiscal_driver_is_atol(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol',
            'credentials' => ['atol' => []], 'is_active' => true]);
        $this->assertInstanceOf(AtolFiscalizer::class, app(Fiscalizer::class));
    }

    public function test_resolves_yookassa_by_default(): void
    {
        $this->assertInstanceOf(YooKassaFiscalizer::class, app(Fiscalizer::class));
    }
}
