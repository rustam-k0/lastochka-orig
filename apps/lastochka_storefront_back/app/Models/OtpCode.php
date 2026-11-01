<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class OtpCode extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'phone',
        'code',
        'attempts',
        'expires_at',
        'verified_at',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ==================== Scopes ====================

    /**
     * Активные (не истёкшие и не верифицированные) OTP.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('expires_at', '>', now())
            ->whereNull('verified_at');
    }

    /**
     * Фильтр по телефону.
     */
    public function scopeForPhone($query, string $phone)
    {
        return $query->where('phone', $phone);
    }

    // ==================== Methods ====================

    /**
     * Проверить, истёк ли OTP.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Проверить, превышено ли количество попыток.
     */
    public function hasExceededAttempts(int $maxAttempts = 3): bool
    {
        return $this->attempts >= $maxAttempts;
    }

    /**
     * Увеличить счётчик попыток.
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Пометить как верифицированный.
     */
    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }
}
