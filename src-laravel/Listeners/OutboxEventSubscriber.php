<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Listeners;

use Illuminate\Support\Facades\DB;
use PhpOutbox\Outbox\Outbox;

/**
 * Laravel event subscriber that auto-captures events to the outbox.
 *
 * Register this subscriber to automatically intercept specific Laravel events
 * and store them in the outbox table. Useful when you want transparent outbox
 * integration without modifying existing event dispatching code.
 *
 * Register in your EventServiceProvider:
 *   protected $subscribe = [OutboxEventSubscriber::class];
 *
 * Or register manually:
 *   Event::subscribe(OutboxEventSubscriber::class);
 */
final class OutboxEventSubscriber
{
    /**
     * @var array<string, array{aggregate_type: string, event_type: string}>
     *
     * Map Laravel event classes to outbox aggregate/event types.
     * Override this by extending the class or via config.
     */
    private array $eventMap = [];

    public function __construct(
        private readonly Outbox $outbox,
    ) {
        /** @var array<string, array{aggregate_type: string, event_type: string}> $map */
        $map = config('outbox.event_map', []);
        $this->eventMap = $map;
    }

    /**
     * Handle the event by storing it in the outbox.
     *
     * @param object $event The Laravel event instance
     */
    public function handleEvent(object $event): void
    {
        $eventClass = $event::class;

        if (!isset($this->eventMap[$eventClass])) {
            return;
        }

        $mapping = $this->eventMap[$eventClass];
        $aggregateType = $mapping['aggregate_type'];
        $eventType = $mapping['event_type'];

        // Extract aggregate ID from the event if available
        $aggregateId = '';
        if (\method_exists($event, 'getAggregateId')) {
            $aggregateId = (string) $event->getAggregateId();
        } elseif (\property_exists($event, 'id')) {
            $aggregateId = (string) $event->id;
        }

        // Extract payload
        $payload = [];
        if (\method_exists($event, 'toOutboxPayload')) {
            $payload = $event->toOutboxPayload();
        } elseif (\method_exists($event, 'toArray')) {
            $payload = $event->toArray();
        } else {
            $payload = \get_object_vars($event);
        }

        $this->outbox->store($aggregateType, $aggregateId, $eventType, $payload);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        $subscriptions = [];

        foreach (\array_keys($this->eventMap) as $eventClass) {
            $subscriptions[$eventClass] = 'handleEvent';
        }

        return $subscriptions;
    }
}
