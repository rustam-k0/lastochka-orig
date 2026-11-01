<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\OtpAttemptsExceededException;
use App\Exceptions\OtpCodeMismatchException;
use App\Exceptions\OtpExpiredOrUsedException;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;

final class OtpService
{
    /**
     * Длина SMS-кода по умолчанию. Фактическая длина задаётся AUTH_SMS_CODE_LENGTH
     * (config services.auth.sms_code_length) и возвращается codeLength().
     */
    public const int CODE_LENGTH_SMS = 6;

    public const int CODE_LENGTH_CALL = 4;

    /**
     * Допустимый диапазон длины кода: под него же настроена валидация поля code
     * в PhoneConfirmRequest / ChangePhoneConfirmRequest.
     */
    public const int MIN_CODE_LENGTH = 4;

    public const int MAX_CODE_LENGTH = 6;

    private const int EXPIRES_IN_MINUTES = 5;

    /**
     * Лимит попыток ввода действует НА КОНКРЕТНЫЙ КОД (запись otp_codes.attempts),
     * а не на номер телефона в целом. Осознанный компромисс: повторный запрос кода
     * («отправить код повторно») создаёт новую запись со свежим attempts=0, поэтому
     * формально можно продлить «бюджет» неверных вводов, перезапрашивая код. Общий
     * потолок на номер за окно времени всё равно даёт canSendOtp() — не более
     * maxRequests (по умолчанию 5) новых кодов за minutesWindow (по умолчанию 10
     * минут), т.е. не более 5 * MAX_ATTEMPTS неверных вводов за то же окно. Менять
     * на phone-level счётчик в рамках ALD-122 сознательно не стали — см. verify().
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * Сколько последних кодов номера остаются одновременно активными.
     *
     * SMS может прийти с задержкой: пользователь жмёт «отправить код повторно»
     * несколько раз и в итоге вводит код из одной из более ранних (но ещё не
     * истёкших) смс. Все они должны приниматься, а не только самый свежий.
     */
    private const int MAX_ACTIVE_CODES = 3;

