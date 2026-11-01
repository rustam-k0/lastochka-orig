<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StorefrontSetting extends Model
{
    protected $table = 'storefront_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    /**
     * Вернуть значение настройки с приведением к типу.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        /** @var self|null $row */
        $row = static::query()->where('key', $key)->first();

        if ($row === null) {
            return $default;
        }

        $raw = $row->value;

        if ($raw === null) {
            return $default;
        }

        return match ($row->type) {
            'json' => json_decode($raw, true),
            'int' => (int) $raw,
            'boolean' => (bool) $raw,
            default => $raw,
        };
    }
}
