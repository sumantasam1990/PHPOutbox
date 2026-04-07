<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Commands;

use Illuminate\Console\Command;
use PhpOutbox\Outbox\Relay\OutboxRelay;

/**
 * Artisan command to run the outbox relay daemon.
 *
 * Usage:
 *   php artisan outbox:relay              # Run as daemon
 *   php artisan outbox:relay --once       # Single pass, then exit
 *   php artisan outbox:relay --batch=50   # Custom batch size
 */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Run a single relay cycle and exit}
        {--batch= : Override the batch size}
        {--interval= : Override poll interval in milliseconds}';

    protected $description = 'Run the outbox relay to publish pending messages to the queue';

    public function handle(OutboxRelay $relay): int
    {
        $this->info('🚀 Starting outbox relay...');
        $this->newLine();

        if ($this->option('once')) {
            $metrics = $relay->runOnce();
            $this->line($metrics->summary());

            if (!$metrics->hasActivity()) {
                $this->info('No pending messages found.');
            }

            return self::SUCCESS;
        }

        $this->info('Running in daemon mode. Press Ctrl+C to stop gracefully.');
        $this->newLine();

        $relay->run();

        $this->info('Relay stopped.');

        return self::SUCCESS;
    }
}
