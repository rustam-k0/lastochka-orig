<?php

use Illuminate\Support\Facades\File;

/**
 * Очистка пустых каталогов сессий обмена.
 *
 * Прежняя реализация звала на КАЖДЫЙ каталог рекурсивный обход Finder'ом
 * (safeAllFiles + safeDirectories). На проде Ласточки в 1cExchange/djami_store
 * накопилось 683 тыс. пустых каталогов сессий, и очистка до них не доходила.
 */
beforeEach(function () {
    $this->root = storage_path('1cExchange/cleanup_dirs_test');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('удаляет пустые каталоги сессий, когда рядом есть файлы', function () {
    // Файл обязателен: внутренний цикл по файлам затирал переменную $path
    // внешнего цикла, и cleanupEmptyDirs() получал путь последнего ФАЙЛА
    // вместо каталога. Без файлов регрессия не воспроизводится.
    $withFile = $this->root . '/2026-08-07_10-52-00_withfile';
    File::ensureDirectoryExists($withFile);
    file_put_contents($withFile . '/import___fresh.xml', '<КоммерческаяИнформация/>');

    $dirs = [];
    foreach (range(1, 5) as $i) {
        $dirs[] = $this->root . "/2026-08-07_10-52-0{$i}_hash{$i}";
        File::ensureDirectoryExists(end($dirs));
    }

    $this->artisan('1c:cleanup', ['--days' => 7])->assertExitCode(0);

    foreach ($dirs as $dir) {
        expect(is_dir($dir))->toBeFalse();
    }
    expect(is_dir($withFile))->toBeTrue();
});

it('не трогает каталог с файлом', function () {
    $withFile = $this->root . '/2026-08-07_10-52-10_withfile';
    File::ensureDirectoryExists($withFile);
    file_put_contents($withFile . '/import___fresh.xml', '<КоммерческаяИнформация/>');

    $empty = $this->root . '/2026-08-07_10-52-11_empty';
    File::ensureDirectoryExists($empty);

    $this->artisan('1c:cleanup', ['--days' => 7])->assertExitCode(0);

    expect(is_dir($withFile))->toBeTrue();
    expect(is_dir($empty))->toBeFalse();
});

it('вычищает вложенные пустые каталоги снизу вверх', function () {
    $deep = $this->root . '/2026-08-07_10-52-20_outer/inner/deepest';
    File::ensureDirectoryExists($deep);

    $this->artisan('1c:cleanup', ['--days' => 7])->assertExitCode(0);

    expect(is_dir($deep))->toBeFalse();
    expect(is_dir($this->root . '/2026-08-07_10-52-20_outer'))->toBeFalse();
});
