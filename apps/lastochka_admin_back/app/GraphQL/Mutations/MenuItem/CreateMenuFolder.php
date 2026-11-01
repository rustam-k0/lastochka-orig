<?php

namespace App\GraphQL\Mutations\MenuItem;

use App\Models\MenuItem;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class CreateMenuFolder
{
    /**
     * menu_items.name — string(50). Урезаем базовый slug до этой длины,
     * оставляя запас под суффикс коллизии "-2", "-3", ... (см. цикл ниже),
     * иначе итоговое имя с суффиксом само может превысить лимит колонки.
     */
    private const SLUG_BASE_MAX_LENGTH = 45;

    public function __invoke($_, array $args): MenuItem
    {
        if (blank($args['title'])) {
            throw new AuthorizationException('Название папки не может быть пустым.');
        }

        // Str::slug() отдаёт чистый ASCII, поэтому байтовый substr() здесь
        // безопасен и не режет многобайтовые символы (в отличие от
        // произвольного пользовательского UTF-8 текста).
        $baseName = substr(Str::slug($args['title']), 0, self::SLUG_BASE_MAX_LENGTH);
        $name = $baseName;
        $suffix = 2;

        while (MenuItem::where('name', $name)->exists()) {
            $name = $baseName . '-' . $suffix;
            $suffix++;
        }

        $icon = $args['icon'] ?? null;
        $icon = blank($icon) ? 'folder-line' : $icon;

        return MenuItem::create([
            'name' => $name,
            'title' => $args['title'],
            'icon' => $icon,
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
            'parent_id' => null,
        ]);
    }
}
