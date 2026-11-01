<?php

namespace App\GraphQL\Mutations\MenuItem;

use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class ReorderMenuItems
{
    public function __invoke($_, array $args): array
    {
        DB::transaction(function () use ($args) {
            foreach ($args['items'] as $item) {
                MenuItem::where('name', $item['name'])
                    ->update(['position' => $item['position']]);
            }
        });

        return MenuItem::orderBy('position')->get()->all();
    }
}
