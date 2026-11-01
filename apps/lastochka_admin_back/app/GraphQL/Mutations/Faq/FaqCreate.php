<?php

namespace App\GraphQL\Mutations\Faq;

use App\Models\Faq;

final class FaqCreate
{
    public function __invoke($_, array $args)
    {
        Faq::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
