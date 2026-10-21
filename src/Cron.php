<?php

declare(strict_types=1);

namespace VastsverigeEvents;

/**
 * Hourly import when the setting is enabled.
 */
class Cron
{
    public const HOOK = 'vastsverige_events_import';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', [$this, 'ensureScheduled']);
    }

    public function ensureScheduled(): void
    {
        $enabled   = (new Options())->cronEnabled();
        $scheduled = wp_next_scheduled(self::HOOK);

        if ($enabled && $scheduled === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
            return;
        }

        if (! $enabled && $scheduled !== false) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        if ((new Options())->cronEnabled()) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public function run(): void
    {
        if (! Actor::set()) {
            return;
        }
        (new Import\Importer())->run();
    }
}
