<?php

namespace App\Services\Exchange1C;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Сервис фильтрации товаров по белому списку из CSV файла
 * 
 * Фильтрация производится по штрихкодам (колонка 5 в CSV)
 */
class ProductWhitelist
{
    /**
     * Штрихкоды из whitelist (для быстрого поиска O(1))
     */
    protected array $barcodes = [];

    /**
     * Загружен ли whitelist
     */
    protected bool $loaded = false;

    /**
     * Включена ли фильтрация
     */
    protected bool $enabled;

    /**
     * Путь к CSV файлу
     */
    protected string $csvPath;

    public function __construct(?string $csvPath = null)
    {
        if ($csvPath === null) {
            // Whitelist disabled for this shop
            $this->enabled = false;
            $this->csvPath = '';
        } else {
            $this->enabled = true;
            $this->csvPath = str_starts_with($csvPath, '/') ? $csvPath : base_path($csvPath);
        }
    }

    /**
     * Create ProductWhitelist from current shop config.
     * If whitelist_path not set in credentials — whitelist disabled.
     * For artisan commands (no shop context) — fallback to global config.
     */
    public static function fromShopConfig(): self
    {
        // Try from current shop config (via container)
        try {
            $config = app('current_shop_config');
            if ($config) {
                $credentials = $config->credentials;
                $whitelistPath = $credentials['whitelist_path'] ?? null;
                return new self($whitelistPath);
            }
        } catch (\Throwable $e) {
            // No shop context — fallback to global config
        }

        // Fallback for artisan commands / global config
        $enabled = config('exchange1c.whitelist_enabled', true);
        if (!$enabled) {
            return new self(null);
        }

        $path = config('exchange1c.whitelist_path', base_path('docs/list2.csv'));
        // If path is already absolute, use as-is; otherwise it was already resolved by config
        return new self($path);
    }

    /**
     * Загрузка whitelist из CSV
     *
     * @throws RuntimeException
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if (!$this->enabled) {
            $this->loaded = true;
            Log::channel('1c-exchange')->info('Whitelist отключён в конфигурации');
            return;
        }

        if (!file_exists($this->csvPath)) {
            throw new RuntimeException("Файл whitelist не найден: {$this->csvPath}");
        }

        $handle = fopen($this->csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл whitelist: {$this->csvPath}");
        }

        $lineNumber = 0;
        $validCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            // Пропускаем заголовок
            if ($lineNumber === 1) {
                continue;
            }

            // Проверяем формат строки (минимум 6 колонок для нового формата)
            if (count($row) < 6) {
                Log::channel('1c-exchange')->warning("Whitelist: некорректная строка {$lineNumber} (< 6 колонок), пропуск");
                continue;
            }

            // Колонка 5 (индекс 5) - Штрихкод
            $barcode = $this->normalizeBarcode($row[5] ?? '');

            if (empty($barcode)) {
                continue;
            }

            $this->barcodes[$barcode] = true;
            $validCount++;
        }

        fclose($handle);
        $this->loaded = true;

        Log::channel('1c-exchange')->info('Whitelist загружен (по штрихкодам)', [
            'path' => $this->csvPath,
            'unique_barcodes' => count($this->barcodes),
        ]);

        if (count($this->barcodes) === 0) {
            Log::channel('1c-exchange')->warning('Whitelist пуст — импорт вернёт 0 товаров');
        }
    }

    /**
     * Проверка, разрешён ли товар к импорту по штрихкодам
     * 
     * @param array $barcodes Массив штрихкодов товара из 1С
     */
    public function isAllowed(array $barcodes): bool
    {
        // Если whitelist отключён — разрешаем всё
        if (!$this->enabled) {
            return true;
        }

        // Lazy load при первом использовании
        if (!$this->loaded) {
            $this->load();
        }

        // Пустой whitelist — ничего не разрешаем
        if (empty($this->barcodes)) {
            return false;
        }

        // Проверяем каждый штрихкод товара — достаточно одного совпадения
        foreach ($barcodes as $barcode) {
            $normalized = $this->normalizeBarcode($barcode);
            if (isset($this->barcodes[$normalized])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Количество позиций в whitelist
     */
    public function getCount(): int
    {
        if (!$this->loaded) {
            $this->load();
        }

        return count($this->barcodes);
    }

    /**
     * Включена ли фильтрация
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Нормализация штрихкода
     */
    protected function normalizeBarcode(string $barcode): string
    {
        // Убираем пробелы
        $barcode = trim($barcode);
        
        // Убираем ведущие нули (опционально, в зависимости от формата)
        // $barcode = ltrim($barcode, '0');
        
        return $barcode;
    }

    /**
     * Получить все штрихкоды из whitelist (для отладки)
     */
    public function getBarcodes(): array
    {
        if (!$this->loaded) {
            $this->load();
        }

        return array_keys($this->barcodes);
    }
}
