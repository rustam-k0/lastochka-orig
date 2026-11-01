<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Http\Request;

/**
 * Политика показа 18+ товаров (products.adult_only).
 *
 * Авторизованный пользователь: только настройка профиля adult_confirmed,
 * query-параметр игнорируется. Гость: query-параметр showAdult (по
 * умолчанию 18+ товары скрыты).
 *
 * Роуты, где авторизация опциональна, должны иметь middleware OptionalAuth —
 * иначе даже с токеном пользователь тут не резолвится и считается гостём.
 */
final class AdultContentPolicy
{
    public function showAdult(Request $request): bool
    {
        $user = $request->user() ?? $request->user('sanctum');

        if ($user !== null) {
            return (bool) $user->adult_confirmed;
        }

        return $request->boolean('showAdult');
    }
}
