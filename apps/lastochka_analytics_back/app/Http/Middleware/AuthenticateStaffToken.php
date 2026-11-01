<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Docker\StaffTokenResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Авторизация API аналитики по Sanctum-токену сотрудника из docker-БД.
 * Контекст кладётся в атрибут запроса 'staff' (StaffContext).
 */
final class AuthenticateStaffToken
{
    public function __construct(private readonly StaffTokenResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = $this->resolver->resolve($request->bearerToken());

        if ($staff === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $request->attributes->set('staff', $staff);

        return $next($request);
    }
}
