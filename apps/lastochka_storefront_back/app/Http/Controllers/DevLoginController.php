<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DevLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /dev-login/{nonce}?expires=&signature= (подпись проверяет DevLoginService).
 *
 * Гасит одноразовый nonce, выпускает Sanctum-токен и редиректит на витрину с
 * токеном во фрагменте URL (`{redirect}#token=…`). Куку `auth_token` кладёт сама
 * витрина через свой useCookie, то есть тем же путём, что и после входа по SMS.
 *
 * Сервер куку не ставит намеренно: витрина и API живут на разных поддоменах, и
 * кука с Domain=.mendev.ru от API сосуществовала с host-only кукой витрины как
 * вторая кука с тем же именем. Браузер отправлял более старую (domain) первой,
 * h3 брал первое значение, и как только её токен протухал (logout, повторный
 * dev-login), витрина получала 401 на profile после каждого входа по SMS, а
 * стереть могла только свою host-only куку. Фрагмент на сервер не уходит: токена
 * нет ни в логах nginx, ни в SSR-запросе.
 */
final class DevLoginController extends Controller
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

        // rawurlencode: в plainTextToken есть `|`, во фрагменте он обязан быть закодирован;
        // витрина разбирает фрагмент через URLSearchParams и получает `|` обратно
        return redirect()->to((string) config('dev_login.redirect').'#token='.rawurlencode($token));
    }
}
