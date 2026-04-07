<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOutbox\Outbox\Store\Schema;

/**
 * Artisan command to create the outbox table.
 *
 * Usage:
 *   php artisan outbox:migrate
 *   php artisan outbox:migrate --connection=mysql
 *   php artisan outbox:migrate --fresh  # Drop and recreate
 */
final class OutboxMigrateCommand extends Command
{
    protected $signature = 'outbox:migrate
        {--connection= : Database connection to use}
        {--fresh : Drop and recreate the outbox table}';

    protected $description = 'Create the outbox messages table';

    public function handle(): int
    {
        $connection = $this->option('connection')
            ?? config('outbox.connection')
            ?? config('database.default');

        $tableName = config('outbox.table_name', 'outbox_messages');

        /** @var \Illuminate\Database\Connection $db */
        $db = DB::connection($connection);
        $driver = $db->getDriverName();

        $this->info(\sprintf('Using connection: %s (driver: %s)', $connection, $driver));

        if ($this->option('fresh')) {
            $this->warn('Dropping existing outbox table...');
            $db->unprepared(Schema::drop($tableName));
            $this->info('Table dropped.');
        }

        $sql = Schema::forDriver($driver, $tableName);

        $db->unprepared($sql);

        $this->info(\sprintf('✅ Outbox table "%s" created successfully.', $tableName));

        return self::SUCCESS;
    }
}
