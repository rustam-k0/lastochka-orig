<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SendOtpAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Enums\ConsentContext;
use App\Enums\ConsentDocument;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePhoneConfirmRequest;
use App\Http\Requests\Api\V1\Auth\ChangePhoneRequest;
use App\Http\Requests\Api\V1\Auth\PhoneConfirmRequest;
use App\Http\Requests\Api\V1\Auth\PhoneLoginRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\OtpService;
use App\Services\Consent\ConsentService;
use App\Support\ClientIp;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('Auth', weight: 0)]
final class AuthController extends Controller
{
    /**
     * Запрос OTP кода
     *
     * Отправляет одноразовый код подтверждения на указанный номер телефона.
     * Код действует 5 минут (`expiresIn` = 300). Длина для `method=sms` задаётся
     * настройкой стенда (4–6 цифр, по умолчанию 6), для `method=call` всегда 4 цифры;
     * фактическая длина отдаётся в `codeLength`, по ней клиент рисует ячейки ввода.
     * Поле `code` присутствует в ответе только когда драйвер отправки равен `otp`
     * (режим без реального SMS/звонка).
     *
     * @response array{message: string, expiresIn: int, method: string, codeLength: int, code?: string}
     */
    #[ScrambleResponse(429, type: 'array{message: string}', description: 'Превышен лимит OTP: 5 запросов на номер за 10 минут (message = «Превышен лимит запросов OTP. Попробуйте позже.»)')]
    public function phoneLogin(
        PhoneLoginRequest $request,
        SendOtpAction $action,
        OtpService $otpService,
    ): JsonResponse {
        $phone = $request->normalizedPhone();
        $method = $request->validated('method', 'sms');
        $otp = $action->execute($phone, $method);

        $response = [
            'message' => 'OTP sent',
            'expiresIn' => $otpService->getExpiresInSeconds(),
            'method' => $method,
            // Длина выданного кода, а не настройки: фиксированный код тестового
            // номера (AUTH_TEST_PHONES) может быть другой длины, а клиент рисует
            // ячейки ввода ровно по этому полю.
            'codeLength' => mb_strlen($otp->plain_code),
        ];

        // В OTP-режиме возвращаем код в ответе (режим без реального SMS/звонка)
        $smsConfig = \App\Services\IntegrationConfigService::get($method === 'call' ? 'call' : 'sms');
        $smsDriver = $smsConfig?->driver ?? config("services.sms_service.driver");
        if ($smsDriver === 'otp') {
            $response['code'] = $otp->plain_code;
        }

        return response()->json($response);
    }

    /**
     * Подтверждение OTP кода
     *
     * Проверяет введённый код и при успехе возвращает токен авторизации.
     * Если пользователь новый — создаётся автоматически вместе с картой лояльности;
     * ранее удалённый (soft delete) — восстанавливается.
     * Объект `user` отдаётся без обёртки `data`.
     *
     * @response array{token: string, user: UserResource}
     */
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Код отклонён (errors.code), три варианта message: «Код подтверждения устарел или уже использован. Запросите новый код.» / «Неверный код подтверждения.» / «Превышено число попыток ввода кода. Запросите новый код.»; либо ошибка валидации phone/code')]
    public function phoneConfirm(
        PhoneConfirmRequest $request,
        VerifyOtpAction $action,
    ): JsonResponse {
        $result = $action->execute(
            $request->normalizedPhone(),
            $request->validated('code'),
            $request->has('adultConfirmed') ? $request->boolean('adultConfirmed') : null,
        );

        // MENDEV-7: авторизация = согласие с политикой, перс. данными и офертой
        $consents = app(ConsentService::class);
        $ip = ClientIp::fromRequest($request);
        $consents->record(
            $result->user,
            [ConsentDocument::PrivacyPolicy, ConsentDocument::PersonalData, ConsentDocument::PublicOffer],
            ConsentContext::Auth,
            ip: $ip,
        );
        if ($request->has('adultConfirmed')) {
            $consents->record(
                $result->user,
                [ConsentDocument::Adult],
                ConsentContext::Auth,
                accepted: $request->boolean('adultConfirmed'),
                ip: $ip,
            );
        }

        return response()->json([
            'token' => $result->token,
            'user' => new UserResource($result->user),
        ]);
    }

