<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Идемпотентность POST по заголовку Idempotency-Key
    |--------------------------------------------------------------------------
    |
    | См. App\Http\Middleware\IdempotentRequest. Ответ первого успешного
    | запроса хранится в кеше приложения ttl_seconds, повтор с тем же ключом
    | отдаёт его без повторного выполнения.
    |
    */

    'ttl_seconds' => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),

    // Сколько держится лок на время выполнения первого запроса.
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 30),

    // Сколько параллельный дубль ждёт первый запрос, прежде чем получить 409.
    'lock_wait_seconds' => (int) env('IDEMPOTENCY_LOCK_WAIT_SECONDS', 10),

];
