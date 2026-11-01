<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comparison;
use App\Models\User;

final class ComparisonPolicy
{
    public function view(User $user, Comparison $comparison): bool
    {
        return $comparison->user_id === $user->id;
    }

    public function delete(User $user, Comparison $comparison): bool
    {
        return $comparison->user_id === $user->id;
    }
}
