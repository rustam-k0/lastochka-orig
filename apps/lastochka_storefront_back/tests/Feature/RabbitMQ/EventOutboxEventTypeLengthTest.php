<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use Tests\TestCase;

/**
 * Схемный гард: каждый публикуемый витриной event_type обязан помещаться
 * в колонку event_outbox.event_type.
 *
 * Проверяется по объявленной в миграциях длине, а не по схеме тестовой БД:
 * тесты гоняются на sqlite, где varchar длину не ограничивает и колонка
 * отдаётся без размера. Из-за этого обычные тесты 402-пути были зелёными,
 * пока на MySQL в strict-режиме публикация order_surcharge_required
 * (24 символа при varchar(20)) валилась с «1406 Data too long» и роняла
 * весь запрос: списание оплаты отдавало 500 вместо 402 «нужна доплата».
 */
final class EventOutboxEventTypeLengthTest extends TestCase
{
    public function test_every_published_event_type_fits_the_outbox_column(): void
    {
        $limit = $this->declaredEventTypeLength();

        $this->assertNotNull($limit, 'Не найдено объявление длины event_outbox.event_type в миграциях');

        $tooLong = array_values(array_filter(
            $this->publishedEventTypes(),
            static fn (string $type): bool => mb_strlen($type) > $limit
        ));

        $this->assertSame([], $tooLong, sprintf(
            'event_outbox.event_type объявлена как varchar(%d) и не вмещает: %s. Расширьте колонку миграцией.',
            $limit,
            implode(', ', $tooLong)
        ));
    }

    /**
     * Длина колонки по последней применяемой миграции: сначала миграции
     * пакета comrades/laravel-rabbitmq-events (он создаёт таблицу), затем
     * миграции приложения. Порядок — как у Laravel, по имени файла.
     */
    private function declaredEventTypeLength(): ?int
    {
        $files = [];

        foreach ($this->migrationDirectories() as $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $path) {
                $files[basename($path)] = $path;
            }
        }

        ksort($files);

        $length = null;

        foreach ($files as $path) {
            $matched = preg_match_all(
                "/string\(\s*'event_type'\s*,\s*(\d+)\s*\)/",
                (string) file_get_contents($path),
                $matches
            );

            if ($matched > 0) {
                $length = (int) end($matches[1]);
            }
        }

        return $length;
    }

    /**
     * @return list<string>
     */
    private function migrationDirectories(): array
    {
        return array_values(array_filter([
            base_path('vendor/comrades/laravel-rabbitmq-events/database/migrations'),
            database_path('migrations'),
        ], 'is_dir'));
    }

    /**
     * Литералы event_type из кода приложения: `eventType: 'order_paid'`
     * у LoggingEventPublisher и `'event_type' => '...'` у прямых записей
     * в outbox. Скан по файлам, а не фиксированный список, — чтобы новое
     * длинное событие роняло тест сразу, а не на проде.
     *
     * @return list<string>
     */
    private function publishedEventTypes(): array
    {
        $types = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $matched = preg_match_all(
                "/(?:eventType:\s*|'event_type'\s*=>\s*)'([a-z0-9_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            if ($matched > 0) {
                $types = array_merge($types, $matches[1]);
            }
        }

        $types = array_values(array_unique($types));

        $this->assertNotEmpty($types, 'В app/ не найдено ни одного event_type — скан сломался');

        return $types;
    }
}
