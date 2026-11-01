<?php

namespace App\GraphQL\Mutations\Printer;

use App\Models\Printer;

final class PrinterCreate
{
    public function __invoke($_, array $args)
    {
        Printer::create($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
