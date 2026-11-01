<?php

declare(strict_types=1);

namespace App\DTOs\Vacancy;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Данные отклика на вакансию. Живут только на время формирования письма —
 * в базу не попадают.
 */
final readonly class VacancyResponseData
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $email,
        public ?string $comment,
        public string $resumePath,
        public string $resumeName,
        public string $resumeMime,
        public string $source,
        public Carbon $sentAt,
    ) {}

    public static function fromRequest(UploadedFile $resume, array $payload): self
    {
        return new self(
            name: trim((string) $payload['name']),
            phone: trim((string) $payload['phone']),
            email: trim((string) $payload['email']),
            comment: isset($payload['comment']) && trim((string) $payload['comment']) !== ''
                ? trim((string) $payload['comment'])
                : null,
            resumePath: $resume->getRealPath(),
            resumeName: $resume->getClientOriginalName() ?: 'resume',
            resumeMime: $resume->getClientMimeType() ?: 'application/octet-stream',
            source: (string) ($payload['source'] ?? 'website'),
            sentAt: Carbon::now(),
        );
    }

    /**
     * Человекочитаемый источник отправки для письма.
     */
    public function sourceLabel(): string
    {
        return $this->source === 'mobile' ? 'Мобильное приложение' : 'Сайт';
    }
}
