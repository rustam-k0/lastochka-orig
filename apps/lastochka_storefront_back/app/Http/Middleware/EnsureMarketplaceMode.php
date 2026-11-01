<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\ModuleConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMarketplaceMode
{
    public function __construct(private readonly ModuleConfigService $moduleConfigService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->moduleConfigService->isMarketplaceMode()) {
            abort(404);
        }

        return $next($request);
    }
}
