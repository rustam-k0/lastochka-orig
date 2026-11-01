<?php

namespace Tests\Feature;

use App\Console\Commands\PrepareTestingDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrepareTestingDatabaseCommandTest extends TestCase
{
  /** @test */
  public function it_refuses_to_run_in_production_environment(): void
  {
    $this->app['env'] = 'production';
    $databaseBefore = DB::connection()->getDatabaseName();

    $this->artisan('testing:prepare-db')
      ->expectsOutputToContain('только для локального запуска')
      ->assertExitCode(1);

    // До подключения к серверу БД и migrate:fresh команда не дошла
    $this->assertSame($databaseBefore, DB::connection()->getDatabaseName());
    $this->assertSame(PrepareTestingDatabase::DATABASE, $databaseBefore);
  }
}