    /**
     * Согласие 18+
     *
     * Фиксирует в профиле подтверждение совершеннолетия (confirmed=true —
     * 18+ товары показываются) либо скрывает 18+ контент (confirmed=false).
     * Для гостей аналогичную роль играет query-параметр showAdult в
     * каталожных ручках.
     *
     * @response array{data: UserResource}
     */
    public function confirmAdult(Request $request): JsonResponse
    {
        $request->validate([
            'confirmed' => ['required', 'boolean'],
        ], [
            'confirmed.required' => 'Укажите значение подтверждения',
            'confirmed.boolean' => 'Значение должно быть булевым',
        ]);

        $user = $request->user();
        $user->update(['adult_confirmed' => $request->boolean('confirmed')]);

        // MENDEV-7: фиксируем выбор (включая отказ) в логе согласий
        app(ConsentService::class)->record(
            $user,
            [ConsentDocument::Adult],
            ConsentContext::AdultConfirm,
            accepted: $request->boolean('confirmed'),
            ip: ClientIp::fromRequest($request),
        );

        return response()->json([
            'data' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Получение профиля
     *
     * Возвращает данные текущего авторизованного пользователя.
     */
    public function getProfile(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Обновление профиля
     *
     * Обновляет данные текущего пользователя. Все поля опциональные.
     */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $fieldMap = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'patronymic' => 'patronymic',
            'email' => 'email',
            'birthDate' => 'birth_date',
            'gender' => 'gender',
            'readyTimeModalSuppressed' => 'ready_time_modal_suppressed',
        ];

        $data = [];
        foreach ($fieldMap as $requestKey => $modelKey) {
            if ($request->has($requestKey)) {
                $data[$modelKey] = $request->validated($requestKey);
            }
        }

        $user->update($data);

        return new UserResource($user->fresh());
    }

    /**
     * Удаление аккаунта
     *
     * Удаляет аккаунт текущего пользователя (soft delete).
     * Запрещено при наличии активных заказов (pending, assembling, assembled, inDelivery).
     * Отзывает все токены, удаляет FCM-токены.
     * При успехе — 204 с пустым телом.
     */
    #[ScrambleResponse(409, type: 'array{message: string}', description: 'У пользователя есть активные заказы (message = «Невозможно удалить аккаунт при наличии активных заказов.»)')]
    public function deleteAccount(Request $request): Response|JsonResponse
    {
        $user = $request->user();

        $hasActiveOrders = $user->orders()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Delivered])
            ->exists();

        if ($hasActiveOrders) {
            return response()->json([
                'message' => 'Невозможно удалить аккаунт при наличии активных заказов.',
            ], 409);
        }

        $user->tokens()->delete();
        $user->fcmTokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    /**
     * Смена номера — запрос OTP
     *
     * Отправляет OTP код на новый номер телефона (поле запроса — `newPhone`).
     * Новый номер не должен быть занят другим пользователем.
     * Формат ответа совпадает с `phone-login`, включая `message` = «OTP sent».
     *
     * @response array{message: string, expiresIn: int, method: string, codeLength: int, code?: string}
     */
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Неверный формат номера или номер занят другим пользователем (errors.newPhone = «Этот номер телефона уже используется»)')]
    #[ScrambleResponse(429, type: 'array{message: string}', description: 'Превышен лимит OTP по новому номеру: 5 запросов за 10 минут')]
    public function changePhone(
        ChangePhoneRequest $request,
        SendOtpAction $action,
        OtpService $otpService,
    ): JsonResponse {
        $newPhone = $request->normalizedPhone();
        $method = $request->validated('method', 'sms');
        $otp = $action->execute($newPhone, $method);

        $response = [
            'message' => 'OTP sent',
            'expiresIn' => $otpService->getExpiresInSeconds(),
            'method' => $method,
            // Длина выданного кода, а не настройки: фиксированный код тестового
            // номера (AUTH_TEST_PHONES) может быть другой длины, а клиент рисует
            // ячейки ввода ровно по этому полю.
            'codeLength' => mb_strlen($otp->plain_code),
        ];

        $smsConfig = \App\Services\IntegrationConfigService::get($method === 'call' ? 'call' : 'sms');
        $smsDriver = $smsConfig?->driver ?? config("services.sms_service.driver");
        if ($smsDriver === 'otp') {
            $response['code'] = $otp->plain_code;
        }

        return response()->json($response);
    }

    /**
     * Смена номера — подтверждение OTP
     *
     * Проверяет OTP код, отправленный на `newPhone`, и обновляет номер телефона пользователя.
     * Токены не отзываются. Объект `user` отдаётся без обёртки `data`.
     *
     * @response array{user: UserResource}
     */
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Код отклонён (errors.code), три варианта message: «Код подтверждения устарел или уже использован. Запросите новый код.» / «Неверный код подтверждения.» / «Превышено число попыток ввода кода. Запросите новый код.»; либо ошибка валидации newPhone/code')]
    public function changePhoneConfirm(
        ChangePhoneConfirmRequest $request,
        OtpService $otpService,
    ): JsonResponse {
        $newPhone = $request->normalizedPhone();

        $otp = $otpService->verify($newPhone, $request->validated('code'));
        $otpService->consume($otp);

        $user = $request->user();
        $user->update(['phone' => $newPhone]);

        return response()->json([
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Выход из системы
     *
     * Отзывает текущий токен авторизации. Остальные токены пользователя продолжают действовать.
     * Ответ — 204 с пустым телом.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
