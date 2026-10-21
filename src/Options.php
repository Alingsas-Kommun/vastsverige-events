<?php

declare(strict_types=1);

namespace VastsverigeEvents;

/**
 * Plugin settings stored as individual options.
 */
class Options
{
    public const API_URL     = 'vastsverige_events_api_url';
    public const CONFIG_ID   = 'vastsverige_events_config_id';
    public const POST_STATUS = 'vastsverige_events_post_status';
    public const CRON        = 'vastsverige_events_cron';

    /**
     * Write defaults without overwriting values already saved.
     */
    public static function ensureDefaults(): void
    {
        add_option(self::API_URL, 'https://www.vastsverige.com/api');
        add_option(self::CONFIG_ID, '2671');
        add_option(self::POST_STATUS, 'publish');
        add_option(self::CRON, '0');
    }

    /**
     * @return non-empty-string
     */
    public function apiUrl(): string
    {
        $url = trim((string) get_option(self::API_URL, 'https://www.vastsverige.com/api'));
        return $url !== '' ? untrailingslashit($url) : 'https://www.vastsverige.com/api';
    }

    public function configId(): string
    {
        $configId = trim((string) get_option(self::CONFIG_ID, '2671'));
        return $configId !== '' ? $configId : '2671';
    }

    /**
     * Status applied to posts created by the importer.
     */
    public function postStatus(): string
    {
        $status = (string) get_option(self::POST_STATUS, 'publish');
        return in_array($status, ['publish', 'draft'], true) ? $status : 'publish';
    }

    public function cronEnabled(): bool
    {
        return get_option(self::CRON, '0') === '1';
    }
}
