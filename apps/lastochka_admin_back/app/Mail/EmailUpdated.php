<?php

namespace App\Mail;

use App\Models\EmailConfirm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;
use Lang;
use Str;

class EmailUpdated extends Mailable
{
  use Queueable, SerializesModels;

  private int $user_id;
  private string $email;

  /**
   * Create a new message instance.
   *
   * @return void
   */
  public function __construct(int $user_id,string $email)
  {
    $this->user_id = $user_id;
    $this->email = $email;
  }

  /**
   * Build the message.
   *
   */
  public function build(): EmailUpdated
  {
    $token = Str::random(40);

    //Делаем старые письма с подтверждением не актуальными
    EmailConfirm::whereUserId($this->user_id)->delete();

    EmailConfirm::create([
      'user_id' => $this->user_id,
      'email' => $this->email,
      'token' => $token,
    ]);

    $url = route('email.confirm', ['token' => $token]);

    $message = (new MailMessage)
      ->line(Lang::get('Please click the button below to verify your email address.'))
      ->action(Lang::get('Verify Email Address'), $url);

    return $this->markdown('vendor.notifications.email', $message->data());
  }
}
