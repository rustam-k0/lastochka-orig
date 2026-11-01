<?php

declare(strict_types=1);

namespace App\DTOs\Feedback;

use Illuminate\Support\Carbon;

final class FeedbackData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly string $message,
        public readonly Carbon $sentAt,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            name: (string) $validated['name'],
            phone: isset($validated['phone']) ? (string) $validated['phone'] : null,
            email: isset($validated['email']) ? (string) $validated['email'] : null,
            message: (string) $validated['message'],
            sentAt: now(),
        );
    }
}
