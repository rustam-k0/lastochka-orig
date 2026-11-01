<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Address extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'country',
        'city',
        'street',
        'house_number',
        'entrance',
        'floor',
        'apartment',
        'intercom',
        'comment',
        'latitude',
        'longitude',
        'full_address',
        'is_active',
        'is_object',
        'object_data',
        'additional',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_object' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'object_data' => \App\Casts\ObjectAddressDataCast::class,
            'additional' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AddressPhoto::class)->orderBy('sort_order');
    }

    // ==================== Scopes ====================

    /**
     * Адреса конкретного пользователя.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Активный адрес.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ==================== Methods ====================

    /**
     * Сформировать полный адрес из полей.
     */
    public function buildFullAddress(): string
    {
        $parts = [
            $this->country,
            $this->city,
            $this->street,
            "д. {$this->house_number}",
        ];

        if ($this->apartment) {
            $parts[] = "кв. {$this->apartment}";
        }

        return implode(', ', $parts);
    }
}
