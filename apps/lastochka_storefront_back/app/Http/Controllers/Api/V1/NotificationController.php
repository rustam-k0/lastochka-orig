<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserNotificationResource;
use App\Models\UserNotification;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Notifications', weight: 15)]
final class NotificationController extends Controller
{
    /**
     * Список уведомлений
     *
     * Возвращает уведомления текущего пользователя с пагинацией, сортировка по created_at по убыванию.
     * Фильтрация по типу: order (заказы) или promo (акции); нераспознанное значение type фильтр не применяет.
     *
     * @response array{hasMore: bool, data: UserNotificationResource[], total: int}
     */
    #[QueryParameter('type', description: 'Тип уведомления (order, promo). Нераспознанное значение игнорируется', type: 'string', example: 'order')]
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество на странице, значения больше 50 усекаются до 50', type: 'int', default: 20, example: 20)]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 50);

        $query = UserNotification::query()
            ->forUser($request->user()->id)
            ->visible()
            ->orderByDesc('created_at');

        $type = NotificationType::tryFrom($request->string('type')->toString());
        if ($type) {
            $query->ofType($type);
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'hasMore' => $notifications->hasMorePages(),
            'data' => UserNotificationResource::collection($notifications),
            'total' => $notifications->total(),
        ]);
    }

    /**
     * Уведомление по announcement_id
     *
     * Возвращает первое уведомление текущего пользователя с указанным announcement_id.
     *
     * @response array{data: UserNotificationResource}
     */
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'У пользователя нет уведомления по этой акции (message = «Уведомление не найдено»)')]
    public function showByAnnouncement(Request $request, int $announcementId): JsonResponse
    {
        $notification = UserNotification::query()
            ->forUser($request->user()->id)
            ->visible()
            ->where('announcement_id', $announcementId)
            ->first();

        if (! $notification) {
            abort(404, 'Уведомление не найдено');
        }

        return response()->json([
            'data' => new UserNotificationResource($notification),
        ]);
    }

    /**
     * Детальное уведомление
     *
     * Возвращает уведомление по ID. Доступно только владельцу.
     *
     * @response array{data: UserNotificationResource}
     */
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Уведомление принадлежит другому пользователю')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Акция уведомления выключена или удалена в админке')]
    public function show(Request $request, UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $notification->isVisible()) {
            abort(404, 'Уведомление не найдено');
        }

        return response()->json([
            'data' => new UserNotificationResource($notification),
        ]);
    }

    /**
     * Пометить прочитанным
     *
     * Помечает уведомление как прочитанное. В ответе `isRead` уже равно true.
     *
     * @response array{data: UserNotificationResource}
     */
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Уведомление принадлежит другому пользователю')]
    #[ScrambleResponse(404, type: 'array{message: string}', description: 'Акция уведомления выключена или удалена в админке')]
    public function markAsRead(Request $request, UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $notification->isVisible()) {
            abort(404, 'Уведомление не найдено');
        }

        $notification->update(['is_read' => true]);

        return response()->json([
            'data' => new UserNotificationResource($notification),
        ]);
    }

    /**
     * Пометить все прочитанными
     *
     * Помечает все непрочитанные уведомления текущего пользователя как прочитанные.
     * При переданном type затрагиваются только уведомления этого типа.
     * Поле `updated` — количество изменённых записей (0, если непрочитанных не было).
     *
     * @response array{updated: int}
     */
    #[QueryParameter('type', description: 'Тип уведомления (order, promo). Нераспознанное значение игнорируется', type: 'string', example: 'order')]
    public function readAll(Request $request): JsonResponse
    {
        $query = UserNotification::query()
            ->forUser($request->user()->id)
            ->visible()
            ->unread();

        $type = NotificationType::tryFrom($request->string('type')->toString());
        if ($type) {
            $query->ofType($type);
        }

        $updated = $query->update(['is_read' => true]);

        return response()->json([
            'updated' => $updated,
        ]);
    }

    /**
     * Количество непрочитанных
     *
     * Возвращает количество непрочитанных уведомлений (для бейджа).
     * Поддерживает фильтр по типу: ?type=promo или ?type=order.
     *
     * @response array{count: int}
     */
    #[QueryParameter('type', description: 'Тип уведомления (order, promo). Нераспознанное значение игнорируется', type: 'string', example: 'promo')]
    public function unreadCount(Request $request): JsonResponse
    {
        $query = UserNotification::query()
            ->forUser($request->user()->id)
            ->visible()
            ->unread();

        $type = NotificationType::tryFrom($request->string('type')->toString());
        if ($type) {
            $query->ofType($type);
        }

        return response()->json([
            'count' => $query->count(),
        ]);
    }
}
