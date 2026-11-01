<?php

namespace App\GraphQL\Mutations\MenuItem;

use App\Models\MenuItem;

class UpdateMenuItem
{
    public function __invoke($_, array $args): MenuItem
    {
        $menuItem = MenuItem::where('name', $args['name'])->firstOrFail();

        $fillable = [];

        if (isset($args['title'])) {
            $fillable['title'] = $args['title'];
        }

        if (isset($args['is_visible'])) {
            $fillable['is_visible'] = $args['is_visible'];
        }

        if (!empty($fillable)) {
            $menuItem->update($fillable);
        }

        return $menuItem;
    }
}
