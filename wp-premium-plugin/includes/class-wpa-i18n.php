<?php
defined('ABSPATH') || exit;

class WPA_i18n {

    /**
     * Load plugin text domain.
     * Compatible with Polylang — language is already set at this point.
     */
    public static function load(): void {
        load_plugin_textdomain(
            WPA_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(WPA_PLUGIN_DIR . 'wp-premium-access.php')) . '/languages'
        );
    }
}
