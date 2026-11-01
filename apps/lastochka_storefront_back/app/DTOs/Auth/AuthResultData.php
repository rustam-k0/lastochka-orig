<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\User;

final readonly class AuthResultData
{
    public function __construct(
        public string $token,
        public User $user,
    ) {}
}
