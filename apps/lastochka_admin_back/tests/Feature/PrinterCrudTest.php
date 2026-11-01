<?php

namespace Tests\Feature;

use App\Models\Printer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PrinterCrudTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    private function createPrinter(User $user, array $variables)
    {
        return $this->actingAs($user)->graphQL('
            mutation ($title: String!, $ip_address: String!, $port: Int!, $is_active: Boolean) {
                PrinterCreate(title: $title, ip_address: $ip_address, port: $port, is_active: $is_active) {
                    code
                    message
                }
            }
        ', $variables);
    }

    public function test_can_create_update_and_delete_printer(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->createPrinter($user, [
            'title' => 'Принтер этикеток, зал 1',
            'ip_address' => '192.168.10.21',
            'port' => 9100,
            'is_active' => true,
        ]);

        $create->assertJson(['data' => ['PrinterCreate' => ['code' => 200]]]);

        $printer = Printer::where('ip_address', '192.168.10.21')->firstOrFail();
        $this->assertSame('Принтер этикеток, зал 1', $printer->title);
        $this->assertSame(9100, $printer->port);
        $this->assertTrue($printer->is_active);

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $title: String, $port: Int) {
                PrinterUpdate(id: $id, title: $title, port: $port) {
                    code
                    message
                }
            }
        ', [
            'id' => $printer->id,
            'title' => 'Принтер этикеток, зал 2',
            'port' => 9101,
        ]);

        $update->assertJson(['data' => ['PrinterUpdate' => ['code' => 200]]]);
        $this->assertSame('Принтер этикеток, зал 2', $printer->fresh()->title);
        $this->assertSame(9101, $printer->fresh()->port);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                PrinterDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $printer->id]);

        $delete->assertJson(['data' => ['PrinterDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('printers', ['id' => $printer->id]);
    }

    public function test_create_rejects_invalid_ip(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPrinter($user, [
            'title' => 'Битый адрес',
            'ip_address' => '192.168.10.999',
            'port' => 9100,
        ]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [PrinterCreate].');
    }

    public function test_create_rejects_port_out_of_range(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->createPrinter($user, [
            'title' => 'Порт вне диапазона',
            'ip_address' => '192.168.10.22',
            'port' => 70000,
        ]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [PrinterCreate].');
    }

    public function test_create_rejects_duplicate_ip_and_port(): void
    {
        $user = $this->makeAdminUser();

        $this->createPrinter($user, [
            'title' => 'Первый',
            'ip_address' => '192.168.10.23',
            'port' => 9100,
        ])->assertJson(['data' => ['PrinterCreate' => ['code' => 200]]]);

        $duplicate = $this->createPrinter($user, [
            'title' => 'Дубль',
            'ip_address' => '192.168.10.23',
            'port' => 9100,
        ]);

        $duplicate->assertGraphQLErrorMessage('Validation failed for the field [PrinterCreate].');

        // Тот же IP с другим портом — легальная пара, это другой принтер.
        $this->createPrinter($user, [
            'title' => 'Тот же IP, другой порт',
            'ip_address' => '192.168.10.23',
            'port' => 9101,
        ])->assertJson(['data' => ['PrinterCreate' => ['code' => 200]]]);
    }

    public function test_can_recreate_printer_with_same_endpoint_after_delete(): void
    {
        $user = $this->makeAdminUser();

        $this->createPrinter($user, [
            'title' => 'Старый принтер',
            'ip_address' => '192.168.10.24',
            'port' => 9100,
        ]);

        $printer = Printer::where('ip_address', '192.168.10.24')->firstOrFail();

        $this->actingAs($user)->graphQL('
            mutation ($id: Int!) { PrinterDelete(id: $id) { code } }
        ', ['id' => $printer->id]);

        // Удалённая запись остаётся в таблице (softDeletes) и не должна
        // блокировать повторное добавление того же принтера.
        $this->createPrinter($user, [
            'title' => 'Новый принтер на том же адресе',
            'ip_address' => '192.168.10.24',
            'port' => 9100,
        ])->assertJson(['data' => ['PrinterCreate' => ['code' => 200]]]);
    }

    public function test_update_rejects_port_change_that_collides_with_other_printer(): void
    {
        $user = $this->makeAdminUser();

        $first = Printer::create(['title' => 'Первый', 'ip_address' => '192.168.10.25', 'port' => 9100]);
        $second = Printer::create(['title' => 'Второй', 'ip_address' => '192.168.10.25', 'port' => 9101]);

        // Меняем только порт — коллизия с $first по паре IP+порт.
        $response = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $port: Int) {
                PrinterUpdate(id: $id, port: $port) { code }
            }
        ', ['id' => $second->id, 'port' => 9100]);

        $response->assertGraphQLErrorMessage('Validation failed for the field [PrinterUpdate].');
        $this->assertSame(9101, $second->fresh()->port);

        // Смена порта на свободный проходит.
        $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $port: Int) {
                PrinterUpdate(id: $id, port: $port) { code }
            }
        ', ['id' => $second->id, 'port' => 9102])
            ->assertJson(['data' => ['PrinterUpdate' => ['code' => 200]]]);

        $this->assertSame(9100, $first->fresh()->port);
    }

    public function test_all_printers_returns_inactive_too(): void
    {
        $user = $this->makeAdminUser();

        Printer::create(['title' => 'Активный', 'ip_address' => '192.168.20.1', 'port' => 9100, 'is_active' => true]);
        Printer::create(['title' => 'Выключенный', 'ip_address' => '192.168.20.2', 'port' => 9100, 'is_active' => false]);

        $all = $this->actingAs($user)->graphQL('query { allPrinters { id title ip_address port is_active } }');
        $this->assertCount(2, $all->json('data.allPrinters'));

        $active = $this->actingAs($user)->graphQL('query { printers { id title } }');
        $this->assertCount(1, $active->json('data.printers'));
    }

    public function test_printer_query_returns_item_by_id(): void
    {
        $user = $this->makeAdminUser();

        $printer = Printer::create(['title' => 'Кассовый', 'ip_address' => '192.168.30.1', 'port' => 9100]);

        $response = $this->actingAs($user)->graphQL('
            query ($id: Int!) { printer(id: $id) { id title ip_address port is_active } }
        ', ['id' => $printer->id]);

        $response->assertJson(['data' => ['printer' => [
            'id' => $printer->id,
            'title' => 'Кассовый',
            'ip_address' => '192.168.30.1',
            'port' => 9100,
            'is_active' => true,
        ]]]);
    }
}
