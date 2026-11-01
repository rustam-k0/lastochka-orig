<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class List1CFilesCommand extends Command
{
    protected $signature = 'list:1c-files {--content : Показать содержимое XML файлов}';

    protected $description = 'Список файлов, полученных от 1С';

    public function handle(): int
    {
        $inputPath = config('protocolExchange1C.inputPath');

        if (!is_dir($inputPath)) {
            $this->error("Директория не существует: {$inputPath}");
            return 1;
        }

        $this->info("📁 Файлы от 1С: {$inputPath}");
        $this->newLine();

        $directories = File::directories($inputPath);

        if (empty($directories)) {
            $this->warn("Нет файлов от 1С");
            return 0;
        }

        // Сортируем по дате (новые первые)
        usort($directories, fn($a, $b) => filemtime($b) - filemtime($a));

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $dirTime = date('Y-m-d H:i:s', filemtime($dir));

            $this->info("📂 {$dirName} ({$dirTime})");

            $files = File::allFiles($dir);
            foreach ($files as $file) {
                $fileName = $file->getRelativePathname();
                $fileSize = $this->formatBytes($file->getSize());

                $this->line("   └── {$fileName} ({$fileSize})");

                // Показать содержимое XML если запрошено
                if ($this->option('content') && $file->getExtension() === 'xml') {
                    $this->showXmlContent($file->getPathname());
                }
            }

            $this->newLine();
        }

        return 0;
    }

    private function showXmlContent(string $path): void
    {
        $content = file_get_contents($path);

        // Декодируем windows-1251 если нужно
        if (preg_match('/encoding=["\']windows-1251["\']/i', $content)) {
            $content = iconv('windows-1251', 'utf-8//IGNORE', $content);
        }

        $this->newLine();
        $this->line("   ┌─── Содержимое XML ───");

        // Ищем интересные теги (статусы, заказы)
        if (preg_match_all('/<Документ>.*?<\/Документ>/s', $content, $matches)) {
            foreach ($matches[0] as $doc) {
                // Извлекаем ключевые поля
                preg_match('/<Номер>(.+?)<\/Номер>/', $doc, $number);
                preg_match('/<Ид>(.+?)<\/Ид>/', $doc, $id);

                // Ищем статус
                if (preg_match('/<ЗначениеРеквизита>.*?<Наименование>Статус.*?<\/Наименование>.*?<Значение>(.+?)<\/Значение>.*?<\/ЗначениеРеквизита>/s', $doc, $status)) {
                    $this->warn("   │ 📋 Заказ: " . ($number[1] ?? $id[1] ?? '?'));
                    $this->info("   │ 🔄 Статус: {$status[1]}");
                    $this->line("   │");
                }
            }
        }

        // Показать превью XML
        $preview = mb_substr($content, 0, 1000);
        foreach (explode("\n", $preview) as $line) {
            $this->line("   │ " . trim($line));
        }

        if (strlen($content) > 1000) {
            $this->line("   │ ...(обрезано)...");
        }

        $this->line("   └───────────────────────");
        $this->newLine();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
