<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ExchangeLog;
use App\Services\ExchangeDiagnosticsPublisher;
use App\Services\Exchange1C\OrderStatusSyncService;

/**
 * Middleware для обработки файлов заказов от 1С
 *
 * Перехватывает запросы type=sale, mode=import с файлами orders
 * и принудительно обрабатывает их
 */
class ProcessOrdersAfterUnzip
{
    public function handle(Request $request, Closure $next)
    {
        $type = $request->get('type');
        $mode = $request->get('mode');
        $filename = $request->get('filename', '');

        // Логируем все запросы к 1С эндпоинту
        Log::channel('1c-exchange')->info('1C Request', [
            'type' => $type,
            'mode' => $mode,
            'filename' => $filename,
            'url' => $request->fullUrl(),
        ]);

        $response = $next($request);

        // Обрабатываем orders файлы в режиме sale
        if ($mode === 'import' &&
            $type === 'sale' &&
            $this->isOrdersFile($filename)) {

            $responseContent = $response->getContent();

            Log::channel('1c-exchange')->info('Processing orders file from 1C', [
                'filename' => $filename,
                'response_before' => $responseContent,
            ]);

            // Если ответ progress или success - пробуем обработать файл
            if (str_contains($responseContent, 'progress') || str_contains($responseContent, 'success')) {
                $this->processOrdersFile($filename);

                // Возвращаем success
                return response('success');
            }
        }

        return $response;
    }

    /**
     * Проверка: это файл заказов?
     */
    private function isOrdersFile(string $filename): bool
    {
        $basename = strtolower(basename($filename));
        return str_starts_with($basename, 'orders') ||
               str_starts_with($basename, 'sale') ||
               str_contains($basename, 'order');
    }

    /**
     * Обработка файла заказов
     */
    private function processOrdersFile(string $filename): void
    {
        try {
            $exchangeDir = config('protocolExchange1C.inputPath');
            $basename = basename($filename);

            // Собираем возможные пути к файлу
            $possiblePaths = [
                $exchangeDir . '/' . $basename,
                $exchangeDir . '/' . $filename,
            ];

            // Ищем во всех подпапках
            if (is_dir($exchangeDir)) {
                $subDirs = glob($exchangeDir . '/*/');
                foreach ($subDirs as $subDir) {
                    $possiblePaths[] = $subDir . $basename;
                }
            }

            // Ищем файл
            $foundPath = null;
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $foundPath = $path;
                    break;
                }
            }

            if ($foundPath) {
                Log::channel('1c-exchange')->info('Found orders file, processing', [
                    'path' => $foundPath,
                ]);

                $log = ExchangeLog::start(ExchangeLog::TYPE_ORDERS_IMPORT);

                $shopId = null;
                try { $shopId = app('current_shop_id'); } catch (\Throwable $e) {}
                if ($shopId) {
                    $log->update(['shop_id' => $shopId]);
                }

                $service = new OrderStatusSyncService();
                $count = $service->importFromFile($foundPath, $log->id);

                $log->refresh();
                if ($log->status === ExchangeLog::STATUS_STARTED) {
                    $log->success($count, "Обработано заказов: {$count}");
                }

                ExchangeDiagnosticsPublisher::publish($log, ['processed' => $count]);

                Log::channel('1c-exchange')->info('Orders file processed', [
                    'path' => $foundPath,
                    'orders_count' => $count,
                ]);
            } else {
                Log::channel('1c-exchange')->warning('ProcessOrdersAfterUnzip: Orders file not found', [
                    'filename' => $filename,
                    'searched_paths' => $possiblePaths,
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('1c-exchange')->error('ProcessOrdersAfterUnzip: Error processing orders file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
