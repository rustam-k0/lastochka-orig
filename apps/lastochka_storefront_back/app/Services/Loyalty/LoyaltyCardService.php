<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Models\StorefrontSetting;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LoyaltyCardService
{
    /**
     * Идемпотентно создаёт карту лояльности: генерит card_number и публикует
     * loyalty_card.created (1c_parser создаст заказ «Лояльность» для 1С).
     * Карта — только клиентам с телефоном: 1С матчит контрагента по телефону.
     *
     * @return bool true, если карта создана этим вызовом
     */
    public function ensureCard(User $user): bool
    {
        if ($user->card_number !== null) {
            return false;
        }

        if (!$user->phone) {
            return false;
        }

        return DB::transaction(function () use ($user) {
            /** @var User $locked */
            $locked = User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->card_number !== null || !$locked->phone) {
                // Проигранная гонка / нет телефона: синхронизируем caller-модель с фактическим состоянием
                $user->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            if (!$locked->loyalty_id) {
                $locked->ensureLoyaltyId();
            }

            $locked->card_number = $this->generateCardNumber();
            $locked->card_created_at = now();
            $locked->save();

            $this->publishCreated($locked);

            // Синхронизируем переданную модель с фактическим состоянием
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Номер карты: <префикс 2 симв.> + 6 случайных цифр. Уникальность — проверкой
     * в БД с retry; unique constraint на users.card_number страхует гонку.
     */
    public function generateCardNumber(): string
    {
        $prefix = $this->currentPrefix();
        $attempts = (int) config('loyalty.generate_attempts', 20);

        for ($i = 0; $i < $attempts; $i++) {
            $candidate = $prefix . $this->randomDigits();

            if (!User::withTrashed()->where('card_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Не удалось сгенерировать уникальный номер карты лояльности');
    }

    /**
     * Случайная 6-значная часть номера карты. Выделено в отдельный метод,
     * чтобы тест на collision/retry мог детерминированно подменить последовательность.
     */
    protected function randomDigits(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Актуальный префикс карт: настройка от 1С (barcode_prefixes.updated),
     * иначе дефолт из config.
     */
    public function currentPrefix(): string
    {
        $key = (string) config('loyalty.card_prefix_setting_key', 'loyalty_card_prefix');

        return (string) StorefrontSetting::getValue($key, config('loyalty.card_prefix', '07'));
    }

    /**
     * Публикация loyalty_card.created через прямой EventOutbox:
     * CustomEventPublisher блокирует 'created' как зарезервированный тип
     * (тот же паттерн, что BonusService::publishOperation).
     */
    public function publishCreated(User $user): void
    {
        $eventId = (string) Str::uuid();
        $name = trim(($user->last_name ? $user->last_name . ' ' : '') . ($user->first_name ?? ''));

        EventOutbox::create([
            'entity_type' => 'loyalty_cards',
            'entity_id'   => $user->getKey(),
            'event_type'  => 'created',
            'event_id'    => $eventId,
            'routing_key' => 'loyalty_card.created',
            'payload'     => [
                'event_id'     => $eventId,
                'event_type'   => 'created',
                'event_source' => config('rabbitmq-events.source'),
                'shop_code'    => config('rabbitmq-events.shop_code', 'STOREFRONT'),
                'user_id'      => (string) $user->id,
                'loyalty_id'   => $user->loyalty_id,
                'card_number'  => $user->card_number,
                'name'         => $name !== '' ? $name : null,
                'phone'        => $user->phone,
            ],
        ]);
    }
}
