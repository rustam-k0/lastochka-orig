<?php

namespace App\Services\RabbitMQ;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitMQConnection
{
    public function __construct(protected AMQPStreamConnection $connection)
    {
    }

    public function channel(): AMQPChannel
    {
        return $this->connection->channel();
    }
}