    /**
     * Генерирует новый OTP код для телефона.
     *
     * @return OtpCode Модель с дополнительным свойством plain_code (plaintext код для отправки)
     */
    public function generate(string $phone, ?string $fixedCode = null, string $method = 'sms'): OtpCode
    {
        $plainCode = $fixedCode ?? $this->generateCode($this->codeLength($method));

        $otp = OtpCode::create([
            'phone' => $phone,
            'code' => Hash::make($plainCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'created_at' => now(),
        ]);

        // Добавляем plaintext код для отправки по SMS
        $otp->plain_code = $plainCode;

        // Держим активными не больше MAX_ACTIVE_CODES последних кодов номера,
        // чтобы пул активных кодов не рос бесконечно при частых повторных отправках.
        $this->pruneExcessActiveCodes($phone);

        return $otp;
    }

    /**
     * Проверяет OTP код: принимает любой из последних MAX_ACTIVE_CODES активных
     * (не истёкших и не использованных) кодов номера, а не только самый свежий.
     *
     * Инкремент счётчика попыток при неверном вводе выполняется здесь же и должен
     * вызываться ВНЕ откатываемой DB-транзакции — иначе при выбросе исключения ниже
     * по стеку счётчик откатится вместе с транзакцией и лимит попыток не сработает.
     *
     * @throws OtpExpiredOrUsedException Для номера нет ни одного активного кода
     * @throws OtpAttemptsExceededException Все активные коды исчерпали лимит попыток
     * @throws OtpCodeMismatchException Введённый код не совпал ни с одним активным
     */
    public function verify(string $phone, string $code): OtpCode
    {
        $activeCodes = OtpCode::query()
            ->forPhone($phone)
            ->active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($activeCodes->isEmpty()) {
            throw new OtpExpiredOrUsedException;
        }

        // Коды, которые ещё можно пробовать (не исчерпали лимит попыток)
        $candidates = $activeCodes->reject(
            fn (OtpCode $otp) => $otp->hasExceededAttempts(self::MAX_ATTEMPTS)
        );

        if ($candidates->isEmpty()) {
            throw new OtpAttemptsExceededException;
        }

        $matched = $candidates->first(
            fn (OtpCode $otp) => Hash::check($code, $otp->code)
        );

        if (! $matched) {
            // Введённый код не совпал ни с одним активным кандидатом — засчитываем
            // неверную попытку каждому из них (иначе неясно, какой код пытались ввести).
            //
            // Важно: это сознательно НЕ даёт единый счётчик на номер. attempts
            // считается per-code, поэтому запрос нового кода посреди неудачных
            // попыток заводит для него attempts=0 и фактически продлевает «бюджет»
            // неверных вводов на номер (пример: код A получил 2 неверных попытки,
            // затем запросили код B — окно MAX_ACTIVE_CODES=3 не обрезало A; ещё один
            // неверный ввод даёт A.attempts=3 (A выбывает из кандидатов), но
            // B.attempts=1, хотя ни разу не проверялся отдельно). Это принятое
            // решение по ALD-122: потолок на номер за время не нужен, т.к. его уже
            // даёт canSendOtp() (см. её докблок и MAX_ATTEMPTS выше) — сценарий
            // покрыт тестом AuthTest::test_phone_confirm_new_code_gets_fresh_attempt_budget_after_resend.
            foreach ($candidates as $otp) {
                $otp->incrementAttempts();
            }

            throw new OtpCodeMismatchException;
        }

        return $matched;
    }

    /**
     * Помечает код использованным и инвалидирует остальные активные коды номера.
     *
     * Без этого шага соседний (ещё не истёкший) код из окна MAX_ACTIVE_CODES
     * остался бы активным и позволил бы повторно войти по нему же (replay).
     */
    public function consume(OtpCode $otp): void
    {
        $otp->markAsVerified();

        OtpCode::query()
            ->forPhone($otp->phone)
            ->active()
            ->update(['verified_at' => now()]);
    }

    /**
     * Проверяет, можно ли отправить новый OTP (rate limiting).
     */
    public function canSendOtp(string $phone, int $maxRequests = 5, int $minutesWindow = 10): bool
    {
        $count = OtpCode::query()
            ->forPhone($phone)
            ->where('created_at', '>=', now()->subMinutes($minutesWindow))
            ->count();

        return $count < $maxRequests;
    }

    /**
     * Длина генерируемого кода для способа доставки.
     *
     * Звонок: всегда CODE_LENGTH_CALL. SMS: из AUTH_SMS_CODE_LENGTH, пустое или
     * нечисловое значение даёт CODE_LENGTH_SMS, выход за MIN/MAX_CODE_LENGTH
     * прижимается к границе (иначе код не прошёл бы валидацию при вводе).
     */
    public function codeLength(string $method = 'sms'): int
    {
        if ($method === 'call') {
            return self::CODE_LENGTH_CALL;
        }

        $configured = (int) config('services.auth.sms_code_length');

        if ($configured <= 0) {
            return self::CODE_LENGTH_SMS;
        }

        return max(self::MIN_CODE_LENGTH, min(self::MAX_CODE_LENGTH, $configured));
    }

    /**
     * Возвращает количество секунд до истечения OTP.
     */
    public function getExpiresInSeconds(): int
    {
        return self::EXPIRES_IN_MINUTES * 60;
    }

    /**
     * Инвалидирует активные коды номера сверх последних MAX_ACTIVE_CODES.
     */
    private function pruneExcessActiveCodes(string $phone): void
    {
        $idsToKeep = OtpCode::query()
            ->forPhone($phone)
            ->active()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ACTIVE_CODES)
            ->pluck('id');

        OtpCode::query()
            ->forPhone($phone)
            ->active()
            ->whereNotIn('id', $idsToKeep)
            ->update(['verified_at' => now()]);
    }

    /**
     * Возвращает статический код для тестового номера или null.
     */
    public function getTestPhoneCode(string $phone): ?string
    {
        return config('services.auth.test_phones')[$phone] ?? null;
    }

    /**
     * Генерирует случайный код.
     */
    private function generateCode(int $length = self::CODE_LENGTH_SMS): string
    {
        $max = (int) str_repeat('9', $length);

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
