<?php

namespace App\GraphQL\Mutations\MenuItem;

use App\Models\MenuItem;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class AssignMenuItemToFolder
{
    public function __invoke($_, array $args): MenuItem
    {
        $item = MenuItem::where('name', $args['name'])->first();

        if (!$item) {
            throw new AuthorizationException('Пункт меню с таким названием не найден.');
        }

        if ($item->is_folder) {
            throw new AuthorizationException('Папку нельзя вложить в другую папку.');
        }

        $parentName = $args['parentName'] ?? null;

        if ($parentName === null) {
            $item->update([
                'parent_id' => null,
                'position' => MenuItem::nextPosition(null),
            ]);

            return $item;
        }

        $parent = MenuItem::where('name', $parentName)->first();

        if (!$parent || !$parent->is_folder) {
            throw new AuthorizationException('Папка с таким названием не найдена.');
        }

        $item->update([
            'parent_id' => $parent->id,
            'position' => MenuItem::nextPosition($parent->id),
        ]);

        return $item;
    }
}
