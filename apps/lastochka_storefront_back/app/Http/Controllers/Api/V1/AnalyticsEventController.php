<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalyticsEventsRequest;
use App\Services\Analytics\AnalyticsIngestService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Analytics', weight: 30)]
final class AnalyticsEventController extends Controller
{
    public function __construct(private readonly AnalyticsIngestService $ingest)
    {
    }

    /**
     * Принять батч поведенческих событий приложения.
     *
     * Работает анонимно и с Bearer-токеном. Невалидное событие не валит
     * батч: ответ 202 содержит счётчик принятых и список отклонённых
     * с причинами. Storefront события не хранит — они уходят в шину
     * и складываются сервисом аналитики.
     */
    public function store(StoreAnalyticsEventsRequest $request): JsonResponse
    {
        $result = $this->ingest->ingest(
            events: $request->validated('events'),
            user: $request->user(),
            storeCode: $request->header('X-Store-Code') ?: null,
        );

        return response()->json($result, 202);
    }
}
