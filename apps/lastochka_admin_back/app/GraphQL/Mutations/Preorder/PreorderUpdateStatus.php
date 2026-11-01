<?php

namespace App\GraphQL\Mutations\Preorder;

use App\Models\Preorder;

final class PreorderUpdateStatus
{
    public function __invoke($_, array $args)
    {
        $preorder = Preorder::findOrFail($args['id']);

        $allowedStatuses = ['new', 'completed', 'cancelled'];
        if (! in_array($args['status'], $allowedStatuses, true)) {
            return ['code' => 422, 'message' => 'Недопустимый статус'];
        }

        $preorder->update(['status' => $args['status']]);

        return ['code' => 200, 'message' => 'OK'];
    }
}
