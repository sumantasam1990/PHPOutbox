<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony\Command;

use DateTimeImmutable;
use PhpOutbox\Outbox\Contracts\OutboxStore;
use PhpOutbox\Outbox\Enum\OutboxMessageStatus;
use PhpOutbox\Outbox\OutboxConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony console command to prune old outbox messages.
 */
#[AsCommand(
    name: 'outbox:prune',
    description: 'Remove old published outbox messages',
)]
final class OutboxPruneCommand extends Command
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly OutboxConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Retention period in days')
            ->addOption('dead-letter', null, InputOption::VALUE_NONE, 'Also prune dead-lettered messages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) ($input->getOption('days') ?: $this->config->pruneAfterDays);
        $before = new DateTimeImmutable("-{$days} days");

        $io->info(\sprintf('Pruning messages older than %d days...', $days));

        $published = $this->store->prune(OutboxMessageStatus::Published, $before);
        $io->writeln(\sprintf('  Pruned %d published messages.', $published));

        $deadLettered = 0;
        if ($input->getOption('dead-letter')) {
            $deadLettered = $this->store->prune(OutboxMessageStatus::DeadLetter, $before);
            $io->writeln(\sprintf('  Pruned %d dead-lettered messages.', $deadLettered));
        }

        $io->success(\sprintf('Total pruned: %d messages.', $published + $deadLettered));

        return Command::SUCCESS;
    }
}
