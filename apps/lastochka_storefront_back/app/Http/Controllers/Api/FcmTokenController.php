<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFcmToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FcmTokenController extends Controller
{

    /**
     * Сохранить или обновить FCM токен пользователя.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:255',
            'device_type' => ['nullable', 'string', Rule::in(['ios', 'android'])],
            'device_name' => 'nullable|string|max:255',
            'period' => 'nullable|integer|min:0',
            'sound' => 'nullable|string|max:50',
        ]);

        $user = Auth::user();

        $fcmToken = UserFcmToken::updateOrCreateToken(
            userId: $user->id,
            token: $validated['token'],
            deviceType: $validated['device_type'] ?? null,
            deviceName: $validated['device_name'] ?? null
        );

        // Обновляем дополнительные поля, если они переданы
        if (isset($validated['period'])) {
            $fcmToken->period = $validated['period'];
        }

        if (isset($validated['sound'])) {
            $fcmToken->sound = $validated['sound'];
        }

        $fcmToken->save();

        return response()->json([
            'success' => true,
            'message' => 'FCM токен успешно сохранен',
            'data' => [
                'id' => $fcmToken->id,
                'token' => $fcmToken->token,
                'device_type' => $fcmToken->device_type,
                'device_name' => $fcmToken->device_name,
                'created_at' => $fcmToken->created_at,
                'updated_at' => $fcmToken->updated_at,
            ],
        ]);
    }

    /**
     * Получить список FCM токенов пользователя.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $tokens = UserFcmToken::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'token' => $token->token,
                    'device_type' => $token->device_type,
                    'device_name' => $token->device_name,
                    'last_sent_at' => $token->last_sent_at,
                    'created_at' => $token->created_at,
                    'updated_at' => $token->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $tokens,
        ]);
    }

    /**
     * Удалить FCM токен.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        $deleted = UserFcmToken::where('user_id', $user->id)
            ->where('token', $validated['token'])
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'FCM токен удален',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'FCM токен не найден',
        ], 404);
    }

    /**
     * Обновить настройки уведомлений для токена.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:255',
            'period' => 'nullable|integer|min:0',
            'sound' => 'nullable|string|max:50',
        ]);

        $user = Auth::user();

        $fcmToken = UserFcmToken::where('user_id', $user->id)
            ->where('token', $validated['token'])
            ->first();

        if (!$fcmToken) {
            return response()->json([
                'success' => false,
                'message' => 'FCM токен не найден',
            ], 404);
        }

        $fcmToken->update([
            'period' => $validated['period'] ?? $fcmToken->period,
            'sound' => $validated['sound'] ?? $fcmToken->sound,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Настройки уведомлений обновлены',
            'data' => [
                'id' => $fcmToken->id,
                'token' => $fcmToken->token,
                'device_type' => $fcmToken->device_type,
                'device_name' => $fcmToken->device_name,
                'period' => $fcmToken->period,
                'sound' => $fcmToken->sound,
                'updated_at' => $fcmToken->updated_at,
            ],
        ]);
    }
}
