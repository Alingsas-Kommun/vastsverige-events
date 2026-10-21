<?php

declare(strict_types=1);

namespace VastsverigeEvents\Import;

use VastsverigeEvents\Api\Client;
use VastsverigeEvents\ImportReport;
use VastsverigeEvents\Mapping\EventWriter;
use VastsverigeEvents\Options;

/**
 * Runs one import with a lock, so cron and WP-CLI never write at the same time.
 */
class Runner
{
    public const LOCK_OPTION     = 'vastsverige_events_lock';
    public const LAST_RUN_OPTION = 'vastsverige_events_last_run';

    private const LOCK_TTL = 1800;

    public function __construct(private Options $options = new Options())
    {
    }

    /**
     * @param int|null    $limit          Maximum merged events to write. Disables trashing.
     * @param string|null $heading        Only events whose title contains this text. Disables trashing.
     * @param bool        $allowMassTrash Skip the limit on how many events one run may trash.
     */
    public function run(?int $limit = null, ?string $heading = null, bool $allowMassTrash = false): ImportReport
    {
        if (! $this->acquire()) {
            $report = new ImportReport();
            $report->fail('Another import is already running.');
            return $report;
        }

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $this->allowOrganizationTerms();
            $report = $this->importer()->run($limit, $heading, $allowMassTrash);
        } catch (\Throwable $throwable) {
            $report = new ImportReport();
            $report->fail('Import crashed: ' . $throwable->getMessage());
        } finally {
            delete_option(self::LOCK_OPTION);
        }

        $this->remember($report);

        return $report;
    }

    private function importer(): Importer
    {
        $apiUrl = $this->options->apiUrl();

        $ratio = (float) apply_filters('vastsverige_events/max_trash_ratio', 0.3);

        return new Importer(
            new Client($apiUrl),
            new EventWriter(),
            new EventMerger($apiUrl),
            new TrashGuard($ratio),
            $this->options->configId(),
            $this->options->postStatus()
        );
    }

    private function acquire(): bool
    {
        if (add_option(self::LOCK_OPTION, (string) time(), '', false)) {
            return true;
        }

        wp_cache_delete(self::LOCK_OPTION, 'options');
        $startedAt = (int) get_option(self::LOCK_OPTION, 0);
        if ($startedAt > 0 && time() - $startedAt < self::LOCK_TTL) {
            return false;
        }

        delete_option(self::LOCK_OPTION);

        return (bool) add_option(self::LOCK_OPTION, (string) time(), '', false);
    }

    private function remember(ImportReport $report): void
    {
        update_option(
            self::LAST_RUN_OPTION,
            [
                'time'     => time(),
                'ok'       => $report->ok,
                'summary'  => $report->summary(),
                'messages' => array_slice($report->messages, 0, 50),
            ],
            false
        );
    }

    /**
     * Organization terms use capabilities the administrator role does not store directly.
     */
    private function allowOrganizationTerms(): void
    {
        add_filter(
            'user_has_cap',
            static function (array $allcaps, array $caps, array $args, \WP_User $user): array {
                $isAdmin = in_array('administrator', $user->roles, true) || is_super_admin($user->ID);
                if (! $isAdmin) {
                    return $allcaps;
                }
                foreach (['manage_organization', 'edit_organization', 'assign_organization', 'delete_organization'] as $cap) {
                    $allcaps[$cap] = true;
                }
                return $allcaps;
            },
            10,
            4
        );
    }
}
