<?php

namespace App\RabbitMQ\DTO;

class CustomerSyncDTO
{
    public function __construct(
        public readonly ?string $loyaltyId,
        public readonly ?string $cardNumber,
        public readonly ?string $name,
        public readonly ?string $surname,
        public readonly ?string $patronymic,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $birthday,
        public readonly ?string $gender,
        public readonly ?int $bonusBalance,
        public readonly ?string $externalId,
        public readonly ?string $shopCode,
    ) {}

    public static function fromMessage(array $m): self
    {
        $p = $m['payload'] ?? $m;
        return new self(
            loyaltyId: $p['loyalty_id'] ?? null,
            cardNumber: $p['card_number'] ?? null,
            name: $p['name'] ?? null,
            surname: $p['surname'] ?? null,
            patronymic: $p['patronymic'] ?? null,
            phone: $p['phone'] ?? null,
            email: $p['email'] ?? null,
            birthday: $p['birthday'] ?? null,
            gender: $p['gender'] ?? null,
            bonusBalance: array_key_exists('bonus_balance', $p) ? (int) $p['bonus_balance'] : null,
            externalId: isset($p['id']) ? (string) $p['id'] : null,
            shopCode: $p['shop_code'] ?? null,
        );
    }
}
