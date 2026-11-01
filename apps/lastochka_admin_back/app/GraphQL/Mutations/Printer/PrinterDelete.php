<?php

namespace App\GraphQL\Mutations\Printer;

use App\Models\Printer;

final class PrinterDelete
{
    public function __invoke($_, array $args)
    {
        $printer = Printer::findOrFail($args['id']);

        $printer->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
