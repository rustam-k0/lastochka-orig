<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $table = 'menu_items';

    protected $fillable = [
        'name',
        'title',
        'icon',
        'position',
        'is_visible',
        'accesses',
        'is_folder',
        'parent_id',
    ];

    protected $casts = [
        'accesses' => 'array',
        'is_visible' => 'boolean',
        'position' => 'integer',
        'is_folder' => 'boolean',
    ];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Следующая свободная позиция в рамках группы (корневой уровень при
     * $parentId=null, либо дети конкретной папки).
     */
    public static function nextPosition(?int $parentId): int
    {
        $max = static::where('parent_id', $parentId)->max('position');
        return (int) ($max ?? -1) + 1;
    }
}
