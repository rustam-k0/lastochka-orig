<?php

declare(strict_types=1);

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\RabbitMQ\Debug\RabbitMessageLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RabbitDebugController extends Controller
{
    /**
     * GET /api/debug/rabbit/messages
     * Список сообщений с фильтрами.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RabbitMessageLog::query()->orderByDesc('created_at');

        // Фильтр по направлению
        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Фильтр по routing_key (поддержка wildcard *)
        if ($request->filled('routing_key')) {
            $routingKey = $request->input('routing_key');
            if (str_contains($routingKey, '*')) {
                $pattern = str_replace('*', '%', $routingKey);
                $query->where('routing_key', 'like', $pattern);
            } else {
                $query->where('routing_key', $routingKey);
            }
        }

        // Фильтр по entity_type
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        // Фильтр по дате
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('date_from')));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('date_to')));
        }

        $perPage = min((int) $request->input('per_page', 50), 100);

        $messages = $query->paginate($perPage);

        return response()->json([
            'data' => $messages->map(fn (RabbitMessageLog $log) => [
                'id' => $log->id,
                'direction' => $log->direction,
                'routing_key' => $log->routing_key,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'external_id' => $log->external_id,
                'status' => $log->status,
                'processing_time_ms' => $log->processing_time_ms,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * GET /api/debug/rabbit/messages/{id}
     * Детали сообщения.
     */
    public function show(int $id): JsonResponse
    {
        $log = RabbitMessageLog::findOrFail($id);

        return response()->json([
            'id' => $log->id,
            'direction' => $log->direction,
            'routing_key' => $log->routing_key,
            'queue_name' => $log->queue_name,
            'payload' => $log->payload,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'external_id' => $log->external_id,
            'status' => $log->status,
            'error_message' => $log->error_message,
            'error_trace' => $log->error_trace,
            'db_snapshot' => $log->db_snapshot,
            'processing_time_ms' => $log->processing_time_ms,
            'created_at' => $log->created_at->toIso8601String(),
        ]);
    }

    /**
     * GET /api/debug/rabbit/stats
     * Статистика за 24 часа.
     */
    public function stats(): JsonResponse
    {
        $since = Carbon::now()->subDay();

        $inboundTotal = RabbitMessageLog::where('direction', 'inbound')
            ->where('created_at', '>=', $since)
            ->count();

        $inboundProcessed = RabbitMessageLog::where('direction', 'inbound')
            ->where('status', 'processed')
            ->where('created_at', '>=', $since)
            ->count();

        $inboundFailed = RabbitMessageLog::where('direction', 'inbound')
            ->where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();

        $outboundTotal = RabbitMessageLog::where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->count();

        $outboundProcessed = RabbitMessageLog::where('direction', 'outbound')
            ->where('status', 'processed')
            ->where('created_at', '>=', $since)
            ->count();

        $outboundFailed = RabbitMessageLog::where('direction', 'outbound')
            ->where('status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();

        $byRoutingKey = RabbitMessageLog::where('created_at', '>=', $since)
            ->selectRaw('routing_key, count(*) as count')
            ->groupBy('routing_key')
            ->orderByDesc('count')
            ->limit(20)
            ->pluck('count', 'routing_key')
            ->toArray();

        $avgProcessingTime = RabbitMessageLog::where('created_at', '>=', $since)
            ->whereNotNull('processing_time_ms')
            ->avg('processing_time_ms');

        return response()->json([
            'period' => '24h',
            'inbound' => [
                'total' => $inboundTotal,
                'processed' => $inboundProcessed,
                'failed' => $inboundFailed,
            ],
            'outbound' => [
                'total' => $outboundTotal,
                'processed' => $outboundProcessed,
                'failed' => $outboundFailed,
            ],
            'by_routing_key' => $byRoutingKey,
            'avg_processing_time_ms' => $avgProcessingTime !== null ? (int) round((float) $avgProcessingTime) : null,
        ]);
    }

    /**
     * DELETE /api/debug/rabbit/messages
     * Очистка логов.
     */
    public function destroy(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', config('rabbit-debug.retention_days', 7));

        $before = Carbon::now()->subDays($days);

        $deleted = RabbitMessageLog::where('created_at', '<', $before)->delete();

        return response()->json([
            'deleted' => $deleted,
            'message' => "Deleted {$deleted} log entries older than {$days} days.",
        ]);
    }

    /**
     * GET /api/debug/rabbit/routing-keys
     * Список уникальных routing keys для автокомплита.
     */
    public function routingKeys(): JsonResponse
    {
        $keys = RabbitMessageLog::selectRaw('DISTINCT routing_key')
            ->orderBy('routing_key')
            ->pluck('routing_key')
            ->toArray();

        return response()->json(['data' => $keys]);
    }
}
