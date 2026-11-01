<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Блокировка завершения сборки не должна жить в файловом кэше (прод 11.09.2026).
 *
 * storage/framework/cache/data наполняют два юзера: php-fpm (www-data) и
 * CI-раннер, прогревающий `artisan lighthouse:cache`. Подкаталог, созданный
 * раннером, получает его первичную группу и для www-data закрыт, а путь к файлу
 * блокировки — это sha1 ключа, то есть «отравленным» оказывается примерно один
 * ключ из 256. Cache::lock('assembly-finish:255') попал ровно в такой каталог и
 * упал с «Unable to create lockable file»: сборка 255 заказа 223-1 осталась
 * незакрытой, сборщик не мог завершить собранный заказ.
 *
 * Стор блокировок вынесен в config('cache.lock_store') = database: общая БД есть
 * на всех форках, права ФС на неё не влияют, и лок переживает несколько
 * php-fpm хостов.
 */
class AssemblyFinishLockStoreTest extends TestCase
{
  use DatabaseTransactions;

  private function lockKey(int $assemblyId): string
  {
    return config('cache.prefix') . 'assembly-finish:' . $assemblyId;
  }

  /** @test */
  public function lock_lives_in_database_not_in_file_cache(): void
  {
    $assemblyId = random_int(900000, 999999);

    $lock = AssemblyFinish::lockFor($assemblyId);

    $this->assertInstanceOf(
      DatabaseLock::class,
      $lock,
      'блокировка завершения сборки обязана быть в БД: файловая падает на правах каталога кэша'
    );

    $this->assertTrue($lock->get(), 'свободная блокировка должна браться');

    $this->assertDatabaseHas('cache_locks', ['key' => $this->lockKey($assemblyId)]);

    $lock->release();

    $this->assertDatabaseMissing('cache_locks', ['key' => $this->lockKey($assemblyId)]);
  }

  /** @test */
  public function second_holder_cannot_take_the_same_lock(): void
  {
    $assemblyId = random_int(900000, 999999);

    $first = AssemblyFinish::lockFor($assemblyId);
    $this->assertTrue($first->get());

    $second = AssemblyFinish::lockFor($assemblyId);
    $this->assertFalse($second->get(), 'второй процесс не должен войти в завершение той же сборки');

    $first->release();

    $this->assertTrue($second->get(), 'после освобождения блокировка снова доступна');
    $second->release();
  }

  /** @test */
  public function different_assemblies_do_not_block_each_other(): void
  {
    $one = AssemblyFinish::lockFor(random_int(900000, 949999));
    $two = AssemblyFinish::lockFor(random_int(950000, 999999));

    $this->assertTrue($one->get());
    $this->assertTrue($two->get(), 'блокировка по сборке не должна сериализовать все завершения подряд');

    $one->release();
    $two->release();
  }

  /**
   * Путь мутации целиком: пока блокировка занята, повтор ждёт LOCK_WAIT_SECONDS
   * и отвечает 409, а не падает 500 и не выполняет второе списание.
   *
   * @test
   */
  public function finish_under_held_lock_answers_409(): void
  {
    $assemblyId = random_int(900000, 999999);

    $held = AssemblyFinish::lockFor($assemblyId);
    $this->assertTrue($held->get());

    $startedAt = microtime(true);
    $result = (new AssemblyFinish())(null, ['id' => $assemblyId, 'packages' => []]);
    $waited = microtime(true) - $startedAt;

    $held->release();

    $this->assertSame(409, $result['code']);
    $this->assertSame('Завершение сборки уже выполняется, повторите через несколько секунд', $result['message']);
    $this->assertGreaterThanOrEqual(
      AssemblyFinish::LOCK_WAIT_SECONDS - 1,
      $waited,
      'повтор обязан дождаться освобождения блокировки, а не отвалиться сразу'
    );
  }

  /** @test */
  public function expired_lock_is_taken_over(): void
  {
    $assemblyId = random_int(900000, 999999);

    // Процесс упал, не сняв блокировку: ряд остался, но expiration в прошлом.
    DB::table('cache_locks')->insert([
      'key' => $this->lockKey($assemblyId),
      'owner' => 'dead-process',
      'expiration' => time() - 1,
    ]);

    $lock = AssemblyFinish::lockFor($assemblyId);

    $this->assertTrue($lock->get(), 'протухшая блокировка не должна навсегда запирать сборку');

    $lock->release();
  }
}
