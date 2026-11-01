<?php

namespace App\GraphQL\Mutations\MenuItem;

use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

class DeleteMenuFolder
{
    public function __invoke($_, array $args): bool
    {
        $folder = MenuItem::where('name', $args['name'])->first();

        if (!$folder || !$folder->is_folder) {
            throw new AuthorizationException('Папка с таким названием не найдена.');
        }

        DB::transaction(function () use ($folder) {
            foreach ($folder->children as $child) {
                $child->update([
                    'parent_id' => null,
                    'position' => MenuItem::nextPosition(null),
                ]);
            }

            $folder->delete();
        });

        return true;
    }
}
