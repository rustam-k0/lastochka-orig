<?php

namespace App\GraphQL\Mutations\User;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactor\TwoFactorService;
use GraphQL\Error\Error;

class UserLogin
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function __invoke($_, array $args): array
    {
        $user = User::whereEmail($args['email'])->first();
        if (!$user) {
            throw new Error('Неверный логин или пароль.');
        }

        $flagEnabled = AdminSetting::getValue('admin_2fa_enabled') === '1';
        $isApiOnly = $user->hasRole(Role::API)
            && !$user->hasAnyRole([Role::ADMIN, Role::SHOP]);

        if (!$flagEnabled || $isApiOnly) {
            return $this->issueToken($user);
        }

        if ($user->two_factor_confirmed_at) {
            return [
                'code'       => 200,
                'message'    => 'Two-factor required',
                'two_factor' => 'required',
                'challenge'  => $this->twoFactor->issueChallenge($user->id),
                'token'      => null,
                'user'       => null,
            ];
        }

        $secret = $this->twoFactor->generateSecret();
        $user->two_factor_secret = $secret;
        $user->save();

        $otpauthUrl = $this->twoFactor->getOtpauthUrl($user, $secret);

        return [
            'code'              => 200,
            'message'           => 'Two-factor setup required',
            'two_factor'        => 'setup',
            'challenge'         => $this->twoFactor->issueChallenge($user->id),
            'two_factor_secret' => $secret,
            'otpauth_url'       => $otpauthUrl,
            'qr'                => $this->twoFactor->getQrSvg($otpauthUrl),
            'token'             => null,
            'user'              => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToken(User $user): array
    {
        return [
            'code'       => 200,
            'message'    => "You're logged in!",
            'two_factor' => 'disabled',
            'token'      => $user->createToken(config('app.name'))->plainTextToken,
            'user'       => $user,
        ];
    }
}
