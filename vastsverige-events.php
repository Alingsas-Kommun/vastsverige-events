<?php

/**
 * Plugin Name: Västsverige Events
 * Description: Imports events from Västsverige into Event Manager posts.
 * Version: 0.1.0
 * Author: Alingsås kommun
 * Text Domain: vastsverige-events
 *
 * @package VastsverigeEvents
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('VASTSVERIGE_EVENTS_FILE', __FILE__);
define('VASTSVERIGE_EVENTS_PATH', plugin_dir_path(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'VastsverigeEvents\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path     = VASTSVERIGE_EVENTS_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($path)) {
            require $path;
        }
    }
);

register_activation_hook(
    VASTSVERIGE_EVENTS_FILE,
    static function (): void {
        VastsverigeEvents\Options::ensureDefaults();
        VastsverigeEvents\Cron::reschedule();
    }
);

register_deactivation_hook(
    VASTSVERIGE_EVENTS_FILE,
    static function (): void {
        wp_clear_scheduled_hook(VastsverigeEvents\Cron::HOOK);
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        (new VastsverigeEvents\Plugin())->register();
    }
);
