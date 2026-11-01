<?php

declare(strict_types=1);

namespace App\RabbitMQ\Debug\Jobs;

use App\RabbitMQ\Debug\RabbitMessageLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class LogRabbitMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}

    public function handle(): void
    {
        RabbitMessageLog::create($this->data);
    }
}
