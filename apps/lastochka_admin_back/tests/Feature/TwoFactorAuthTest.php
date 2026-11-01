<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeUser(string $role = Role::ADMIN, string $password = 'password123'): User
    {
        $user = User::create([
            'name'     => 'TwoFA',
            'surname'  => 'Tester',
            'email'    => 'twofa_' . uniqid() . '@example.com',
            'phone'    => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt($password),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function login(User $user, string $password = 'password123')
    {
        $mutation = '
            mutation ($email: String, $password: String) {
                AdminLogin(email: $email, password: $password) {
                    code token two_factor challenge two_factor_secret otpauth_url qr
                }
            }
        ';

        return $this->graphQL($mutation, ['email' => $user->email, 'password' => $password]);
    }

    private function verify(string $challenge, string $code)
    {
        $mutation = '
            mutation ($challenge: String, $code: String) {
                VerifyTwoFactor(challenge: $challenge, code: $code) {
                    code token two_factor
                }
            }
        ';

        return $this->graphQL($mutation, ['challenge' => $challenge, 'code' => $code]);
    }

    /** @test */
    public function flag_off_issues_token_immediately()
    {
        AdminSetting::setValue('admin_2fa_enabled', '0', 'boolean');
        $user = $this->makeUser();

        $response = $this->login($user);

        $response->assertJsonPath('data.AdminLogin.two_factor', 'disabled');
        $this->assertNotEmpty($response->json('data.AdminLogin.token'));
    }

    /** @test */
    public function flag_on_without_2fa_returns_setup_challenge()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();

        $response = $this->login($user);

        $response->assertJsonPath('data.AdminLogin.two_factor', 'setup');
        $this->assertNull($response->json('data.AdminLogin.token'));
        $this->assertNotEmpty($response->json('data.AdminLogin.challenge'));
        $this->assertNotEmpty($response->json('data.AdminLogin.two_factor_secret'));
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    /** @test */
    public function flag_on_with_active_2fa_returns_required_challenge()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $response = $this->login($user);

        $response->assertJsonPath('data.AdminLogin.two_factor', 'required');
        $this->assertNull($response->json('data.AdminLogin.token'));
        $this->assertNotEmpty($response->json('data.AdminLogin.challenge'));
        $this->assertNull($response->json('data.AdminLogin.two_factor_secret'));
    }

    /** @test */
    public function api_only_account_bypasses_2fa()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser(Role::API);

        $response = $this->login($user);

        $response->assertJsonPath('data.AdminLogin.two_factor', 'disabled');
        $this->assertNotEmpty($response->json('data.AdminLogin.token'));
    }

    /** @test */
    public function admin_with_extra_api_role_still_requires_2fa()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser(Role::ADMIN);
        $user->assignRole(Role::API);

        $response = $this->login($user);

        $response->assertJsonPath('data.AdminLogin.two_factor', 'setup');
        $this->assertNull($response->json('data.AdminLogin.token'));
    }

    /** @test */
    public function verify_with_valid_code_during_setup_confirms_and_issues_token()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();

        $login = $this->login($user);
        $challenge = $login->json('data.AdminLogin.challenge');
        $secret = $login->json('data.AdminLogin.two_factor_secret');
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->verify($challenge, $code);

        $response->assertJsonPath('data.VerifyTwoFactor.two_factor', 'verified');
        $this->assertNotEmpty($response->json('data.VerifyTwoFactor.token'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    /** @test */
    public function verify_with_valid_code_when_active_issues_token()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $challenge = app(TwoFactorService::class)->issueChallenge($user->id);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $response = $this->verify($challenge, $code);

        $this->assertNotEmpty($response->json('data.VerifyTwoFactor.token'));
    }

    /** @test */
    public function verify_rejects_replayed_code()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        $svc = app(TwoFactorService::class);
        $code = (new Google2FA())->getCurrentOtp($secret);

        $first = $this->verify($svc->issueChallenge($user->id), $code);
        $this->assertNotEmpty($first->json('data.VerifyTwoFactor.token'));

        $second = $this->verify($svc->issueChallenge($user->id), $code);
        $second->assertGraphQLErrorMessage('Неверный код.');
    }

    /** @test */
    public function verify_with_invalid_code_errors_and_issues_no_token()
    {
        AdminSetting::setValue('admin_2fa_enabled', '1', 'boolean');
        $user = $this->makeUser();
        $challenge = $this->login($user)->json('data.AdminLogin.challenge');

        $response = $this->verify($challenge, '000000');

        $response->assertGraphQLErrorMessage('Неверный код.');
        $this->assertNull($response->json('data.VerifyTwoFactor'));
    }

    /** @test */
    public function verify_with_garbage_challenge_errors()
    {
        $response = $this->verify('garbage', '123456');
        $response->assertGraphQLErrorMessage('Сессия подтверждения истекла. Войдите заново.');
    }

    /** @test */
    public function admin_can_reset_user_two_factor()
    {
        $admin = $this->makeUser(Role::ADMIN);
        $target = $this->makeUser(Role::SHOP);
        $secret = app(TwoFactorService::class)->generateSecret();
        $target->two_factor_secret = $secret;
        $target->two_factor_confirmed_at = now();
        $target->save();

        $mutation = '
            mutation ($id: Int!) {
                ResetUserTwoFactor(id: $id) { code message }
            }
        ';

        $response = $this->actingAs($admin)->graphQL($mutation, ['id' => $target->id]);

        $response->assertJsonPath('data.ResetUserTwoFactor.code', 200);
        $this->assertNull($target->fresh()->two_factor_secret);
        $this->assertNull($target->fresh()->two_factor_confirmed_at);
    }

    /** @test */
    public function user_type_exposes_two_factor_enabled()
    {
        $admin = $this->makeUser(Role::ADMIN);
        $target = $this->makeUser(Role::SHOP);
        $target->two_factor_secret = app(TwoFactorService::class)->generateSecret();
        $target->two_factor_confirmed_at = now();
        $target->save();

        $query = '
            query ($id: Int) { user(id: $id) { id two_factor_enabled } }
        ';

        $response = $this->actingAs($admin)->graphQL($query, ['id' => $target->id]);

        $response->assertJsonPath('data.user.two_factor_enabled', true);
    }

    /** @test */
    public function artisan_command_resets_two_factor()
    {
        $user = $this->makeUser(Role::ADMIN);
        $user->two_factor_secret = app(TwoFactorService::class)->generateSecret();
        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->artisan('two-factor:reset', ['email' => $user->email])
            ->assertExitCode(0);

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }
}
