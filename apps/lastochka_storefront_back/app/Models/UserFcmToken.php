<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

final class UserFcmToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'device_type',
        'device_name',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * Связь с пользователем.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить активные токены для конкретного пользователя.
     *
     * @return Collection<int, string>
     */
    public static function getActiveTokensForUser(int $userId): Collection
    {
        return static::where('user_id', $userId)
            ->whereNotNull('token')
            ->pluck('token');
    }

    /**
     * Получить все активные токены в системе.
     *
     * @return Collection<int, string>
     */
    public static function getAllActiveTokens(): Collection
    {
        return static::whereNotNull('token')
            ->whereNotNull('user_id')
            ->pluck('token');
    }

    /**
     * Получить токены для активных пользователей (заходивших за последние 30 дней).
     *
     * @return Collection<int, string>
     */
    public static function getActiveUserTokens(): Collection
    {
        return static::whereHas('user', function ($query) {
            $query->where('last_login_at', '>=', now()->subDays(30));
        })
        ->whereNotNull('token')
        ->pluck('token');
    }

    /**
     * Создать или обновить токен для пользователя.
     */
    public static function updateOrCreateToken(
        int $userId,
        string $token,
        ?string $deviceType = null,
        ?string $deviceName = null,
    ): self {
        return static::updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $userId,
                'device_type' => $deviceType,
                'device_name' => $deviceName,
            ]
        );
    }

    /**
     * Отметить токен как использованный для отправки.
     */
    public function markAsSent(): void
    {
        $this->update(['last_sent_at' => now()]);
    }

    /**
     * Удалить устаревшие или недействительные токены.
     */
    public static function removeInvalidTokens(array $invalidTokens): int
    {
        if (empty($invalidTokens)) {
            return 0;
        }

        return static::whereIn('token', $invalidTokens)->delete();
    }
}
