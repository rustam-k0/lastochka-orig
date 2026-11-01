<?php

declare(strict_types=1);

namespace App\Services\Consent;

use App\Enums\ConsentContext;
use App\Enums\ConsentDocument;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Log;

/**
 * Фиксация согласий пользователя с документами (MENDEV-7).
 *
 * Точки сбора:
 * - авторизация: политика конфиденциальности + перс. данные + оферта;
 * - ручка согласия 18+ и adultConfirmed при авторизации: adult (включая отказ);
 * - создание заказа: перс. данные + оферта;
 * - отклик на вакансию и форма обратной связи: перс. данные (user опционален).
 *
 * Фиксация никогда не валит основную операцию: ошибка записи логируется,
 * авторизация/заказ/отклик проходят как обычно.
 */
final class ConsentService
{
    /**
     * @param  list<ConsentDocument>  $documents
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        ?User $user,
        array $documents,
        ConsentContext $context,
        bool $accepted = true,
        ?string $ip = null,
        ?array $meta = null,
    ): void {
        foreach ($documents as $document) {
            try {
                UserConsent::create([
                    'user_id' => $user?->id,
                    'document_type' => $document,
                    'context' => $context,
                    'accepted' => $accepted,
                    'ip' => $ip,
                    'meta' => $meta,
                ]);
            } catch (\Throwable $e) {
                Log::error('Не удалось зафиксировать согласие', [
                    'user_id' => $user?->id,
                    'document' => $document->value,
                    'context' => $context->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
