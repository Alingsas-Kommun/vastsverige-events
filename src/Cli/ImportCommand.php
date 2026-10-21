<?php

declare(strict_types=1);

namespace VastsverigeEvents\Cli;

use VastsverigeEvents\Actor;
use VastsverigeEvents\Import\Runner;

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
     * : Only import events whose title contains this text. Skips trashing.
     *
     * [--allow-mass-trash]
     * : Trash events missing from the API even when that is more than the safety limit allows.
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
        $mass    = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'allow-mass-trash', false);

        $report = (new Runner())->run($limit, $heading, $mass);
        foreach ($report->messages as $message) {
            \WP_CLI::log($message);
        }

        \WP_CLI::log($report->summary());

        if (! $report->ok) {
            \WP_CLI::error('Import failed.');
        }

        \WP_CLI::success('Import finished.');
    }
}
