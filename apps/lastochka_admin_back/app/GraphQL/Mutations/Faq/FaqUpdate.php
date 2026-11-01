<?php

namespace App\GraphQL\Mutations\Faq;

use App\Models\Faq;

final class FaqUpdate
{
    public function __invoke($_, array $args)
    {
        $faq = Faq::findOrFail($args['id']);

        $faq->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
