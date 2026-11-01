<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\MerchantProductGroup;

use App\Models\MerchantProductGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ChangeMerchantProductGroupPosition
{
    /**
     * @param  null  $_
     * @param  array{items: array<int, array{id: int, sort_order: int}>}  $args
     * @return array{code: int, message: string}
     *
     * @throws AuthorizationException
     */
    public function __invoke($_, array $args): array
    {
        $this->assertOwnership($args['items']);

        DB::transaction(function () use ($args): void {
            foreach ($args['items'] as $item) {
                $group = MerchantProductGroup::find($item['id']);

                if ($group === null) {
                    // Defensive: row could be soft-deleted between validator and resolver.
                    continue;
                }

                $group->update(['sort_order' => $item['sort_order']]);
            }
        });

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }

    /**
     * @param  array<int, array{id: int, sort_order: int}>  $items
     *
     * @throws AuthorizationException
     */
    private function assertOwnership(array $items): void
    {
        $user = auth()->user();

        if ($user === null || $user->isGlobalScope()) {
            return;
        }

        $userShopIds = $user->shops()->pluck('shop_id')->toArray();

        $itemShopIds = MerchantProductGroup::query()
            ->whereIn('id', array_column($items, 'id'))
            ->pluck('shop_id')
            ->unique()
            ->toArray();

        $foreignShopIds = array_diff($itemShopIds, $userShopIds);

        if ($foreignShopIds !== []) {
            throw new AuthorizationException(
                'Forbidden: cannot reorder product groups from other shops'
            );
        }
    }
}
