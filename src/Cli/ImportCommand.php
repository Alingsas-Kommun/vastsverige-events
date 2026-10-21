<?php

declare(strict_types=1);

namespace VastsverigeEvents\Cli;

use VastsverigeEvents\Actor;
use VastsverigeEvents\Import\Importer;

/**
 * WP-CLI command for a single import run.
 */
class ImportCommand
{
    public function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_command('vastsverige import', [$this, 'import']);
    }

    /**
     * Import events from Västsverige into Event Manager.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Write at most this many merged events. Skips trashing.
     *
     * [--heading=<text>]
     * : Only import events whose title contains this text.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        if (! Actor::set()) {
            \WP_CLI::error('No administrator user is available to own the imported posts.');
        }

        $limit = isset($assoc['limit']) ? max(0, (int) $assoc['limit']) : null;
        if ($limit === 0) {
            $limit = null;
        }
        $heading = isset($assoc['heading']) ? (string) $assoc['heading'] : null;

        $report = (new Importer())->run($limit, $heading);
        foreach ($report->messages as $message) {
            \WP_CLI::log($message);
        }

        \WP_CLI::log(sprintf('created=%d updated=%d trashed=%d skipped=%d', $report->created, $report->updated, $report->trashed, $report->skipped));

        if (! $report->ok) {
            \WP_CLI::error('Import failed.');
        }

        \WP_CLI::success('Import finished.');
    }
}
