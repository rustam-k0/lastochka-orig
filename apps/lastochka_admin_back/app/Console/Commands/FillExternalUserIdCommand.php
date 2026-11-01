<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Command;

class FillExternalUserIdCommand extends Command
{
  protected $signature = 'fill:external-user-id';

  protected $description = 'Генерирует external_user_id тем пользователям, у которых его нет';

  public function handle(): void
  {
    User::whereNull('external_user_id')->chunk(500, function ($users) {
      foreach ($users as $user) {
        $user->update([
          'external_user_id' => sha1(\Str::random(300))
        ]);
      }
    });
  }
}
