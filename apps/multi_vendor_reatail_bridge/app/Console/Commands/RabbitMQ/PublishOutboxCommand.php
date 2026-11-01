<?php

namespace App\Console\Commands\RabbitMQ;

use App\Services\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class PublishOutboxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rb:publish-outbox {entity?} {--batch=100} {--loop} {--sleep=1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Публикует события из event_outbox в RabbitMQ. Если сущность не указана, обрабатывает все из конфига.';

    public function __construct(protected OutboxPublisher $publisher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $entityArg = $this->argument('entity');

        // Determine entities to process
        if ($entityArg) {
            $entities = [$entityArg];
        } else {
            $entities = array_keys(config('rabbitmq-events.entities', []));
        }

        if (empty($entities)) {
            $this->error('Не найдены сущности для обработки. Проверьте config/rabbitmq-events.php или передайте аргумент entity.');
            return self::FAILURE;
        }

        $loop = (bool) $this->option('loop');
        $sleep = (int) $this->option('sleep');

        if (!$loop) {
            $totalPublished = 0;
            foreach ($entities as $entity) {
                $this->info("Обработка сущности: {$entity}");
                $published = $this->publisher->publishBatch($entity, $batchSize);
                if ($published > 0) {
                    $this->info("[{$entity}] Опубликовано сообщений: {$published}");
                }
                $totalPublished += $published;
            }
            $this->info("Всего опубликовано: {$totalPublished}");
            return self::SUCCESS;
        }

        $this->info("Запуск бесконечного публикатора outbox для сущностей: " . implode(', ', $entities));
        
        while (true) {
            $totalPublishedInLoop = 0;
            
            foreach ($entities as $entity) {
                $published = $this->publisher->publishBatch($entity, $batchSize);
                
                if ($published > 0) {
                    $this->info("[{$entity}] Опубликовано сообщений: {$published}");
                    $totalPublishedInLoop += $published;
                }
            }

            if ($totalPublishedInLoop === 0) {
                if ($sleep > 0) {
                    sleep($sleep);
                }
            }
        }
    }
}
