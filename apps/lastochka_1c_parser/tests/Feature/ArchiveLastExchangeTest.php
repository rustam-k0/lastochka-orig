<?php

use App\Jobs\Concerns\ArchivesProcessedFile;
use Illuminate\Support\Facades\File;

/**
 * Объект с трейтом ArchivesProcessedFile и публичной обёрткой над cleanupFile,
 * чтобы дёргать protected-логику архивации напрямую.
 */
function makeArchiverJob(string $filePath): object
{
    return new class($filePath)
    {
        use ArchivesProcessedFile;

        public function __construct(public string $filePath) {}

        public function run(): void
        {
            $this->cleanupFile();
        }
    };
}

beforeEach(function () {
    $this->archiveDir = storage_path('1cExchange/_last');
    $this->sessionDir = storage_path('1cExchange/djami_store/2026-06-15_15-04-47_testsess');

    File::deleteDirectory($this->archiveDir);
    File::deleteDirectory($this->sessionDir);
    File::ensureDirectoryExists($this->sessionDir);
});

afterEach(function () {
    File::deleteDirectory($this->archiveDir);
    File::deleteDirectory(storage_path('1cExchange/djami_store/2026-06-15_15-04-47_testsess'));
});

it('переносит обработанный файл в архив вместо удаления', function () {
    config(['exchange1c.keep_last_exchange' => true]);

    $src = $this->sessionDir . '/import___abc.xml';
    file_put_contents($src, '<КоммерческаяИнформация/>');

    makeArchiverJob($src)->run();

    expect(file_exists($src))->toBeFalse();

    $archived = glob($this->archiveDir . '/*') ?: [];
    expect($archived)->toHaveCount(1);
    expect(basename($archived[0]))->toContain('import___abc.xml')
        ->and(basename($archived[0]))->toContain('2026-06-15_15-04-47_testsess');
});

it('удаляет файл когда архивация выключена', function () {
    config(['exchange1c.keep_last_exchange' => false]);

    $src = $this->sessionDir . '/import___xyz.xml';
    file_put_contents($src, '<xml/>');

    makeArchiverJob($src)->run();

    expect(file_exists($src))->toBeFalse();
    expect(glob($this->archiveDir . '/*') ?: [])->toBeEmpty();
});

it('вычищает предыдущую выгрузку, но сохраняет файлы текущей пачки', function () {
    config([
        'exchange1c.keep_last_exchange' => true,
        'exchange1c.keep_last_window_minutes' => 10,
    ]);

    File::ensureDirectoryExists($this->archiveDir);

    // Файл предыдущей выгрузки: mtime 30 минут назад — вне окна.
    $old = $this->archiveDir . '/oldsession__import___old.xml';
    file_put_contents($old, '<old/>');
    touch($old, time() - 30 * 60);

    // Файл текущей пачки: mtime 2 минуты назад — в окне.
    $recent = $this->archiveDir . '/cursession__import___recent.xml';
    file_put_contents($recent, '<recent/>');
    touch($recent, time() - 2 * 60);

    // Приходит новый файл текущей выгрузки.
    $src = $this->sessionDir . '/import___new.xml';
    file_put_contents($src, '<new/>');

    makeArchiverJob($src)->run();

    expect(file_exists($old))->toBeFalse();   // предыдущая выгрузка вычищена
    expect(file_exists($recent))->toBeTrue(); // текущая пачка осталась

    $archived = array_map('basename', glob($this->archiveDir . '/*') ?: []);
    expect($archived)->toContain('cursession__import___recent.xml')
        ->and($archived)->toContain('2026-06-15_15-04-47_testsess__import___new.xml');
});
