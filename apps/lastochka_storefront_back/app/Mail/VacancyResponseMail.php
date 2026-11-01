<?php

declare(strict_types=1);

namespace App\Mail;

use App\DTOs\Vacancy\VacancyResponseData;
use App\Models\Vacancy;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо с откликом на вакансию. Единственное место, где данные кандидата
 * покидают систему: ни отклик, ни резюме в базе не сохраняются.
 */
final class VacancyResponseMail extends Mailable
{
    public function __construct(
        public readonly Vacancy $vacancy,
        public readonly VacancyResponseData $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Отклик на вакансию: '.$this->vacancy->title,
            replyTo: [$this->data->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.vacancy-response',
            with: [
                'vacancy' => $this->vacancy,
                'data' => $this->data,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->data->resumePath)
                ->as($this->data->resumeName)
                ->withMime($this->data->resumeMime),
        ];
    }
}
