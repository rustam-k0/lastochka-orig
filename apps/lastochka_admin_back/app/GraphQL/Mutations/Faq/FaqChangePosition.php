<?php

namespace App\GraphQL\Mutations\Faq;

use App\Models\Faq;
use Illuminate\Support\Facades\DB;

final class FaqChangePosition
{
    /**
     * @param null $_
     * @param array{items: array<int, array{id: int, position: int}>} $args
     * @return array{code: int, message: string}
     */
    public function __invoke($_, array $args): array
    {
        DB::transaction(function () use ($args): void {
            foreach ($args['items'] as $item) {
                $faq = Faq::find($item['id']);

                if ($faq === null) {
                    // Defensive: запись могла исчезнуть между валидатором и резолвером.
                    continue;
                }

                if ($faq->position !== (int) $item['position']) {
                    $faq->update(['position' => (int) $item['position']]);
                }
            }
        });

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
