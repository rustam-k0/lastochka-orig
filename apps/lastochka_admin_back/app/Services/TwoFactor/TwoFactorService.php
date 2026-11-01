<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private const CHALLENGE_TTL_MINUTES = 5;

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function getOtpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name'),
            (string) $user->email,
            $secret
        );
    }

    public function getQrSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->verifyAndGetTimestamp($secret, $code, null) !== false;
    }

    /**
     * Проверяет код и возвращает его TOTP-timestep, либо false.
     * Если передан $oldTimestamp — код принимается только если он строго новее
     * последнего использованного (защита от replay в пределах окна).
     *
     * @return int|false
     */
    public function verifyAndGetTimestamp(string $secret, string $code, ?int $oldTimestamp)
    {
        if ($code === '') {
            return false;
        }

        // google2fa при null вместо timestep возвращает true, поэтому первый код
        // проверяем как «новее нулевого» и всегда получаем число.
        return $this->engine->verifyKeyNewer($secret, $code, $oldTimestamp ?? 0, 1);
    }

    public function issueChallenge(int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $userId,
            'exp' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES)->timestamp,
        ]));
    }

    public function resolveChallenge(string $challenge): ?int
    {
        if ($challenge === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($challenge), true);
        } catch (DecryptException) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['uid'], $payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < now()->timestamp) {
            return null;
        }

        return (int) $payload['uid'];
    }
}
