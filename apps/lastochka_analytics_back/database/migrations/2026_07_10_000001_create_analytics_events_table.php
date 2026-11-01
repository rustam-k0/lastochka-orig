<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Сырой append-only журнал аналитических событий (#11596).
     *
     * Партиционирование по месяцам от occurred_at — Blueprint этого не умеет,
     * поэтому raw SQL. PK и UNIQUE обязаны включать ключ партиционирования.
     * Сами партиции создаёт команда analytics:ensure-partitions.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE analytics_events (
                id             BIGINT GENERATED ALWAYS AS IDENTITY,
                event_id       UUID        NOT NULL,
                event_type     VARCHAR     NOT NULL,
                occurred_at    TIMESTAMPTZ NOT NULL,
                source         VARCHAR     NOT NULL,
                correlation_id VARCHAR     NULL,
                session_id     VARCHAR     NULL,
                store_id       VARCHAR     NULL,
                employee_id    VARCHAR     NULL,
                role           VARCHAR     NULL,
                order_id       VARCHAR     NULL,
                client_id      VARCHAR     NULL,
                payload        JSONB       NOT NULL,
                PRIMARY KEY (id, occurred_at),
                UNIQUE (event_id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('CREATE INDEX analytics_events_client_idx ON analytics_events (client_id, occurred_at)');
        DB::statement('CREATE INDEX analytics_events_store_idx ON analytics_events (store_id, occurred_at)');
        DB::statement('CREATE INDEX analytics_events_employee_idx ON analytics_events (employee_id, occurred_at)');
        DB::statement('CREATE INDEX analytics_events_session_idx ON analytics_events (session_id)');
        DB::statement('CREATE INDEX analytics_events_type_idx ON analytics_events (event_type)');
        DB::statement('CREATE INDEX analytics_events_payload_idx ON analytics_events USING GIN (payload)');

        // DEFAULT-партиция: событие с occurred_at вне созданных диапазонов
        // не должно теряться (append-only журнал важнее аккуратности партиций).
        DB::statement('CREATE TABLE analytics_events_default PARTITION OF analytics_events DEFAULT');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS analytics_events CASCADE');
    }
};
