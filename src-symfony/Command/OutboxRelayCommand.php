<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony\Command;

use PhpOutbox\Outbox\Relay\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony console command to run the outbox relay daemon.
 */
#[AsCommand(
    name: 'outbox:relay',
    description: 'Run the outbox relay to publish pending messages',
)]
final class OutboxRelayCommand extends Command
{
    public function __construct(
        private readonly OutboxRelay $relay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single relay cycle and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Outbox Relay');

        if ($input->getOption('once')) {
            $metrics = $this->relay->runOnce();
            $io->writeln($metrics->summary());

            return Command::SUCCESS;
        }

        $io->info('Running in daemon mode. Press Ctrl+C to stop.');
        $this->relay->run();

        $io->success('Relay stopped.');

        return Command::SUCCESS;
    }
}
