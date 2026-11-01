<?php

namespace App\RabbitMQ\DTO;

class CustomerDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'Покупатель',
            phone: $data['phone'] ?? null,
            email: $data['email'] ?? null,
        );
    }
}
