<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;

/**
 * Artisan command to prune old outbox messages.
 *
 * Schedule this in your Console\Kernel:
 *   $schedule->command('outbox:prune')->daily();
 *
 * Usage:
 *   php artisan outbox:prune         # Prune published messages older than config days
 *   php artisan outbox:prune --days=7
 *   php artisan outbox:prune --dead-letter  # Also prune dead-lettered messages
 */
final class OutboxPruneCommand extends Command
{
    protected $signature = 'outbox:prune
        {--days= : Override the retention period in days}
        {--dead-letter : Also prune dead-lettered messages}';

    protected $description = 'Remove old published outbox messages';

    public function handle(OutboxStore $store): int
    {
        $days = (int) ($this->option('days') ?: config('outbox.prune_after_days', 30));
        $before = new DateTimeImmutable("-{$days} days");

        $this->info(\sprintf('Pruning published messages older than %d days (%s)...', $days, $before->format('Y-m-d H:i:s')));

        $published = $store->prune(OutboxMessageStatus::Published, $before);
        $this->line(\sprintf('  Pruned %d published messages.', $published));

        $deadLettered = 0;
        if ($this->option('dead-letter')) {
            $deadLettered = $store->prune(OutboxMessageStatus::DeadLetter, $before);
            $this->line(\sprintf('  Pruned %d dead-lettered messages.', $deadLettered));
        }

        $total = $published + $deadLettered;
        $this->info(\sprintf('✅ Total pruned: %d messages.', $total));

        return self::SUCCESS;
    }
}
