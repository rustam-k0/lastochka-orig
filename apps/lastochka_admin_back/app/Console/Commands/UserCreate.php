<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class UserCreate extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'make:user {email} {role}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Command description';

  /**
   * Execute the console command.
   *
   * @return int
   */
  public function handle()
  {
    $email = $this->argument('email');

    $role_name = $this->argument('role');

    if (!Role::whereName($role_name)->exists()) {
      $this->error("No role with name '$role_name'");
      return Command::FAILURE;
    }

    if (User::whereEmail($email)->exists()) {
      $this->error("User with email '$email' already exists");
      return Command::FAILURE;
    }

    $password = \Str::random();

    \DB::transaction(function () use ($email, $role_name, $password) {
      $user = User::create([
        "email" => $email,
        "password" => bcrypt($password)
      ]);
      $user->assignRole($role_name);

    });

    $this->info($password);

    return Command::SUCCESS;
  }
}
