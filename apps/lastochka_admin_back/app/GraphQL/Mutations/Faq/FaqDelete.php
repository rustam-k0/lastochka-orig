<?php

namespace App\GraphQL\Mutations\Faq;

use App\Models\Faq;

final class FaqDelete
{
    public function __invoke($_, array $args)
    {
        $faq = Faq::findOrFail($args['id']);

        $faq->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
