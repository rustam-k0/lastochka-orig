<?php

declare(strict_types=1);

namespace App\Services\Bonus;

use App\Models\BonusHold;
use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\User;
use App\RabbitMQ\Transformers\BonusOperationEventTransformer;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Единая точка расчёта бонусов по состоянию заказа.
 *
 * Модель:
 *   - Снапшот (order_bonuses.type/percent/requested_amount, order_items.eligible_for_bonus)
 *     фиксируется при создании item/заказа и не меняется.
 *   - Зеркало актуального состояния (orders.eligible_amount/bonus_earned/bonus_spent,
 *     order_bonuses.eligible_amount/amount) всегда равно результату чистой функции
 *     от снапшота и активных order_items.
 *   - Баланс пользователя и BonusTransaction — applied-стейт. Всегда применяется
 *     дельтой new - current, поэтому повторный вызов идемпотентен.
 */
final class BonusService
{
    /**
     * Обнулить бонусы при отмене заказа: вернуть списанные и отменить начисленные.
     * Идемпотентно через дельту.
     */
    public function refundAll(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->loadMissing('bonus');

            // Освободить незаконвертированные резервы (отмена до/после сборки)
            $pendingHolds = BonusHold::where('order_id', $order->id)
                ->where('status', BonusHold::STATUS_HELD)
                ->lockForUpdate()
                ->get();
            if ($pendingHolds->isNotEmpty()) {
                $holdUser = User::lockForUpdate()->findOrFail($order->user_id);
                foreach ($pendingHolds as $hold) {
                    $holdUser->bonus_reserved = max(0, (int) $holdUser->bonus_reserved - (int) $hold->amount);
                    $hold->forceFill(['status' => BonusHold::STATUS_RELEASED, 'resolved_at' => now()])->save();
                }
                $holdUser->save();
            }

            $currentSpent = $this->currentSpentForOrder($order->id);
            if ($currentSpent > 0) {
                $user = User::lockForUpdate()->findOrFail($order->user_id);
                $user->bonus_balance += $currentSpent;
                $user->save();

                $tx = BonusTransaction::create([
                    'user_id'       => $user->id,
                    'order_id'      => $order->id,
                    'type'          => 'refund',
                    'amount'        => $currentSpent,
                    'balance_after' => $user->bonus_balance,
                    'operation_id'  => (string) Str::uuid(),
                    'source'        => 'online',
                    'description'   => "Возврат за отменённый заказ #{$order->id}",
                    'created_at'    => now(),
                ]);
                $this->publishOperation($tx);

                $order->forceFill(['bonus_spent' => 0])->saveQuietly();
                if ($order->bonus && $order->bonus->type === 'spend') {
                    $order->bonus->forceFill(['amount' => 0])->saveQuietly();
                }
            }

            $currentCredited = $this->currentCreditedForOrder($order->id);
            if ($currentCredited > 0) {
                $user = User::lockForUpdate()->findOrFail($order->user_id);
                $cancel = min($currentCredited, (int) $user->bonus_balance);

                if ($cancel > 0) {
                    $user->bonus_balance -= $cancel;
                    $user->save();

                    $tx = BonusTransaction::create([
                        'user_id'       => $user->id,
                        'order_id'      => $order->id,
                        'type'          => 'cancel',
                        'amount'        => $cancel,
                        'balance_after' => $user->bonus_balance,
                        'operation_id'  => (string) Str::uuid(),
                        'source'        => 'online',
                        'description'   => "Отмена начисления за заказ #{$order->id}",
                        'created_at'    => now(),
                    ]);
                    $this->publishOperation($tx);
                }
            }
        });
    }

    /**
     * Пересчёт только зеркальных полей (orders.eligible_amount/bonus_earned/bonus_spent,
     * order_bonuses.eligible_amount/amount) без изменения баланса пользователя,
     * резерва, bonus_transactions и bonus_holds.
     *
     * Чистая функция от снапшота (order_bonuses) и активных order_items: баланс
     * пользователя не трогается. Фактическое списание/начисление — в applyOnAssembled().
     */
    public function recalculateMirror(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->loadMissing(['bonus', 'promocode']);
            $bonus = $order->bonus;
            if (! $bonus) {
                return;
            }

            $newEligible = $this->calculateEligibleAmount($order);
            $percent = (int) $bonus->percent;
            $requested = (int) ($bonus->requested_amount ?? $bonus->amount);

            $newEarned = 0;
            $newSpent = 0;

            if ($bonus->type === 'earn') {
                $newEarned = min((int) floor($newEligible * $percent / 100), $newEligible);
            } elseif ($bonus->type === 'spend') {
                $maxAllowed = (int) floor($newEligible * $percent / 100);
                $newSpent = min($requested, $maxAllowed);
            }

            $order->forceFill([
                'eligible_amount' => $newEligible,
                'bonus_earned' => $newEarned,
                'bonus_spent' => $newSpent,
            ])->saveQuietly();

            $bonus->forceFill([
                'eligible_amount' => $newEligible,
                'amount' => $bonus->type === 'earn' ? $newEarned : $newSpent,
            ])->saveQuietly();
        });
    }

    /**
     * Зарезервировать bonus_spent при создании заказа (тип spend).
     * Создаёт BonusHold(STATUS_HELD) и увеличивает users.bonus_reserved.
     * Идемпотентно: если held-hold уже существует — no-op.
     * Баланс пользователя и bonus_transactions не трогает.
     */
    public function reserveOnCreate(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->loadMissing('bonus');
            if (! $order->bonus || $order->bonus->type !== 'spend') {
                return;
            }

            $held = (int) $order->bonus_spent;
            if ($held <= 0) {
                return;
            }

            $exists = BonusHold::where('order_id', $order->id)
                ->where('status', BonusHold::STATUS_HELD)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return;
            }

            $user = User::lockForUpdate()->findOrFail($order->user_id);
            $user->bonus_reserved += $held;
            $user->save();

            BonusHold::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'amount' => $held,
                'status' => BonusHold::STATUS_HELD,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Освободить все held-резервы заказа при отмене до сборки.
     * Переводит BonusHold → STATUS_RELEASED, уменьшает users.bonus_reserved (clamp ≥ 0).
     */
    public function releaseHolds(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $holds = BonusHold::where('order_id', $order->id)
                ->where('status', BonusHold::STATUS_HELD)
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                return;
            }

            $user = User::lockForUpdate()->findOrFail($order->user_id);
            foreach ($holds as $hold) {
                $user->bonus_reserved = max(0, (int) $user->bonus_reserved - (int) $hold->amount);
                $hold->forceFill([
                    'status' => BonusHold::STATUS_RELEASED,
                    'resolved_at' => now(),
                ])->save();
            }
            $user->save();
        });
    }

    /**
     * Конвертировать резерв в фактическое списание и начислить earn по факту сборки.
     * Идемпотентно: если spend-транзакция с source=online уже существует — не списывать повторно.
     * Holds переводятся в converted в любом случае. Earn применяется дельтой.
     */
    public function applyOnAssembled(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $this->recalculateMirror($order);
            $order->refresh()->loadMissing('bonus');

            // --- SPEND: конвертация holds в фактическое списание ---
            if ($order->bonus && $order->bonus->type === 'spend') {
                $actual = (int) $order->bonus_spent;

                $alreadySpent = BonusTransaction::where('order_id', $order->id)
                    ->where('type', 'spend')
                    ->where('source', 'online')
                    ->exists();

                $holds = BonusHold::where('order_id', $order->id)
                    ->where('status', BonusHold::STATUS_HELD)
                    ->lockForUpdate()
                    ->get();

                $user = User::lockForUpdate()->findOrFail($order->user_id);

                if (! $alreadySpent && $actual > 0) {
                    $applied = min($actual, (int) $user->bonus_balance);
                    $user->bonus_balance -= $applied;
                    $tx = BonusTransaction::create([
                        'user_id'       => $user->id,
                        'order_id'      => $order->id,
                        'type'          => 'spend',
                        'amount'        => $applied,
                        'balance_after' => $user->bonus_balance,
                        'operation_id'  => (string) Str::uuid(),
                        'source'        => 'online',
                        'description'   => "Списание за заказ #{$order->id}",
                        'created_at'    => now(),
                    ]);
                    $this->publishOperation($tx);
                }

                foreach ($holds as $hold) {
                    $user->bonus_reserved = max(0, (int) $user->bonus_reserved - (int) $hold->amount);
                    $hold->forceFill(['status' => BonusHold::STATUS_CONVERTED, 'resolved_at' => now()])->save();
                }
                $user->save();
            }

            // --- EARN: начисление по факту сборки (дельта, идемпотентно) ---
            $targetEarn = (int) $order->bonus_earned;
            $currentCredited = $this->currentCreditedForOrder($order->id);
            $deltaEarn = $targetEarn - $currentCredited;
            if ($deltaEarn > 0) {
                $user = User::lockForUpdate()->findOrFail($order->user_id);
                $user->bonus_balance += $deltaEarn;
                $user->save();
                $tx = BonusTransaction::create([
                    'user_id'       => $user->id,
                    'order_id'      => $order->id,
                    'type'          => 'earn',
                    'amount'        => $deltaEarn,
                    'balance_after' => $user->bonus_balance,
                    'operation_id'  => (string) Str::uuid(),
                    'source'        => 'online',
                    'description'   => "Начисление за заказ #{$order->id}",
                    'created_at'    => now(),
                ]);
                $this->publishOperation($tx);
            }
        });
    }

    /**
     * Применить офлайн-операцию (из 1С/кассы) к балансу пользователя.
     * Идемпотентно: если operation_id уже записан — no-op.
     * Пользователь ищется по loyalty_id; если не найден — warning + return.
     */
    public function applyExternalOperation(array $op): void
    {
        $operationId = $op['operation_id'] ?? null;
        $loyaltyId = $op['loyalty_id'] ?? null;
        $type = $op['type'] ?? null;
        $amount = (int) ($op['amount'] ?? 0);
        $description = trim((string) ($op['description'] ?? '')) ?: 'Офлайн-операция (касса)';

        if (! $operationId || ! $loyaltyId || $amount <= 0 || ! in_array($type, ['earn', 'spend', 'refund', 'cancel'], true)) {
            return;
        }

        DB::transaction(function () use ($operationId, $loyaltyId, $type, $amount, $description) {
            $user = User::where('loyalty_id', $loyaltyId)->lockForUpdate()->first();
            if (! $user) {
                \Illuminate\Support\Facades\Log::warning('Bonus offline op: user not found by loyalty_id', [
                    'operation_id' => $operationId,
                    'loyalty_id' => $loyaltyId,
                ]);

                return;
            }

            if (BonusTransaction::where('operation_id', $operationId)->exists()) {
                return; // идемпотентность
            }

            $isCredit = in_array($type, ['earn', 'refund'], true);
            if ($isCredit) {
                $user->bonus_balance += $amount;
            } else {
                $user->bonus_balance = max(0, (int) $user->bonus_balance - $amount); // clamp
            }
            $user->save();

            $tx = BonusTransaction::create([
                'user_id'      => $user->id,
                'order_id'     => null,
                'type'         => $type,
                'amount'       => $amount,
                'balance_after' => $user->bonus_balance,
                'operation_id' => $operationId,
                'source'       => 'offline',
                'description'  => $description,
                'created_at'   => now(),
            ]);

            // Факт уезжает в шину, чтобы офлайн-операция с кассы попала в зеркало
            // журнала в админке. Своё же эхо отсекается идемпотентностью по operation_id.
            $this->publishOperation($tx);
        });
    }

    /**
     * Применить ручную операцию администратора (команда bonus_operation.requested).
     * Идемпотентно по operation_id. Списание ограничено доступным балансом
     * (balance - reserved), чтобы не сломать уже оформленный заказ клиента.
     * Любая невозможность применить операцию публикуется как bonus_operation.rejected,
     * чтобы админка закрыла свою pending-запись с причиной.
     */
    public function applyManualOperation(array $op): void
    {
        $operationId = isset($op['operation_id']) ? (string) $op['operation_id'] : '';
        $type = $op['type'] ?? null;
        $amount = (int) ($op['amount'] ?? 0);
        $comment = trim((string) ($op['comment'] ?? ''));
        $loyaltyId = $op['loyalty_id'] ?? null;
        $phone = $op['phone'] ?? null;

        if ($operationId === '') {
            // Без operation_id публиковать bonus_operation.rejected не с чем
            // сопоставить на стороне админки — отказ не отправляем, только логируем.
            Log::warning('Manual bonus op without operation_id', $op);

            return;
        }

        if (! in_array($type, ['earn', 'spend'], true) || $amount <= 0 || $comment === '') {
            $this->publishRejection($operationId, 'invalid_payload', 'Некорректные параметры операции');

            return;
        }

        DB::transaction(function () use ($operationId, $type, $amount, $comment, $loyaltyId, $phone) {
            // lockForUpdate на проверке существования: на MySQL SELECT ... FOR UPDATE
            // по уникальному индексу operation_id берёт gap-lock и сериализует
            // конкурентные вставки одного и того же operation_id, так что вторая
            // параллельная доставка команды видит уже вставленную строку и уходит
            // в идемпотентный no-op вместо падения на unique constraint. SQLite
            // в тестах блокировку игнорирует (однопоточная запись), но семантика
            // не нарушается.
            $existing = BonusTransaction::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                // Идемпотентность: операция уже применена. Но факт bonus_operation.created
                // мог не доехать до админки (очередь ещё не была создана, консьюмер лежал
                // дольше числа ретраев и сообщение ушло в DLQ) — тогда запись в админке
                // навсегда виснет в статусе «в обработке», а переотправить нечем, так как
                // повторная доставка той же команды всегда попадает в эту ветку. Поэтому
                // вместо тихого return переиздаём то же событие с тем же operation_id: приём
                // в админке идемпотентен (upsert по operation_id), повтор безопасен и это
                // штатный способ долечить потерянный ответ. Не заменять обратно на return
                // без публикации.
                $this->publishOperation($existing);

                return;
            }

            $phoneArg = $phone !== null ? (string) $phone : null;

            $resolved = $this->resolveUserForManualOperation(
                $loyaltyId !== null ? (string) $loyaltyId : null,
                $phoneArg
            );

            if (! $resolved) {
                // Код причины один и тот же (его читают программно), а текст для
                // оператора разный: пустые идентификаторы и «телефон был, но не
                // распознан» — разные ситуации, и оператор должен понимать, что
                // именно проверять. $phoneArg повторяет ту же проверку truthiness,
                // что и внутри resolveUserForManualOperation(), поэтому ветка тут
                // всегда совпадает с тем, что реально произошло в резолве.
                $message = $phoneArg
                    ? 'Телефон указан, но не удалось распознать номер. Проверьте формат телефона в карточке клиента.'
                    : 'Клиента не удалось определить: нет ни loyalty_id, ни телефона';

                $this->publishRejection($operationId, 'user_not_resolvable', $message);

                return;
            }

            $user = User::lockForUpdate()->findOrFail($resolved->id);

            if ($type === 'spend') {
                $available = max(0, (int) $user->bonus_balance - (int) $user->bonus_reserved);
                if ($amount > $available) {
                    $this->publishRejection(
                        $operationId,
                        'insufficient_balance',
                        "Доступно к списанию: {$available}"
                    );

                    return;
                }
                $user->bonus_balance -= $amount;
            } else {
                $user->bonus_balance += $amount;
            }

            $user->save();

            $tx = BonusTransaction::create([
                'user_id'       => $user->id,
                'order_id'      => null,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $user->bonus_balance,
                'operation_id'  => $operationId,
                'source'        => 'manual',
                'description'   => $comment,
                'created_at'    => now(),
            ]);

            $this->publishOperation($tx);
        });
    }

    private function calculateEligibleAmount(Order $order): int
    {
        if ($order->promocode) {
            return 0;
        }

        return (int) $order->items()
            ->where('eligible_for_bonus', true)
            ->where(function ($q) {
                $q->whereNotIn('status', ['cancelled', 'replaced'])
                    ->orWhereNull('status');
            })
            ->sum('total');
    }

    /**
     * Публикует BonusTransaction в RabbitMQ как bonus_operation.created.
     * Публикуем и для пользователя без loyalty_id: зеркало журнала в админке
     * должно быть полным, а идентифицировать клиента там умеют по client_phone.
     *
     * CustomEventPublisher блокирует 'created' как зарезервированный тип,
     * поэтому пишем в outbox напрямую через Transactional Outbox pattern.
     */
    private function publishOperation(BonusTransaction $tx): void
    {
        $user = $tx->user()->first();
        if (! $user) {
            return;
        }

        $eventId = (string) Str::uuid();
        $payload = (new BonusOperationEventTransformer())->toEvent($tx, null, $eventId);

        EventOutbox::create([
            'entity_type' => 'bonus_operations',
            'entity_id'   => $tx->getKey(),
            'event_type'  => 'created',
            'event_id'    => $eventId,
            'routing_key' => 'bonus_operation.created',
            'payload'     => $payload,
        ]);
    }

    /**
     * Клиент для ручной операции: сначала по loyalty_id, затем по телефону.
     * Телефон неизвестен системе — создаём минимальную запись, чтобы баллы
     * дождались клиента при первом входе по OTP (тот же приём, что в
     * CustomerB2bStatusTransformer). withoutEvents, чтобы не публиковать
     * обратно customer.created с пустым профилем.
     *
     * Телефон сравнивается по последним 10 цифрам, а не строкой as-is: команда
     * может прийти в формате «своей» системы (админка/1С/касса — 89896544847,
     * с пробелами/скобками/дефисами), а в БД клиент лежит в другом формате
     * (+79896544847). Строгое сравнение находило «нового» человека и заводило
     * дубликат учётки вместо начисления существующему (проявилось на проде).
     * Новый пользователь всегда создаётся с телефоном, нормализованным к
     * +7XXXXXXXXXX, — иначе при первом входе клиента по OTP со своим обычным
     * номером получится вторая учётка.
     *
     * Номер, из которого нельзя однозначно выделить 10-значный абонентский
     * номер (не 10 цифр и не 11 с ведущей 7/8 — «доб. 123», обрывок, мусор),
     * считается неразобранным: пользователь НЕ создаётся, метод возвращает
     * null, а applyManualOperation() публикует user_not_resolvable. Домыслить
     * код страны из хвоста произвольной длины — значит рискнуть подставить
     * случайного «соседа» по цифрам вместо реального клиента, тот же баг,
     * который тут и чинится, просто с другого входа.
     */
    private function resolveUserForManualOperation(?string $loyaltyId, ?string $phone): ?User
    {
        if ($loyaltyId) {
            $byLoyalty = User::where('loyalty_id', $loyaltyId)->first();
            if ($byLoyalty) {
                return $byLoyalty;
            }
        }

        if (! $phone) {
            return null;
        }

        $lastTenDigits = $this->lastTenPhoneDigits($phone);
        if ($lastTenDigits === null) {
            return null;
        }

        $normalizedPhone = "+7{$lastTenDigits}";

        $byPhone = $this->findUserByLastTenPhoneDigits($lastTenDigits);
        if ($byPhone) {
            return $byPhone;
        }

        try {
            return User::withoutEvents(fn () => User::create(['phone' => $normalizedPhone]));
        } catch (QueryException $e) {
            // Та же гонка, что и с operation_id, но на users.phone (уникальный):
            // два конкурентных вызова с разными operation_id и одним и тем же
            // неизвестным телефоном одновременно пытаются создать пользователя.
            // Тот, кто проиграл гонку на unique constraint, не падает, а находит
            // запись, созданную победителем.
            $isDuplicate = $e->getCode() === '23000'
                || str_contains($e->getMessage(), 'Duplicate entry')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            if (! $isDuplicate) {
                throw $e;
            }

            // lockForUpdate, а не обычный first(): под MySQL REPEATABLE READ снимок
            // non-locking чтения зафиксирован ещё на поиске по телефону ДО create()
            // выше, то есть до коммита конкурента, и обычный first() тут вернёт null
            // из того же снимка. Блокирующее чтение всегда читает последнюю
            // закоммиченную версию, поэтому находит строку, созданную победителем гонки.
            return $this->findUserByLastTenPhoneDigits($lastTenDigits, lockForUpdate: true);
        }
    }

    /**
     * Цифры номера телефона по порядку появления. Работает по массиву
     * символов (mb_str_split), а не substr/strlen — номер может прийти
     * с любым мусором, в т.ч. многобайтовым (например, «доб.» кириллицей).
     *
     * @return list<string>
     */
    private function phoneDigits(string $phone): array
    {
        return array_values(array_filter(
            mb_str_split($phone),
            static fn (string $char): bool => $char >= '0' && $char <= '9'
        ));
    }

    /**
     * 10-значный абонентский номер (без кода страны), извлечённый из сырого
     * ввода, либо null, если номер не раскладывается однозначно.
     *
     * Принимаются только две осмысленные длины: ровно 10 цифр (абонентский
     * номер без кода страны) или 11 цифр с ведущей 7/8 (полный российский
     * номер). Любая другая длина — добавочный «доб. 123», обрывок, вставленный
     * мусор — не разбирается: без верхней границы «взять последние 10 цифр»
     * от 14-значного мусора даёт случайные 10 цифр чужого номера вместо
     * признака ошибки разбора.
     */
    private function lastTenPhoneDigits(string $phone): ?string
    {
        $digits = $this->phoneDigits($phone);
        $count = count($digits);

        if ($count === 10) {
            return implode('', $digits);
        }

        if ($count === 11 && in_array($digits[0], ['7', '8'], true)) {
            return implode('', array_slice($digits, 1));
        }

        return null;
    }

    /**
     * Найти пользователя, чей телефон оканчивается на те же 10 цифр,
     * независимо от формата записи (8/+7/пробелы/скобки/дефисы) — причём
     * и во входящей команде, и в уже сохранённом значении колонки.
     *
     * Портируемо между MySQL (прод) и SQLite (тесты, phpunit.xml —
     * :memory:): REGEXP_REPLACE в SQLite нет, поэтому колонка чистится
     * вложенными REPLACE (стандартная функция в обеих СУБД) от `+`, пробела,
     * скобок и дефиса — тех же разделителей, что могут затесаться внутрь
     * значимых цифр, если номер попал в БД без нормализации (см. например
     * CustomerB2bStatusTransformer). Финальное сравнение — всегда по цифрам
     * в PHP: LIKE тут не источник истины, а только сужение выборки на
     * небольшой таблице (индекс по выражению не нужен), защита от случайного
     * совпадения хвоста строки остаётся на PHP-сверке.
     *
     * Порядок при нескольких кандидатах — не произвольный: уникальность
     * телефона в БД держится на сырой строке, поэтому один человек мог
     * оказаться представлен двумя записями в разных форматах (прод-факт).
     * Приоритет — записи с заполненным loyalty_id (настоящий, уже
     * идентифицированный клиент), при прочих равных — наименьший id
     * (самый старый, то есть основной аккаунт).
     */
    private function findUserByLastTenPhoneDigits(string $lastTenDigits, bool $lockForUpdate = false): ?User
    {
        $cleanedPhoneSql = "replace(replace(replace(replace(replace("
            ."phone, '+', ''), ' ', ''), '(', ''), ')', ''), '-', '')";

        $query = User::whereRaw("{$cleanedPhoneSql} like ?", ['%'.$lastTenDigits])
            ->orderByRaw("(case when loyalty_id is null or loyalty_id = '' then 1 else 0 end) asc")
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->first(fn (User $candidate) => $this->lastTenPhoneDigits((string) $candidate->phone) === $lastTenDigits);
    }

    /**
     * Публикует отказ по команде. entity_id = 0: локальной сущности нет,
     * а колонка event_outbox.entity_id не nullable.
     */
    private function publishRejection(string $operationId, string $reason, ?string $message = null): void
    {
        $eventId = (string) Str::uuid();

        EventOutbox::create([
            'entity_type' => 'bonus_operations',
            'entity_id'   => 0,
            'event_type'  => 'rejected',
            'event_id'    => $eventId,
            'routing_key' => 'bonus_operation.rejected',
            'payload'     => [
                'event_id'        => $eventId,
                'event_timestamp' => now()->toIso8601String(),
                'event_source'    => config('rabbitmq-events.source'),
                'source'          => config('rabbitmq-events.source'),
                'operation_id'    => $operationId,
                'reason'          => $reason,
                'message'         => $message,
            ],
        ]);
    }

    private function currentCreditedForOrder(int $orderId): int
    {
        $earned = (int) BonusTransaction::where('order_id', $orderId)
            ->where('type', 'earn')
            ->sum('amount');

        $cancelled = (int) BonusTransaction::where('order_id', $orderId)
            ->where('type', 'cancel')
            ->sum('amount');

        return max(0, $earned - $cancelled);
    }

    /**
     * Фактически списанная (нетто) сумма online-бонусов по заказу:
     * Σ(spend, online) − Σ(refund, online). До сборки == 0 (резерв ещё не сконвертирован
     * в списание), поэтому отмена до сборки не возвращает «фантомные» бонусы.
     */
    private function currentSpentForOrder(int $orderId): int
    {
        $spent = (int) BonusTransaction::where('order_id', $orderId)
            ->where('type', 'spend')->where('source', 'online')->sum('amount');
        $refunded = (int) BonusTransaction::where('order_id', $orderId)
            ->where('type', 'refund')->where('source', 'online')->sum('amount');

        return max(0, $spent - $refunded);
    }
}
