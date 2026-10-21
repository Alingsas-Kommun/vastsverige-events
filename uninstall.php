<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (
    [
        'vastsverige_events_api_url',
        'vastsverige_events_config_id',
        'vastsverige_events_post_status',
        'vastsverige_events_cron',
        'vastsverige_events_lock',
        'vastsverige_events_last_run',
    ] as $option
) {
    delete_option($option);
}

wp_clear_scheduled_hook('vastsverige_events_import');
