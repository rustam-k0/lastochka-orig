<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Справочник принтеров печати. Ведётся в admin_back, читается из общей БД.
 * Курьерскому приложению отдаём только активные принтеры и только
 * авторизованному сотруднику: IP-адреса внутренней сети наружу не отдаём.
 */
class PrinterQueryTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private const QUERY = '{ printers { id title ip_address port } }';

  private function insertPrinter(string $title, string $ip, int $port, bool $isActive = true): int
  {
    return \DB::table('printers')->insertGetId([
      'title' => $title,
      'ip_address' => $ip,
      'port' => $port,
      'is_active' => $isActive,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function makeCollector(): User
  {
    // User в этом репо полностью guarded — сырой insert + findOrFail
    // (паттерн CourierAssignPaymentGateTest).
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Тестовый ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));

    return $user;
  }

  public function test_returns_only_active_printers(): void
  {
    $activeId = $this->insertPrinter('Этикетки, зал 1', '192.168.40.11', 9100);
    $inactiveId = $this->insertPrinter('Сломанный', '192.168.40.12', 9100, false);

    $this->actingAs($this->makeCollector(), 'api');

    $response = $this->graphQL(self::QUERY);

    $ids = array_column($response->json('data.printers'), 'id');

    $this->assertContains($activeId, $ids);
    $this->assertNotContains($inactiveId, $ids);
  }

  public function test_returns_printer_endpoint_fields(): void
  {
    $id = $this->insertPrinter('Кассовый принтер', '192.168.41.5', 9101);

    $this->actingAs($this->makeCollector(), 'api');

    $printers = collect($this->graphQL(self::QUERY)->json('data.printers'));
    $printer = $printers->firstWhere('id', $id);

    $this->assertNotNull($printer, 'Созданный принтер не попал в выдачу');
    $this->assertSame('Кассовый принтер', $printer['title']);
    $this->assertSame('192.168.41.5', $printer['ip_address']);
    $this->assertSame(9101, $printer['port']);
  }

  public function test_requires_authentication(): void
  {
    $this->insertPrinter('Этикетки, зал 2', '192.168.42.7', 9100);

    $response = $this->graphQL(self::QUERY);

    $response->assertGraphQLErrorMessage('Unauthenticated.');
    $this->assertNull($response->json('data.printers'));
  }

  public function test_soft_deleted_printers_are_hidden(): void
  {
    $deletedId = $this->insertPrinter('Списанный', '192.168.43.9', 9100);
    \DB::table('printers')->where('id', $deletedId)->update(['deleted_at' => now()]);

    $this->actingAs($this->makeCollector(), 'api');

    $ids = array_column($this->graphQL(self::QUERY)->json('data.printers'), 'id');

    $this->assertNotContains($deletedId, $ids);
  }
}
