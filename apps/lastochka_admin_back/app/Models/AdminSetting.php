<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSetting extends Model
{
    protected $table = 'admin_settings';

    protected $fillable = ['key', 'value', 'type'];

    public static function getValue(string $key): ?string
    {
        return static::where('key', $key)->value('value');
    }

    public static function setValue(string $key, ?string $value, string $type = 'string'): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }
}
