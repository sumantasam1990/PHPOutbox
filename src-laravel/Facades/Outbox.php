<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Message\OutboxMessage;
use PhpOutbox\Outbox\Outbox as OutboxInstance;

/**
 * Laravel Facade for the Outbox service.
 *
 * @method static OutboxMessage store(string $aggregateType, string $aggregateId, string $eventType, array<mixed>|string $payload, array<string, string> $headers = [])
 * @method static OutboxMessage[] storeMany(array<int, array<string, mixed>> $items)
 * @method static OutboxStore getStore()
 *
 * @see OutboxInstance
 */
final class Outbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'outbox';
    }
}
