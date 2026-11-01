<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо-уведомление пользователю витрины. Один Mailable на все типы:
 * заголовок и текст приходят готовыми из джоб — теми же, что уходят в push.
 */
final class UserNotificationMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $orderUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-notification',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'orderUrl' => $this->orderUrl,
            ],
        );
    }
}
