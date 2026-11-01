<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Loyalty\LoyaltyCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoyaltyCardController extends Controller
{
    /**
     * Карта лояльности
     *
     * Статусная модель: `pending` — карта создана, но не подтверждена 1С
     * (номер и QR не отдаются), `active` — карта подтверждена.
     * При первом обращении пользователя без карты она создаётся автоматически.
     *
     * @response array{status: 'pending'|'active', cardNumber?: string, qrPayload?: string, balance: int}
     */
    public function show(Request $request, LoyaltyCardService $cards): JsonResponse
    {
        $user = $request->user();

        // Lazy-создание: существующие юзеры (в т.ч. приехавшие синком из админки)
        // получают карту при первом заходе на экран карты.
        if ($user->card_number === null) {
            $cards->ensureCard($user);
            $user->refresh();
        }

        if (!$user->hasActiveCard()) {
            // Карта не подтверждена 1С — номер и QR клиенту не отдаём:
            // на офлайн-кассе она всё равно не сработает (LASTOCHKI-JAMI-12013).
            return response()->json([
                'status' => 'pending',
                'balance' => (int) ($user->bonus_balance ?? 0),
            ]);
        }

        return response()->json([
            'status' => 'active',
            'cardNumber' => $user->card_number,
            'qrPayload' => $user->card_number,
            'balance' => (int) ($user->bonus_balance ?? 0),
        ]);
    }
}
