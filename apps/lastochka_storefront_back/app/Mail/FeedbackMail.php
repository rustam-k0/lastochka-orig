<?php

declare(strict_types=1);

namespace App\Mail;

use App\DTOs\Feedback\FeedbackData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо с обращением из формы обратной связи (MENDEV-6). Единственное место,
 * куда попадает обращение: в базе и админке оно не сохраняется. Отправка —
 * через очередь (ShouldQueue), пользователь не ждёт SMTP.
 */
final class FeedbackMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly FeedbackData $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Обращение с формы обратной связи',
            replyTo: $this->data->email !== null ? [$this->data->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback',
            with: ['data' => $this->data],
        );
    }
}
