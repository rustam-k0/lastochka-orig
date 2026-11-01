<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;

final class EstimatePolicy
{
    public function view(User $user, Estimate $estimate): bool
    {
        return $estimate->user_id === $user->id;
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return $estimate->user_id === $user->id;
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $estimate->user_id === $user->id;
    }

    public function manageItem(User $user, Estimate $estimate, EstimateItem $item): bool
    {
        return $estimate->user_id === $user->id && $item->estimate_id === $estimate->id;
    }
}
