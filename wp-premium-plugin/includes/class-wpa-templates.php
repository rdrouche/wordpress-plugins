<?php
defined('ABSPATH') || exit;

class WPA_Templates {

    /**
     * Get a template part from the /templates/ directory.
     * Theme can override by placing the file in /wp-content/themes/your-theme/wpa/
     */
    public static function get(string $name): string {
        $file = "{$name}.php";

        // Allow theme override
        $theme_path  = get_stylesheet_directory() . "/wpa/{$file}";
        $plugin_path = WPA_PLUGIN_DIR . "templates/{$file}";

        $path = file_exists($theme_path) ? $theme_path : $plugin_path;

        if (!file_exists($path)) return '';

        ob_start();
        include $path;
        return ob_get_clean();
    }
}
