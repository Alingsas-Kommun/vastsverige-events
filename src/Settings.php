<?php

declare(strict_types=1);

namespace VastsverigeEvents;

/**
 * Settings screen for the API source.
 */
class Settings
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_menu', [$this, 'menu']);
    }

    public function registerSettings(): void
    {
        register_setting('vastsverige_events', Options::API_URL, ['sanitize_callback' => 'esc_url_raw']);
        register_setting('vastsverige_events', Options::CONFIG_ID, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(
            'vastsverige_events',
            Options::POST_STATUS,
            [
                'sanitize_callback' => static function (string $value): string {
                    return in_array($value, ['publish', 'draft'], true) ? $value : 'publish';
                },
            ]
        );
        register_setting(
            'vastsverige_events',
            Options::CRON,
            [
                'sanitize_callback' => static function (mixed $value): string {
                    return $value === '1' ? '1' : '0';
                },
            ]
        );
    }

    public function menu(): void
    {
        add_options_page(
            __('Västsverige', 'vastsverige-events'),
            __('Västsverige', 'vastsverige-events'),
            'manage_options',
            'vastsverige-events',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $options = new Options();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Västsverige', 'vastsverige-events'); ?></h1>
            <form action="options.php" method="post">
                <?php settings_fields('vastsverige_events'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vastsverige-api-url"><?php echo esc_html__('API-adress', 'vastsverige-events'); ?></label></th>
                        <td><input name="<?php echo esc_attr(Options::API_URL); ?>" id="vastsverige-api-url" type="url" class="regular-text" value="<?php echo esc_attr($options->apiUrl()); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vastsverige-config"><?php echo esc_html__('Konfiguration', 'vastsverige-events'); ?></label></th>
                        <td><input name="<?php echo esc_attr(Options::CONFIG_ID); ?>" id="vastsverige-config" type="text" class="regular-text" value="<?php echo esc_attr($options->configId()); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Status för nya inlägg', 'vastsverige-events'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(Options::POST_STATUS); ?>" value="publish" <?php checked($options->postStatus(), 'publish'); ?>> <?php echo esc_html__('Publicera', 'vastsverige-events'); ?></label><br>
                            <label><input type="radio" name="<?php echo esc_attr(Options::POST_STATUS); ?>" value="draft" <?php checked($options->postStatus(), 'draft'); ?>> <?php echo esc_html__('Utkast', 'vastsverige-events'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Timvis import', 'vastsverige-events'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(Options::CRON); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr(Options::CRON); ?>" value="1" <?php checked($options->cronEnabled()); ?>> <?php echo esc_html__('Kör importen varje timme', 'vastsverige-events'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
