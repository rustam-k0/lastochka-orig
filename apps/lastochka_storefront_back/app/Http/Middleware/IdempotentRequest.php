<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Идемпотентность неидемпотентных POST по заголовку Idempotency-Key.
 *
 * Мобильное приложение на слабой сети может не получить ответ и повторить
 * запрос. Для POST /cart/items повтор прибавляет количество ещё раз, то есть
 * задваивает товар. С заголовком повтор отдаёт сохранённый ответ первого
 * запроса и не выполняет действие повторно.
 *
 * Сознательные решения:
 * - Без заголовка поведение прежнее: старые версии приложения не ломаются.
 * - Ключ живёт в пространстве пользователя и маршрута: одинаковый ключ
 *   у разных пользователей или на разных ручках не пересекается.
 * - Сохраняются только 2xx. Ошибка ничего не меняет в корзине, поэтому
 *   повтор после неё безопасно выполняет запрос заново (например, остаток
 *   появился).
 * - Тот же ключ с другим телом запроса = ошибка клиента, 422, а не тихий
 *   возврат ответа на другой запрос.
 * - Параллельный дубль ждёт завершения первого запроса под локом и получает
 *   его ответ; если первый так и не завершился, 409.
 */
final class IdempotentRequest
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotency-Replayed';

    private const MAX_KEY_LENGTH = 128;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $request->user() === null) {
            return $next($request);
        }

        $key = trim($key);

        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return response()->json([
                'error' => 'IDEMPOTENCY_KEY_INVALID',
                'message' => 'Некорректный заголовок Idempotency-Key: ожидается непустая строка до '.self::MAX_KEY_LENGTH.' символов',
            ], 422);
        }

        $cacheKey = $this->cacheKey($request, $key);
        $fingerprint = $this->fingerprint($request);

        $lock = Cache::lock($cacheKey.':lock', (int) config('idempotency.lock_seconds', 30));

        try {
            $lock->block((int) config('idempotency.lock_wait_seconds', 10));
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                'message' => 'Запрос с этим ключом ещё выполняется, повторите позже',
            ], 409);
        }

        try {
            $stored = Cache::get($cacheKey);

            if (is_array($stored)) {
                if (($stored['fingerprint'] ?? null) !== $fingerprint) {
                    return response()->json([
                        'error' => 'IDEMPOTENCY_KEY_REUSED',
                        'message' => 'Ключ Idempotency-Key уже использован для другого запроса',
                    ], 422);
                }

                return $this->replay($stored);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => (string) $response->getContent(),
                    'content_type' => $response->headers->get('Content-Type', 'application/json'),
                ], (int) config('idempotency.ttl_seconds', 86400));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(Request $request, string $key): string
    {
        return 'idempotency:'.implode(':', [
            $request->user()->getAuthIdentifier(),
            $request->method(),
            hash('sha256', $request->path()),
            hash('sha256', $key),
        ]);
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', json_encode($request->all(), JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    /** @param  array{status:int, content:string, content_type:string}  $stored */
    private function replay(array $stored): Response
    {
        return response($stored['content'], $stored['status'], [
            'Content-Type' => $stored['content_type'],
            self::REPLAYED_HEADER => 'true',
        ]);
    }
}
