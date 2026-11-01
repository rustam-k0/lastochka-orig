<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserNotification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'short_description',
        'body',
        'image_url',
        'promo_code',
        'deep_link',
        'announcement_id',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'is_read' => 'boolean',
        ];
    }

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== Scopes ====================

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, NotificationType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Уведомления, которые пользователь должен видеть.
     *
     * Промо-уведомление — снимок акции, сделанный в момент рассылки, поэтому само по себе
     * оно живёт вечно. Скрываем его, когда акцию в админке выключили или удалили: галка
     * «Активно» должна работать и для уже разосланных карточек, иначе пользователь открывает
     * акцию, которой больше нет. Скрытие обратимо — вернули галку, вернулась и карточка.
     *
     * Уведомления без announcement_id (заказы, рассылки до появления привязки) и уведомления,
     * чья акция ещё не доехала по шине, остаются видимыми: не прячем то, о чём не знаем.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $q) {
            $q->whereNotNull('announcement_id')
                ->whereExists(function ($sub) {
                    // Сравнение без CAST намеренно: announcement_id — bigint, external_id — varchar,
                    // и MySQL здесь приводит строку к числу. CAST(... AS CHAR) выглядел бы чище,
                    // но на MySQL 8 даёт collation соединения (utf8mb4_0900_ai_ci) против
                    // utf8mb4_unicode_ci колонки и роняет запрос с ошибкой 1267.
                    $sub->selectRaw('1')
                        ->from('announcements')
                        ->whereColumn('announcements.external_id', 'user_notifications.announcement_id')
                        ->where(function ($state) {
                            $state->where('announcements.is_active', false)
                                ->orWhereNotNull('announcements.deleted_at');
                        });
                });
        });
    }

    // ==================== Helpers ====================

    /**
     * Видно ли уведомление пользователю (акция не выключена и не удалена).
     */
    public function isVisible(): bool
    {
        return self::query()->visible()->whereKey($this->getKey())->exists();
    }
}
