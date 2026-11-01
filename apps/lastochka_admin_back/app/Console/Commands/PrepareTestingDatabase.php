<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Локальная тестовая БД курьерских модулей (admin_back, delivery_back, purchase_back):
 * пересоздаёт её с нуля миграциями и штатными сидами admin_back.
 *
 * Имя БД и хост прибиты здесь и в phpunit.xml всех трёх модулей, поэтому ни команда,
 * ни тесты не попадут в рабочую БД из .env. Только для локального запуска.
 */
class PrepareTestingDatabase extends Command
{
  public const DATABASE = 'courier_test';

  public const HOST = '127.0.0.1';

  protected $signature = 'testing:prepare-db';

  protected $description = 'Пересоздать локальную тестовую БД courier_test: миграции и сиды admin_back (только локально)';

  public function handle(): int
  {
    if ($this->laravel->environment('production')) {
      $this->error('Команда только для локального запуска, в production-окружении не выполняется.');
      return self::FAILURE;
    }

    $connection = config('database.default');
    $key = "database.connections.{$connection}";

    // Подключаемся без базы, чтобы создать её, если её ещё нет
    config(["{$key}.host" => self::HOST, "{$key}.database" => '']);
    DB::purge($connection);
    DB::connection($connection)->statement(sprintf(
      'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
      self::DATABASE,
      config("{$key}.charset"),
      config("{$key}.collation")
    ));

    config(["{$key}.database" => self::DATABASE]);
    DB::purge($connection);

    $this->info('Тестовая БД ' . self::DATABASE . ' на ' . self::HOST . ': migrate:fresh --seed');

    return $this->call('migrate:fresh', ['--force' => true, '--seed' => true]);
  }
}
