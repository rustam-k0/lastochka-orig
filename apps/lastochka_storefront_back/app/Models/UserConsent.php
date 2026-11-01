<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentContext;
use App\Enums\ConsentDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only лог согласий пользователя с документами (MENDEV-7).
 * Записи не редактируются и не удаляются — каждая фиксация даёт новую строку.
 */
final class UserConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'document_type',
        'context',
        'accepted',
        'ip',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => ConsentDocument::class,
            'context' => ConsentContext::class,
            'accepted' => 'boolean',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::creating(function (UserConsent $consent) {
            $consent->created_at ??= $consent->freshTimestamp();
        });
    }
}
