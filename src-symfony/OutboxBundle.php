<?php

declare(strict_types=1);

namespace PhpOutbox\Outbox\Symfony;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Symfony Bundle for the Outbox package.
 *
 * Registers services, commands, and configuration for the
 * transactional outbox pattern within a Symfony application.
 */
final class OutboxBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/src-symfony';
    }
}
