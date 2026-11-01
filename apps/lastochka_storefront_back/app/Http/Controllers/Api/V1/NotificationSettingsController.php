<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Http\Resources\Api\V1\NotificationSettingsResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Notifications', weight: 15)]
final class NotificationSettingsController extends Controller
{
    /**
     * Получить настройки уведомлений
     *
     * Возвращает четыре пользовательских флага: уведомления о заказах,
     * уведомления об акциях, общий push-канал и email-канал.
     *
     * @response array{data: NotificationSettingsResource}
     */
    public function show(Request $request): NotificationSettingsResource
    {
        return new NotificationSettingsResource($request->user());
    }

    /**
     * Обновить настройки уведомлений
     *
     * Принимает любые из ordersEnabled / promoEnabled / pushEnabled / emailEnabled.
     * Поля, отсутствующие в запросе, не изменяются; пустое тело оставляет настройки без изменений.
     * emailEnabled можно включить без заполненного email — письма начнут уходить,
     * когда пользователь укажет адрес в профиле.
     *
     * @response array{data: NotificationSettingsResource}
     */
    public function update(UpdateNotificationSettingsRequest $request): NotificationSettingsResource
    {
        $user = $request->user();

        $payload = $request->settings();
        if ($payload !== []) {
            $user->forceFill($payload)->save();
        }

        return new NotificationSettingsResource($user->refresh());
    }
}
