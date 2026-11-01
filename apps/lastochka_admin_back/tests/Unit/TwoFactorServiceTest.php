<?php

namespace Tests\Unit;

use App\Services\TwoFactor\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwoFactorService();
    }

    /** @test */
    public function it_generates_secret_and_verifies_current_code()
    {
        $secret = $this->service->generateSecret();
        $this->assertNotEmpty($secret);

        $code = (new Google2FA())->getCurrentOtp($secret);
        $this->assertTrue($this->service->verify($secret, $code));
    }

    /** @test */
    public function it_rejects_wrong_and_empty_code()
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verify($secret, '000000'));
        $this->assertFalse($this->service->verify($secret, ''));
    }

    /** @test */
    public function it_returns_timestep_for_first_code_without_previous_timestamp()
    {
        $secret = $this->service->generateSecret();
        $engine = new Google2FA();
        $code = $engine->getCurrentOtp($secret);

        $timestamp = $this->service->verifyAndGetTimestamp($secret, $code, null);

        // Иначе в two_factor_last_otp_timestamp уходит true (=1) и тот же код проходит повторно.
        $this->assertIsInt($timestamp);
        $this->assertFalse($this->service->verifyAndGetTimestamp($secret, $code, $timestamp));
    }

    /** @test */
    public function it_issues_and_resolves_challenge()
    {
        $challenge = $this->service->issueChallenge(42);
        $this->assertSame(42, $this->service->resolveChallenge($challenge));
    }

    /** @test */
    public function it_rejects_garbage_challenge()
    {
        $this->assertNull($this->service->resolveChallenge('not-a-valid-token'));
        $this->assertNull($this->service->resolveChallenge(''));
    }

    /** @test */
    public function it_builds_qr_data_uri()
    {
        $secret = $this->service->generateSecret();
        $url = 'otpauth://totp/Test:a@b.c?secret=' . $secret . '&issuer=Test';
        $svg = $this->service->getQrSvg($url);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $svg);
    }
}
