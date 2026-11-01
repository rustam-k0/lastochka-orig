<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Админ из AdminSeeder. Первый пользователь БД не обязан быть админом
     * (повторный сид, пользователи без ролей), а гарды onlyAdmin требуют роль admin.
     */
    protected function adminUser(): User
    {
        return User::whereHas('roles', fn ($query) => $query->where('name', Role::ADMIN))
            ->orderBy('id')
            ->firstOrFail();
    }
}
