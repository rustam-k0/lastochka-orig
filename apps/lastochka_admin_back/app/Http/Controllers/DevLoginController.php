<?php

namespace App\Http\Controllers;

use App\Services\DevLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /action/dev-login/{nonce}?expires=&signature= (подпись проверяет DevLoginService).
 *
 * Гасит одноразовый nonce, выпускает Sanctum-токен и кладёт его в JS-куку
 * `admin_token` ровно так же, как это делает admin_front после обычного логина
 * (js-cookie: без HttpOnly, SameSite=Strict, session-cookie), затем редиректит
 * в админку. Кука в открытом виде: исключение в EncryptCookies::$except.
 */
class DevLoginController extends Controller
{
    public function __invoke(Request $request, string $nonce, DevLoginService $service): RedirectResponse
    {
        // Гейт на рантайме: даже если роут попал в route:cache, без флага или на чужом хосте это 404
        abort_unless($service->isAvailable(), 404);

        // Подпись и срок: 403 до обращения к кэшу, чтобы подделка не гасила чужой nonce
        abort_unless(
            $service->hasValidSignature($nonce, $request->query('expires'), $request->query('signature')),
            403,
            'Ссылка недействительна или просрочена.'
        );

        $user = $service->consume($nonce);

        abort_if($user === null, 403, 'Ссылка недействительна или уже использована.');

        $token = $service->issueToken($user);

        $cookie = cookie(
            name: config('dev_login.cookie'),
            value: $token,
            minutes: 0,
            path: '/',
            domain: config('dev_login.cookie_domain') ?: null,
            secure: $request->isSecure(),
            httpOnly: false,
            raw: false,
            sameSite: 'strict',
        );

        return redirect(config('dev_login.redirect'))->withCookie($cookie);
    }
}
