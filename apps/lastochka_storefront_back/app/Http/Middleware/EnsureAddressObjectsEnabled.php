<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\ModuleConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EnsureAddressObjectsEnabled
{
    public function __construct(private readonly ModuleConfigService $moduleConfig) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->moduleConfig->isAddressObjectsEnabled()) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
