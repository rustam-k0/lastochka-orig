<?php

declare(strict_types=1);

namespace Tests\Feature\Bonus;

use App\Models\BonusTransaction;
use App\Models\User;
use App\Services\Bonus\BonusService;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyManualOperationTest extends TestCase
{
    use RefreshDatabase;

    private function op(array $override = []): array
    {
        return array_merge([
            'operation_id' => 'ADM-OP-1',
            'type' => 'earn',
            'amount' => 100,
            'comment' => 'Компенсация за просроченный товар',
            'phone' => '+79001112233',
            'loyalty_id' => null,
        ], $override);
    }

    public function test_manual_earn_credits_balance_and_writes_transaction(): void
    {
        $user = User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 50]);

        app(BonusService::class)->applyManualOperation($this->op());

        $this->assertSame(150, (int) $user->fresh()->bonus_balance);

        $tx = BonusTransaction::where('operation_id', 'ADM-OP-1')->firstOrFail();
        $this->assertSame('earn', $tx->type);
        $this->assertSame('manual', $tx->source);
        $this->assertSame(100, (int) $tx->amount);
        $this->assertSame(150, (int) $tx->balance_after);
        $this->assertSame('Компенсация за просроченный товар', $tx->description);
        $this->assertNull($tx->order_id);
    }

    public function test_manual_spend_debits_balance(): void
    {
        $user = User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 300]);

        app(BonusService::class)->applyManualOperation($this->op(['type' => 'spend', 'amount' => 120]));

        $this->assertSame(180, (int) $user->fresh()->bonus_balance);
        $this->assertSame(180, (int) BonusTransaction::where('operation_id', 'ADM-OP-1')->value('balance_after'));
    }

    public function test_manual_spend_over_available_is_rejected_and_balance_untouched(): void
    {
        $user = User::factory()->create([
            'phone' => '+79001112233',
            'bonus_balance' => 300,
            'bonus_reserved' => 250,
        ]);

        app(BonusService::class)->applyManualOperation($this->op(['type' => 'spend', 'amount' => 100]));

        $this->assertSame(300, (int) $user->fresh()->bonus_balance);
        $this->assertSame(0, BonusTransaction::where('operation_id', 'ADM-OP-1')->count());

        $row = EventOutbox::where('routing_key', 'bonus_operation.rejected')->latest('id')->firstOrFail();
        $this->assertSame('ADM-OP-1', $row->payload['operation_id']);
        $this->assertSame('insufficient_balance', $row->payload['reason']);
    }

    public function test_manual_operation_is_idempotent_by_operation_id(): void
    {
        // Повторная доставка той же команды (например, после ретрая, когда
        // предыдущий bonus_operation.created не доехал до админки и ушёл в DLQ)
        // не должна ни создавать вторую транзакцию, ни менять баланс, но обязана
        // переиздать факт: это единственный штатный способ долечить запись,
        // навсегда зависшую в админке в статусе «в обработке».
        $user = User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 0]);

        $svc = app(BonusService::class);
        $svc->applyManualOperation($this->op());
        $svc->applyManualOperation($this->op());

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, BonusTransaction::where('operation_id', 'ADM-OP-1')->count());

        $createdEvents = EventOutbox::where('routing_key', 'bonus_operation.created')
            ->get()
            ->filter(fn (EventOutbox $row) => ($row->payload['operation_id'] ?? null) === 'ADM-OP-1');

        $this->assertSame(2, $createdEvents->count());
    }

    public function test_unknown_phone_creates_minimal_user_and_credits_it(): void
    {
        app(BonusService::class)->applyManualOperation($this->op(['phone' => '+79005556677']));

        $user = User::where('phone', '+79005556677')->firstOrFail();
        $this->assertSame(100, (int) $user->bonus_balance);
    }

    public function test_loyalty_id_takes_priority_over_phone(): void
    {
        $byLoyalty = User::factory()->create(['loyalty_id' => 'LTY-PRIORITY', 'bonus_balance' => 10]);
        $byPhone = User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 10]);

        app(BonusService::class)->applyManualOperation($this->op(['loyalty_id' => 'LTY-PRIORITY']));

        $this->assertSame(110, (int) $byLoyalty->fresh()->bonus_balance);
        $this->assertSame(10, (int) $byPhone->fresh()->bonus_balance);
    }

    public function test_second_manual_operation_for_same_unknown_phone_reuses_previously_created_user(): void
    {
        // Проверяет обычный последовательный поиск ($byPhone = User::where(...)->first())
        // ДО try/create в resolveUserForManualOperation: второй вызов находит
        // пользователя, созданного первым, ещё до попытки create(). Ветка catch
        // (конкурентное создание, рефетч через lockForUpdate) на SQLite in-memory
        // тестами не покрывается — тесты выполняются в один поток, конкурентную
        // вставку двух транзакций не воспроизвести.
        $svc = app(BonusService::class);

        $svc->applyManualOperation($this->op(['operation_id' => 'ADM-OP-1', 'phone' => '+79005556677']));
        $svc->applyManualOperation($this->op(['operation_id' => 'ADM-OP-2', 'phone' => '+79005556677']));

        $this->assertSame(1, User::where('phone', '+79005556677')->count());
        $user = User::where('phone', '+79005556677')->firstOrFail();
        $this->assertSame(200, (int) $user->bonus_balance);
        $this->assertSame(2, BonusTransaction::where('user_id', $user->id)->count());
    }

    public function test_invalid_payload_is_rejected_without_touching_balance(): void
    {
        $user = User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 100]);

        app(BonusService::class)->applyManualOperation($this->op(['amount' => 0]));

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $row = EventOutbox::where('routing_key', 'bonus_operation.rejected')->latest('id')->firstOrFail();
        $this->assertSame('invalid_payload', $row->payload['reason']);
    }

    public function test_empty_comment_is_rejected(): void
    {
        User::factory()->create(['phone' => '+79001112233', 'bonus_balance' => 100]);

        app(BonusService::class)->applyManualOperation($this->op(['comment' => '   ']));

        $this->assertSame(0, BonusTransaction::where('operation_id', 'ADM-OP-1')->count());
        $row = EventOutbox::where('routing_key', 'bonus_operation.rejected')->latest('id')->firstOrFail();
        $this->assertSame('invalid_payload', $row->payload['reason']);
    }

    // ==================== Нормализация телефона (LASTOCHKI phone-format bug) ====================
    //
    // Баг на проде: клиент лежит в БД с телефоном +79896544847, админка прислала
    // команду с телефоном в собственном формате 89896544847 — строгое сравнение
    // не нашло клиента и создало вторую пустую учётку. Ниже — покрытие того,
    // что resolveUserForManualOperation() сравнивает телефоны по последним 10
    // цифрам независимо от формата записи (8/+7/пробелы/скобки/дефисы).

    public function test_admin_format_phone_command_matches_user_stored_in_e164_format(): void
    {
        $user = User::factory()->create(['phone' => '+79896544847', 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '89896544847',
            'loyalty_id' => null,
        ]));

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, User::count());
    }

    public function test_e164_format_phone_command_matches_user_stored_in_admin_format(): void
    {
        $user = User::factory()->create(['phone' => '89896544847', 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '+79896544847',
            'loyalty_id' => null,
        ]));

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, User::count());
    }

    public function test_manual_operation_matches_phone_with_spaces_parentheses_and_dashes(): void
    {
        $user = User::factory()->create(['phone' => '+79896544847', 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '8 (989) 654-48-47',
            'loyalty_id' => null,
        ]));

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, User::count());
    }

    public function test_unknown_admin_format_phone_creates_single_user_with_normalized_e164_phone(): void
    {
        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '89896544847',
            'loyalty_id' => null,
        ]));

        $user = User::where('phone', '+79896544847')->firstOrFail();
        $this->assertSame(100, (int) $user->bonus_balance);
        $this->assertSame(1, User::count());
    }

    public function test_loyalty_id_priority_over_phone_even_when_phone_normalizes_to_another_existing_user(): void
    {
        $byLoyalty = User::factory()->create([
            'loyalty_id' => 'LTY-PRIORITY-2',
            'phone' => '+79000000001',
            'bonus_balance' => 10,
        ]);
        $byPhone = User::factory()->create(['phone' => '+79896544847', 'bonus_balance' => 10]);

        app(BonusService::class)->applyManualOperation($this->op([
            'loyalty_id' => 'LTY-PRIORITY-2',
            'phone' => '89896544847', // нормализовался бы к $byPhone, но loyalty_id обязан победить
        ]));

        $this->assertSame(110, (int) $byLoyalty->fresh()->bonus_balance);
        $this->assertSame(10, (int) $byPhone->fresh()->bonus_balance);
    }

    public function test_phone_differing_in_last_ten_digits_does_not_match_existing_user(): void
    {
        $existing = User::factory()->create(['phone' => '+79896544847', 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '89896544848', // отличается последней цифрой — другой человек
            'loyalty_id' => null,
        ]));

        $this->assertSame(0, (int) $existing->fresh()->bonus_balance);

        $newUser = User::where('phone', '+79896544848')->firstOrFail();
        $this->assertSame(100, (int) $newUser->bonus_balance);
        $this->assertSame(2, User::count());
    }

    // ==================== Фикс-раунд 1 (ревью team-lead) ====================

    public function test_manual_operation_matches_user_whose_stored_phone_has_separators_inside_digits(): void
    {
        // В БД телефон лежит с разделителями ВНУТРИ значимых 10 цифр (так его
        // мог записать сторонний трансформер без нормализации). Сужение выборки
        // по LIKE на сырую колонку такое значение не находит — колонку тоже
        // нужно чистить на стороне SQL, не только входящую команду в PHP.
        $user = User::factory()->create(['phone' => '+7 989 654-48-47', 'bonus_balance' => 0]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '89896544847',
            'loyalty_id' => null,
        ]));

        $this->assertSame(100, (int) $user->fresh()->bonus_balance);
        $this->assertSame(1, User::count());
    }

    public function test_garbled_phone_with_extra_digits_is_rejected_and_creates_no_user(): void
    {
        // "89896544847доб123" — 14 цифр после извлечения. Наивное "взять
        // последние 10" даёт "6544847123" — случайный чужой номер, а не код
        // ошибки разбора. Длина вне {10, 11-с-ведущей-7/8} должна считаться
        // неразобранным номером: без домыслов, без фантомного пользователя.
        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '89896544847доб123',
            'loyalty_id' => null,
        ]));

        $this->assertSame(0, User::count());

        $row = EventOutbox::where('routing_key', 'bonus_operation.rejected')->latest('id')->firstOrFail();
        $this->assertSame('ADM-OP-1', $row->payload['operation_id']);
        $this->assertSame('user_not_resolvable', $row->payload['reason']);
        $this->assertSame(
            'Телефон указан, но не удалось распознать номер. Проверьте формат телефона в карточке клиента.',
            $row->payload['message']
        );
    }

    public function test_missing_identifiers_rejects_with_no_loyalty_or_phone_message(): void
    {
        // Раньше это был ЕДИНСТВЕННЫЙ повод для user_not_resolvable — команда
        // вообще без loyalty_id и телефона. Текст должен остаться прежним:
        // отличается от случая «телефон был, но не распознан» (тест выше),
        // оператор должен видеть разные причины разными словами.
        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => null,
            'loyalty_id' => null,
        ]));

        $this->assertSame(0, User::count());

        $row = EventOutbox::where('routing_key', 'bonus_operation.rejected')->latest('id')->firstOrFail();
        $this->assertSame('user_not_resolvable', $row->payload['reason']);
        $this->assertSame(
            'Клиента не удалось определить: нет ни loyalty_id, ни телефона',
            $row->payload['message']
        );
    }

    public function test_manual_operation_with_multiple_phone_matches_prefers_record_with_loyalty_id(): void
    {
        // Уникальность телефона в БД — на сырой строке, поэтому один человек
        // может быть представлен двумя записями в разных форматах (прод-факт).
        // Порядок вставки специально "неправильный": пустой дубль без карты —
        // ПЕРВЫЙ (меньший id), учётка с loyalty_id — ВТОРОЙ (больший id).
        // Наивная реализация без явного ORDER BY взяла бы первую попавшуюся
        // строку и начислила бы в пустой дубль.
        $empty = User::factory()->create([
            'phone' => '89896544847',
            'loyalty_id' => null,
            'bonus_balance' => 0,
        ]);
        $withLoyalty = User::factory()->create([
            'phone' => '+79896544847',
            'loyalty_id' => 'LTY-DUP-WINNER',
            'bonus_balance' => 0,
        ]);

        app(BonusService::class)->applyManualOperation($this->op([
            'phone' => '8 (989) 654-48-47',
            'loyalty_id' => null,
        ]));

        $this->assertSame(0, (int) $empty->fresh()->bonus_balance);
        $this->assertSame(100, (int) $withLoyalty->fresh()->bonus_balance);
    }
}
