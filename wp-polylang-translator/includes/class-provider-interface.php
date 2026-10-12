<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface WPPT_Provider_Interface {
    /**
     * Translate a string.
     *
     * @param string $text      Text to translate.
     * @param string $source    Source language code (ISO 639-1).
     * @param string $target    Target language code (ISO 639-1).
     * @return string|WP_Error  Translated string or WP_Error on failure.
     */
    public function translate( $text, $source, $target );

    /**
     * Check if the provider is properly configured.
     *
     * @return bool
     */
    public function is_configured();

    /**
     * Return the provider label.
     *
     * @return string
     */
    public function get_label();
}
