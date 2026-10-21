<?php

declare(strict_types=1);

namespace VastsverigeEvents;

use VastsverigeEvents\Cli\ImportCommand;

/**
 * Wires admin, cron, and CLI.
 */
class Plugin
{
    public function register(): void
    {
        Options::ensureDefaults();
        (new Settings())->register();
        (new Cron())->register();
        (new ImportCommand())->register();
    }
}
