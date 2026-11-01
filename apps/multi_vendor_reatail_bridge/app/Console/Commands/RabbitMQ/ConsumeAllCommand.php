<?php

namespace App\Console\Commands\RabbitMQ;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

/**
 * Команда для запуска всех RabbitMQ Consumer-ов одновременно.
 *
 * Использование:
 * php artisan rabbitmq:consume-all
 *
 * Запускает отдельные процессы для каждой сущности и мониторит их работу.
 */
class ConsumeAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Запускает consumer-ы для всех сущностей (product, category, customer, order, user, shop)';

    /**
     * Запущенные процессы consumer-ов.
     *
     * @var array<string, Process>
     */
    protected array $processes = [];

    /**
     * Список сущностей для обработки.
     *
     * @var array
     */
    protected array $entities = [
        'product',
        'category',
        'customer',
        'order',
        'user',
        'delivery_address',
        'shop',
    ];

    /**
     * Маппинг единственного числа во множественное для конфига.
     *
     * @var array
     */
    protected array $entityPluralMap = [
        'product' => 'product',
        'category' => 'category',
        'customer' => 'customer',
        'order' => 'order',
        'user' => 'user',
        'delivery_address' => 'delivery_address',
        'shop' => 'shop',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $eventsConfig = config('rabbitmq-events');

        if (empty($eventsConfig)) {
            $this->warn('Конфигурация rabbitmq-events не найдена.');
            return self::FAILURE;
        }

        $this->info('🚀 Запуск consumer-ов для всех сущностей...');
        $this->newLine();

        // Настройка обработчиков сигналов для graceful shutdown
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'stopAll']);
            pcntl_signal(SIGTERM, [$this, 'stopAll']);
            $this->comment('✓ Signal handlers установлены (SIGINT, SIGTERM)');
        } else {
            $this->warn('⚠ pcntl расширение недоступно, graceful shutdown может не работать');
        }

        // Запускаем consumer для каждой сущности
        foreach ($this->entities as $entity) {
            // Получаем форму множественного числа для проверки конфига
            $entityPlural = $this->entityPluralMap[$entity] ?? $entity;

            if (isset($eventsConfig['entities'][$entityPlural])) {
                $this->startConsumer($entity);
            } else {
                $this->warn("⚠ Сущность {$entity} ({$entityPlural}) не найдена в конфиге rabbitmq-events, пропускаем");
            }
        }

        $this->newLine();
        $this->info("✅ Все consumer-ы запущены. Нажмите Ctrl+C для остановки.");
        $this->comment("Мониторинг процессов...");
        $this->newLine();

        // Запускаем мониторинг процессов
        $this->monitorProcesses();

        return self::SUCCESS;
    }

    /**
     * Запускает consumer для указанной сущности.
     *
     * @param string $entity
     * @return void
     */
    protected function startConsumer(string $entity): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');

        $command = [$phpBinary, $artisan, 'rabbitmq:consume', $entity];

        $process = new Process($command);
        $process->setTimeout(null); // Без таймаута
        $process->setWorkingDirectory(base_path());

        try {
            $process->start();
            $this->processes[$entity] = $process;
            $this->info("  ✓ Consumer запущен: <fg=cyan>{$entity}</>");
        } catch (\Exception $e) {
            $this->error("  ✗ Ошибка запуска consumer для {$entity}: {$e->getMessage()}");
            Log::error("Ошибка запуска consumer для {$entity}", [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Мониторит запущенные процессы и перезапускает упавшие.
     *
     * @return void
     */
    protected function monitorProcesses(): void
    {
        $restartDelay = 3; // секунды до перезапуска

        while (true) {
            foreach ($this->processes as $entity => $process) {
                /** @var Process $process */

                // Форвардим stdout
                $incrementalOutput = $process->getIncrementalOutput();
                if ($incrementalOutput) {
                    foreach (explode("\n", $incrementalOutput) as $line) {
                        if (trim($line)) {
                            $this->line("<fg=cyan>[{$entity}]</> {$line}");
                        }
                    }
                }

                // Форвардим stderr
                $incrementalErrorOutput = $process->getIncrementalErrorOutput();
                if ($incrementalErrorOutput) {
                    foreach (explode("\n", $incrementalErrorOutput) as $line) {
                        if (trim($line)) {
                            $this->error("[{$entity}] {$line}");
                        }
                    }
                }

                // Проверяем, не упал ли процесс
                if (!$process->isRunning()) {
                    $exitCode = $process->getExitCode();

                    if ($exitCode === 0) {
                        $this->warn("⚠️  Consumer для {$entity} завершился нормально (exit code 0)");
                    } else {
                        $this->error("❌ Consumer для {$entity} упал (exit code {$exitCode})");
                        Log::error("Consumer для {$entity} упал", [
                            'exit_code' => $exitCode,
                            'output' => $process->getOutput(),
                            'error_output' => $process->getErrorOutput(),
                        ]);
                    }

                    $this->warn("  ⟳ Перезапуск через {$restartDelay} секунд...");

                    // Удаляем из списка процессов
                    unset($this->processes[$entity]);

                    // Ждем перед перезапуском
                    sleep($restartDelay);

                    // Перезапускаем
                    $this->startConsumer($entity);
                }
            }

            // Предотвращаем высокую нагрузку на CPU
            usleep(200000); // 0.2 секунды
        }
    }

    /**
     * Останавливает все запущенные consumer-ы.
     * Вызывается при получении SIGINT/SIGTERM.
     *
     * @return void
     */
    public function stopAll(): void
    {
        $this->newLine();
        $this->info('🛑 Остановка всех consumer-ов...');

        foreach ($this->processes as $entity => $process) {
            /** @var Process $process */
            if ($process->isRunning()) {
                $process->stop(3, SIGTERM); // 3 секунды на graceful shutdown
                $this->info("  ✓ Остановлен consumer для: {$entity}");
            }
        }

        $this->newLine();
        $this->info('✅ Все consumer-ы остановлены');

        exit(0);
    }
}
