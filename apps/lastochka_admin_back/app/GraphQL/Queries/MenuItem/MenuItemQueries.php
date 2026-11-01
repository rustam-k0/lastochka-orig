<?php

namespace App\GraphQL\Queries\MenuItem;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Collection;

class MenuItemQueries
{
    public function all($_, array $args): Collection
    {
        return MenuItem::orderBy('position')->get();
    }
}
