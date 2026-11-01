<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFcmTokenRequest;
use App\Http\Resources\UserFcmTokenResource;
use App\Models\UserFcmToken;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('FcmToken', weight: 16)]
final class FcmTokenController extends Controller
{
    /**
     * Получить список FCM токенов пользователя.
     *
     * Сортировка по created_at по убыванию. Поля ответа — snake_case.
     */
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $tokens = $request->user()->fcmTokens()
            ->orderBy('created_at', 'desc')
            ->get();

        return UserFcmTokenResource::collection($tokens);
    }

    /**
     * Сохранить или обновить FCM токен.
     *
     * Запись создаётся или обновляется по уникальному token; user_id перезаписывается
     * текущим пользователем. Не переданные deviceType/deviceName сохраняются как null.
     * Тело запроса — camelCase (deviceType, deviceName), snake_case принимается
     * как deprecated-алиас; поля ответа — snake_case.
     */
    #[ScrambleResponse(201, type: 'array{data: UserFcmTokenResource}', description: 'Токен создан этим запросом')]
    #[ScrambleResponse(200, type: 'array{data: UserFcmTokenResource}', description: 'Обновлена существующая запись с таким token')]
    public function store(StoreFcmTokenRequest $request): UserFcmTokenResource
    {
        $validated = $request->validated();

        $token = UserFcmToken::updateOrCreateToken(
            userId: $request->user()->id,
            token: $validated['token'],
            deviceType: $validated['deviceType'] ?? null,
            deviceName: $validated['deviceName'] ?? null,
        );

        return new UserFcmTokenResource($token);
    }

    /**
     * Удалить FCM токен.
     *
     * Значение token читается через input(), поэтому принимается и в query-строке, и в теле запроса.
     * 204 — токен пользователя удалён; 404 с пустым телом — токен не найден или принадлежит другому пользователю.
     */
    #[QueryParameter('token', description: 'FCM токен для удаления', type: 'string', required: true)]
    public function destroy(Request $request): Response
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $deleted = $request->user()
            ->fcmTokens()
            ->where('token', $request->input('token'))
            ->delete();

        if (! $deleted) {
            return response()->noContent(404);
        }

        return response()->noContent();
    }
}