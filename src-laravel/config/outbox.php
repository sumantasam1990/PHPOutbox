<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Outbox Table Name
    |--------------------------------------------------------------------------
    |
    | The database table where outbox messages are stored. This table must
    | exist in the same database as your application's business tables so
    | that writes can be wrapped in the same transaction.
    |
    */
    'table_name' => env('OUTBOX_TABLE', 'outbox_messages'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for the outbox table. Set to null to
    | use the default connection. This MUST be the same connection used
    | by your application's business logic for atomic guarantees.
    |
    */
    'connection' => env('OUTBOX_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Relay Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the background relay process (artisan outbox:relay).
    |
    */
    'relay' => [
        // Number of messages to fetch per cycle
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),

        // Milliseconds between poll cycles
        'poll_interval_ms' => (int) env('OUTBOX_POLL_INTERVAL', 1000),

        // Maximum publish attempts before dead-lettering
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),

        // Base backoff in ms between retries (multiplied by attempt #)
        'retry_backoff_ms' => (int) env('OUTBOX_RETRY_BACKOFF', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher Configuration
    |--------------------------------------------------------------------------
    |
    | How outbox messages are published to your queue/broker.
    |
    */
    'publisher' => [
        // The Laravel queue connection to dispatch to
        'queue_connection' => env('OUTBOX_QUEUE_CONNECTION', null),

        // The queue name to dispatch to
        'queue_name' => env('OUTBOX_QUEUE_NAME', 'outbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Old published and dead-lettered messages are pruned by the
    | artisan outbox:prune command. Schedule it in your Kernel.
    |
    */
    'prune_after_days' => (int) env('OUTBOX_PRUNE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Delete on Publish
    |--------------------------------------------------------------------------
    |
    | If true, messages are DELETE'd from the table after successful publish.
    | If false (default), they are marked as "published" and can be pruned later.
    | Keeping them provides an audit trail; deleting reduces table size.
    |
    */
    'delete_on_publish' => (bool) env('OUTBOX_DELETE_ON_PUBLISH', false),

    /*
    |--------------------------------------------------------------------------
    | Lock Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Timeout for row-level locks when fetching pending messages.
    |
    */
    'lock_timeout_seconds' => (int) env('OUTBOX_LOCK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | ID Generator
    |--------------------------------------------------------------------------
    |
    | The strategy for generating outbox message IDs.
    | Supported: "uuid7", "ulid"
    |
    */
    'id_generator' => env('OUTBOX_ID_GENERATOR', 'uuid7'),
];
