<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use App\Enums\NotificationType;
use App\Models\BonusTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'phone',
        'first_name',
        'last_name',
        'patronymic',
        'email',
        'birth_date',
        'gender',
        'external_id',
        'external_source',
        'loyalty_id',
        'card_number',
        'card_created_at',
        'card_confirmed_at',
        'is_b2b',
        'bonus_balance',
        'bonus_reserved',
        'orders_enabled',
        'promo_enabled',
        'push_enabled',
        'email_enabled',
        'adult_confirmed',
        'ready_time_modal_suppressed',
    ];

    protected $hidden = [
        'remember_token',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
            'card_created_at' => 'datetime',
            'card_confirmed_at' => 'datetime',
            'is_b2b' => 'boolean',
            'bonus_balance' => 'integer',
            'bonus_reserved' => 'integer',
            'orders_enabled' => 'boolean',
            'promo_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'adult_confirmed' => 'boolean',
            'ready_time_modal_suppressed' => 'boolean',
        ];
    }

    // ==================== Relations ====================

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cart(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(UserFcmToken::class);
    }

    public function bonusTransactions(): HasMany
    {
        return $this->hasMany(BonusTransaction::class);
    }

    public function bonusHolds(): HasMany
    {
        return $this->hasMany(BonusHold::class);
    }

    public function comparisons(): HasMany
    {
        return $this->hasMany(Comparison::class);
    }

    // ==================== Loyalty ID ====================

    /**
     * Генерирует уникальный loyalty_id в формате LTY-<26-char uppercase ULID base32>.
     */
    public static function generateLoyaltyId(): string
    {
        do {
            $candidate = 'LTY-'.Str::upper(Str::ulid()->toBase32());
        } while (self::where('loyalty_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * Возвращает loyalty_id пользователя, генерируя и сохраняя если ещё не задан.
     * Идемпотентен: повторный вызов возвращает то же значение без обращения к БД.
     */
    public function ensureLoyaltyId(): string
    {
        if (!$this->loyalty_id) {
            $this->loyalty_id = self::generateLoyaltyId();
            $this->saveQuietly();
        }

        return $this->loyalty_id;
    }

    /**
     * Карта лояльности активна: номер выдан и создание подтверждено 1С
     * (заказ «Лояльность» ушёл в 1С). До подтверждения карта клиенту не показывается.
     */
    public function hasActiveCard(): bool
    {
        return $this->card_number !== null && $this->card_confirmed_at !== null;
    }

    // ==================== Accessors ====================

    public function getAvailableBonusBalanceAttribute(): int
    {
        return max(0, (int) $this->bonus_balance - (int) $this->bonus_reserved);
    }

    /**
     * Полное имя пользователя.
     */
    public function getFullNameAttribute(): ?string
    {
        $parts = array_filter([
            $this->first_name,
            $this->last_name,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    /**
     * Краткое имя для отзывов (Иван И.).
     */
    public function getShortNameAttribute(): ?string
    {
        if (! $this->first_name) {
            return null;
        }

        $name = $this->first_name;

        if ($this->last_name) {
            $name .= ' '.mb_substr($this->last_name, 0, 1).'.';
        }

        return $name;
    }

    // ==================== Notification settings ====================

    /**
     * Включён ли in-app канал для уведомлений данного типа.
     * Гейтит создание UserNotification.
     */
    public function isNotificationTypeEnabled(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::Order => (bool) ($this->orders_enabled ?? true),
            NotificationType::Promo => (bool) ($this->promo_enabled ?? true),
        };
    }

    /**
     * Можно ли слать push конкретного типа пользователю.
     * Push требует И общий тоггл push_enabled, И тип-тоггл.
     */
    public function canReceivePush(NotificationType $type): bool
    {
        if (! ($this->push_enabled ?? true)) {
            return false;
        }

        return $this->isNotificationTypeEnabled($type);
    }

    /**
     * Можно ли слать email конкретного типа пользователю.
     * Email требует И общий тоггл email_enabled, И тип-тоггл, И пригодный адрес.
     *
     * В отличие от push, канал opt-in: неизвестное значение читаем как «нельзя».
     */
    public function canReceiveEmail(NotificationType $type): bool
    {
        if (! ($this->email_enabled ?? false)) {
            return false;
        }

        if (! $this->hasDeliverableEmail()) {
            return false;
        }

        return $this->isNotificationTypeEnabled($type);
    }

    /**
     * Адрес пригоден для отправки: заполнен и проходит валидацию формата.
     */
    public function hasDeliverableEmail(): bool
    {
        return $this->email !== null
            && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
