<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/')
            . '/admin/restore?password_reset_token=' . $this->token;

        $expire = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

        return (new MailMessage())
            ->subject('Восстановление пароля — ' . config('app.name'))
            ->greeting('Здравствуйте!')
            ->line('Вы получили это письмо, потому что для вашей учётной записи в административной панели был запрошен сброс пароля.')
            ->action('Восстановить пароль', $url)
            ->line("Срок действия ссылки — {$expire} минут.")
            ->line('Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо — ваш пароль останется прежним.')
            ->salutation('С уважением, команда ' . config('app.name'));
    }
}
