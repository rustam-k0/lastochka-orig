<?php

namespace App\GraphQL\Mutations\Printer;

use App\Models\Printer;

final class PrinterUpdate
{
    public function __invoke($_, array $args)
    {
        $printer = Printer::findOrFail($args['id']);

        $printer->update($args);

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
