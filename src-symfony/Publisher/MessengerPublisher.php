<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony\Publisher;

use PhpOutbox\Outbox\Contracts\OutboxPublisher;
use PhpOutbox\Outbox\Exception\PublishException;
use PhpOutbox\Outbox\Message\OutboxMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Publisher that dispatches outbox messages to Symfony Messenger.
 *
 * Each outbox message is wrapped in a Messenger Envelope and dispatched
 * to the configured message bus. Downstream handlers process the events.
 */
final class MessengerPublisher implements OutboxPublisher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ?string $transportName = null,
    ) {
    }

    public function publish(OutboxMessage $message): void
    {
        try {
            $envelope = new Envelope($message);

            if ($this->transportName !== null) {
                $envelope = $envelope->with(new TransportNamesStamp([$this->transportName]));
            }

            $this->bus->dispatch($envelope);
        } catch (\Throwable $e) {
            throw PublishException::failed($message->id, $e);
        }
    }

    public function publishBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $this->publish($message);
        }
    }
}
