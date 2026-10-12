<?php
/**
 * RDR-IT Login Security — DB
 * Crée la table wp_rdrit_login_logs à l'activation.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRIT_LOGIN_DB_VERSION', '1.0' );

function rdrit_login_create_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'rdrit_login_logs';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_time    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        event_type    VARCHAR(20)     NOT NULL COMMENT 'attempt|ban|unban|pwd_reset',
        result        VARCHAR(20)     NOT NULL COMMENT 'success|fail|blocked|bot',
        ip            VARCHAR(45)     NOT NULL,
        user_agent    TEXT,
        email         VARCHAR(255),
        pwd_hash      VARCHAR(64)     COMMENT 'sha256 du mot de passe saisi',
        country_code  VARCHAR(2),
        country_name  VARCHAR(100),
        city          VARCHAR(100),
        ban_until     DATETIME        NULL COMMENT 'rempli si event_type=ban',
        PRIMARY KEY (id),
        KEY idx_ip        (ip),
        KEY idx_time      (event_time),
        KEY idx_type      (event_type),
        KEY idx_email     (email(100))
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'rdrit_login_db_version', RDRIT_LOGIN_DB_VERSION );
}

// Appeler à l'activation du plugin
register_activation_hook( RDRIT_TOOLS_FILE, 'rdrit_login_create_table' );

// Vérifier à chaque chargement si la table existe (migration)
add_action( 'plugins_loaded', function() {
    if ( get_option( 'rdrit_login_db_version' ) !== RDRIT_LOGIN_DB_VERSION ) {
        rdrit_login_create_table();
    }
} );
