<?php
defined('ABSPATH') || exit;

class WPA_Admin {

    public static function init(): void {
        add_action('admin_menu',            [self::class, 'add_menu']);
        add_action('admin_init',            [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        // AJAX handlers
        add_action('wp_ajax_wpa_save_role',    [self::class, 'ajax_save_role']);
        add_action('wp_ajax_wpa_delete_role',  [self::class, 'ajax_delete_role']);
        add_action('wp_ajax_wpa_simulate_webhook',   [self::class, 'ajax_simulate_webhook']);
        add_action('wp_ajax_wpa_reset_rate_limits',   [self::class, 'ajax_reset_rate_limits']);
        add_action('wp_ajax_wpa_update_database',      [self::class, 'ajax_update_database']);
        add_action('wp_ajax_wpa_clear_single_rl',      [self::class, 'ajax_clear_single_rl']);
        add_action('wp_ajax_wpa_generate_api_token',   [self::class, 'ajax_generate_api_token']);
        add_action('wp_ajax_wpa_revoke_api_token',     [self::class, 'ajax_revoke_api_token']);
        add_action('wp_ajax_wpa_delete_api_token',     [self::class, 'ajax_delete_api_token']);
        add_action('wp_ajax_wpa_send_manual_email',  [self::class, 'ajax_send_manual_email']);
        add_action('wp_ajax_wpa_resync_capabilities', [self::class, 'ajax_resync_capabilities']);
        add_action('wp_ajax_wpa_grant_user_role',     [self::class, 'ajax_grant_user_role']);
        add_action('wp_ajax_wpa_create_capability',   [self::class, 'ajax_create_capability']);
        add_action('wp_ajax_wpa_save_capability',     [self::class, 'ajax_save_capability']);
        add_action('wp_ajax_wpa_delete_capability',   [self::class, 'ajax_delete_capability']);
        add_action('wp_ajax_wpa_revoke_user_role',    [self::class, 'ajax_revoke_user_role']);
        add_action('wp_ajax_wpa_reset_user_sessions', [self::class, 'ajax_reset_user_sessions']);
        add_action('wp_ajax_wpa_preview_email',      [self::class, 'ajax_preview_email']);
    }

    public static function add_menu(): void {
        // Register hidden admin page for user detail via custom hook
        // Using add_submenu_page(null, ...) triggers deprecated warnings in WP 6.x
        // Instead we hook into the page load action directly
        add_action('admin_init', function () {
            if (isset($_GET['page']) && $_GET['page'] === 'wpa-user-detail') {
                if (!current_user_can('manage_options')) {
                    wp_die(esc_html__('Access denied.', WPA_TEXT_DOMAIN));
                }
                // Register the page title for the admin
                add_filter('admin_title', function ($title) {
                    return __('User Detail', WPA_TEXT_DOMAIN) . ' &lsaquo; ' . get_bloginfo('name');
                });
            }
        });
        // Register the menu page callback so WordPress knows how to render it
        add_action('admin_menu', function () {
            // Use wpa-settings as parent with empty menu_title to hide from nav
            // This avoids the null parent deprecation warning
            add_submenu_page(
                'wpa-settings',
                __('User Detail', WPA_TEXT_DOMAIN),
                '', // empty string hides from menu display
                'manage_options',
                'wpa-user-detail',
                ['WPA_Admin', 'render_user_detail']
            );
        }, 99); // late priority — after main menu
        add_menu_page(
            __('Premium Access', WPA_TEXT_DOMAIN),
            __('Premium Access', WPA_TEXT_DOMAIN),
            'manage_options',
            'wpa-settings',
            [self::class, 'render_settings'],
            'dashicons-star-filled',
            30
        );
        add_submenu_page('wpa-settings', __('Settings', WPA_TEXT_DOMAIN),   __('Settings', WPA_TEXT_DOMAIN),   'manage_options', 'wpa-settings',      [self::class, 'render_settings']);
        add_submenu_page('wpa-settings', __('Roles', WPA_TEXT_DOMAIN),      __('Roles', WPA_TEXT_DOMAIN),      'manage_options', 'wpa-roles',          [self::class, 'render_roles']);
        add_submenu_page('wpa-settings', __('Users', WPA_TEXT_DOMAIN),      __('Users', WPA_TEXT_DOMAIN),      'manage_options', 'wpa-users',          [self::class, 'render_users']);
        add_submenu_page('wpa-settings', __('Magic Link Log', WPA_TEXT_DOMAIN), __('Magic Link Log', WPA_TEXT_DOMAIN), 'manage_options', 'wpa-magic-log', [self::class, 'render_magic_log']);
        add_submenu_page('wpa-settings', __('Webhook Log', WPA_TEXT_DOMAIN),__('Webhook Log', WPA_TEXT_DOMAIN),'manage_options', 'wpa-webhook-log',    [self::class, 'render_webhook_log']);
        add_submenu_page('wpa-settings', __('SSO', WPA_TEXT_DOMAIN), __('SSO', WPA_TEXT_DOMAIN), 'manage_options', 'wpa-sso', [self::class, 'render_sso']);
        add_submenu_page('wpa-settings', __('API', WPA_TEXT_DOMAIN),         __('API', WPA_TEXT_DOMAIN),         'manage_options', 'wpa-api',             [self::class, 'render_api']);
        add_submenu_page('wpa-settings', __('Stripe', WPA_TEXT_DOMAIN),      __('Stripe', WPA_TEXT_DOMAIN),      'manage_options', 'wpa-stripe',          [self::class, 'render_stripe']);
        add_submenu_page('wpa-settings', __('Emails', WPA_TEXT_DOMAIN),      __('Emails', WPA_TEXT_DOMAIN),      'manage_options', 'wpa-emails',          [self::class, 'render_emails']);
        // User Detail — hidden from nav (registered via admin_page hook, not submenu)
    }

    public static function register_settings(): void {
        register_setting('wpa_settings', 'wpa_kofi_token',        ['sanitize_callback' => 'sanitize_text_field']);

        register_setting('wpa_settings', 'wpa_test_mode',      ['sanitize_callback' => 'absint']);

        // Ko-fi mappings — separate group so saving one doesn't wipe the other
        register_setting('wpa_kofi_mappings', 'wpa_kofi_tiers',      ['sanitize_callback' => [self::class, 'sanitize_tiers']]);
        register_setting('wpa_kofi_mappings', 'wpa_kofi_tier_names', ['sanitize_callback' => [self::class, 'sanitize_tier_names']]);
        register_setting('wpa_kofi_mappings', 'wpa_shop_items',      ['sanitize_callback' => [self::class, 'sanitize_shop_items']]);
        // Rate limits — kept in wpa_settings so they save with the main settings form
        register_setting('wpa_sso_settings', 'wpa_sso_allowed_origins', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('wpa_sso_settings', 'wpa_sso_jwt_ttl',          ['sanitize_callback' => 'absint']);
        register_setting('wpa_redirect_settings', 'wpa_post_login_redirect_default', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('wpa_sso_settings', 'wpa_sso_allowed_origins', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('wpa_sso_settings', 'wpa_sso_jwt_ttl',          ['sanitize_callback' => 'absint']);
        register_setting('wpa_redirect_settings', 'wpa_post_login_redirects',         ['sanitize_callback' => [self::class, 'sanitize_redirect_map']]);
        register_setting('wpa_settings', 'wpa_rl_email_max',    ['sanitize_callback' => 'absint']);
        register_setting('wpa_settings', 'wpa_rl_email_window', ['sanitize_callback' => 'absint']);
        register_setting('wpa_settings', 'wpa_rl_ip_max',       ['sanitize_callback' => 'absint']);
        register_setting('wpa_settings', 'wpa_rl_ip_window',    ['sanitize_callback' => 'absint']);

        register_setting('wpa_settings', 'wpa_export_post_types', ['sanitize_callback' => [self::class, 'sanitize_post_types']]);

        // Capability map
        register_setting('wpa_cap_settings', 'wpa_capability_map', ['sanitize_callback' => [self::class, 'sanitize_capability_map']]);

        // Stripe settings
        register_setting('wpa_stripe_settings', 'wpa_stripe_secret_key',         ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wpa_stripe_settings', 'wpa_stripe_publishable_key',    ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wpa_stripe_settings', 'wpa_stripe_webhook_secret',     ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wpa_stripe_settings', 'wpa_stripe_products',           ['sanitize_callback' => [self::class, 'sanitize_stripe_products']]);
        register_setting('wpa_stripe_settings', 'wpa_stripe_billing_portal_url', ['sanitize_callback' => 'esc_url_raw']);

        // Email settings
        register_setting('wpa_email_settings', 'wpa_email_accent_color',      ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('wpa_email_settings', 'wpa_kofi_url',                ['sanitize_callback' => 'esc_url_raw']);
        register_setting('wpa_email_settings', 'wpa_email_format_welcome',    ['sanitize_callback' => 'sanitize_key']);
        register_setting('wpa_email_settings', 'wpa_email_format_expiry',     ['sanitize_callback' => 'sanitize_key']);
        register_setting('wpa_email_settings', 'wpa_email_subject_welcome',   ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wpa_email_settings', 'wpa_email_body_welcome',      ['sanitize_callback' => 'wp_kses_post']);
        register_setting('wpa_email_settings', 'wpa_email_subject_expiry_warning', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('wpa_email_settings', 'wpa_email_body_expiry_warning',    ['sanitize_callback' => 'wp_kses_post']);
    }

    public static function sanitize_tiers($input): array {
        if (!is_array($input)) return [];
        return array_map(function ($tier) {
            return [
                'min'    => (float) ($tier['min']  ?? 0),
                'role'   => sanitize_text_field($tier['role'] ?? 'premium_basic'),
                'months' => isset($tier['months']) && $tier['months'] !== '' ? (int) $tier['months'] : null,
            ];
        }, $input);
    }

    public static function sanitize_post_types($input): array {
        if (!is_array($input)) return ['post'];
        return array_map('sanitize_key', $input);
    }

    public static function sanitize_tier_names($input): array {
        if (!is_array($input)) return [];
        $out = [];
        foreach ($input as $name => $cfg) {
            $key        = sanitize_text_field($name);
            if (empty($key)) continue;
            $out[$key]  = [
                'role'   => sanitize_key($cfg['role']   ?? 'premium_basic'),
                'months' => isset($cfg['months']) && $cfg['months'] !== '' ? (int) $cfg['months'] : null,
            ];
        }
        return $out;
    }

    public static function sanitize_redirect_map($input): array {
        if (!is_array($input)) return [];
        $clean = [];
        foreach ($input as $lang => $url) {
            $lang = sanitize_key($lang);
            $url  = esc_url_raw(trim($url));
            if ($lang && $url) $clean[$lang] = $url;
        }
        return $clean;
    }

    public static function sanitize_shop_items($input): array {
        if (!is_array($input)) return [];
        $out = [];
        foreach ($input as $code => $cfg) {
            $key       = sanitize_text_field($code);
            if (empty($key)) continue;
            $out[$key] = [
                'role'   => sanitize_key($cfg['role']   ?? 'premium_basic'),
                'months' => isset($cfg['months']) && $cfg['months'] !== '' ? (int) $cfg['months'] : null,
            ];
        }
        return $out;
    }

    public static function enqueue_assets(string $hook): void {
        if (!str_contains($hook, 'wpa')) return;
        wp_enqueue_style('wpa-admin',  WPA_PLUGIN_URL . 'assets/css/admin.css',  [], WPA_VERSION);
        wp_enqueue_script('wpa-admin', WPA_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], WPA_VERSION, true);
        // Pass roles list for dynamic JS row templates
        wp_localize_script('wpa-admin', 'WPA_Admin_Roles', WPA_Roles::get_all_defined_roles());
        wp_localize_script('wpa-admin', 'WPA_Admin', [
            'nonce'       => wp_create_nonce('wpa_admin'),
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'webhookUrl'  => rest_url('wpa/v1/kofi-webhook'),
            'i18n'        => [
                'confirm_delete' => __('Delete this role? Users with this role will keep it until manually revoked.', WPA_TEXT_DOMAIN),
                'saved'          => __('Saved.', WPA_TEXT_DOMAIN),
                'deleted'        => __('Deleted.', WPA_TEXT_DOMAIN),
                'error'          => __('Error. Please try again.', WPA_TEXT_DOMAIN),
                'simulating'     => __('Sending simulated webhook...', WPA_TEXT_DOMAIN),
                'sim_ok'         => __('Webhook simulated successfully.', WPA_TEXT_DOMAIN),
                'sim_error'      => __('Simulation failed.', WPA_TEXT_DOMAIN),
            ],
        ]);
    }

    // =========================================================================
    // Settings page
    // =========================================================================

    public static function render_settings(): void {
        if (!current_user_can('manage_options')) return;

        // Handle manual grant
        if (isset($_POST['wpa_grant_nonce']) && wp_verify_nonce($_POST['wpa_grant_nonce'], 'wpa_grant_role')) {
            $email   = sanitize_email($_POST['grant_email'] ?? '');
            $role    = sanitize_text_field($_POST['grant_role'] ?? '');
            $expires = sanitize_text_field($_POST['grant_expires'] ?? '') ?: null;
            $user    = get_user_by('email', $email);

            if (!$user && is_email($email)) {
                $uid  = wp_create_user($email, wp_generate_password(32, true, true), $email);
                if (!is_wp_error($uid)) {
                    $u = new WP_User($uid);
                    $u->set_role('subscriber');
                    $user = get_user_by('ID', $uid);
                }
            }

            if ($user && $role) {
                WPA_Roles::grant($user->ID, $role, 'admin', $expires ? $expires . ' 00:00:00' : null);
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__('Role granted successfully.', WPA_TEXT_DOMAIN) . '</p></div>';
            }
        }

        $kofi_token  = get_option('wpa_kofi_token', '');
        $webhook_url = rest_url('wpa/v1/kofi-webhook');
        $all_roles   = WPA_Roles::get_all_defined_roles();
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — Settings', WPA_TEXT_DOMAIN); ?></h1>

            <?php /* ---- Ko-fi ---- */ ?>
            <form method="post" action="options.php">
                <?php settings_fields('wpa_settings'); ?>
                <h2><?php esc_html_e('Ko-fi Integration', WPA_TEXT_DOMAIN); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Verification Token', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="text" name="wpa_kofi_token"
                                   value="<?php echo esc_attr($kofi_token); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Found in Ko-fi Settings > API.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Webhook URL', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <code class="wpa-copyable"><?php echo esc_html($webhook_url); ?></code>
                            <p class="description"><?php esc_html_e('Paste this URL in Ko-fi Settings > Webhooks.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Exportable post types', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <?php
                            $saved_types = get_option('wpa_export_post_types', ['post']);
                            $all_types   = get_post_types(['public' => true], 'objects');
                            foreach ($all_types as $pt) :
                                if ($pt->name === 'attachment') continue;
                            ?>
                            <label style="display:block;margin-bottom:.35em">
                                <input type="checkbox" name="wpa_export_post_types[]"
                                       value="<?php echo esc_attr($pt->name); ?>"
                                       <?php checked(in_array($pt->name, (array) $saved_types, true)); ?> />
                                <?php echo esc_html($pt->label); ?>
                                <code style="font-size:.8em;color:#666"><?php echo esc_html($pt->name); ?></code>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Test mode', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpa_test_mode" value="1"
                                       <?php checked(get_option('wpa_test_mode', 0), 1); ?> />
                                <?php esc_html_e('Enable test mode', WPA_TEXT_DOMAIN); ?>
                            </label>
                            <p class="description" style="color:#d97706">
                                <?php esc_html_e('Bypasses rate limits. Magic link URLs are stored for 1 hour and shown in Magic Link Log. Disable in production.', WPA_TEXT_DOMAIN); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', WPA_TEXT_DOMAIN)); ?>
            </form>

            <hr />

            <?php /* ---- Post-login redirect ---- */ ?>
            <form method="post" action="options.php">
                <?php settings_fields('wpa_redirect_settings'); ?>
                <h2><?php esc_html_e('Post-login redirect', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description">
                    <?php esc_html_e('Page where users land after clicking their magic link. Configurable per language if Polylang is active.', WPA_TEXT_DOMAIN); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Default URL', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="url" name="wpa_post_login_redirect_default" class="regular-text"
                                   value="<?php echo esc_attr(get_option('wpa_post_login_redirect_default', '')); ?>"
                                   placeholder="<?php echo esc_attr(home_url('/mon-compte-premium/')); ?>" />
                            <p class="description"><?php esc_html_e('Used when no language-specific URL is set. Leave empty to redirect to Polylang home or site home.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <?php
                    // Per-language URLs — only if Polylang is active
                    if (function_exists('pll_languages_list')) :
                        $languages   = pll_languages_list(['fields' => '']);
                        $saved_urls  = get_option('wpa_post_login_redirects', []);
                        foreach ($languages as $lang) :
                            $slug  = $lang->slug;
                            $name  = $lang->name;
                            $flag  = $lang->flag ?? '';
                            $saved = $saved_urls[$slug] ?? '';
                    ?>
                    <tr>
                        <th>
                            <?php if ($flag) echo '<img src="' . esc_url($flag) . '" style="height:14px;vertical-align:middle;margin-right:6px">'; ?>
                            <?php echo esc_html($name); ?> <code style="font-size:.75rem"><?php echo esc_html($slug); ?></code>
                        </th>
                        <td>
                            <input type="url" name="wpa_post_login_redirects[<?php echo esc_attr($slug); ?>]"
                                   class="regular-text"
                                   value="<?php echo esc_attr($saved); ?>"
                                   placeholder="<?php echo esc_url(pll_home_url($slug) ?: home_url('/')); ?>" />
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </table>
                <?php submit_button(__('Save redirect settings', WPA_TEXT_DOMAIN)); ?>
            </form>

            <hr />

            <?php /* ---- Database ---- */ ?>
            <h2><?php esc_html_e('Database', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description">
                <?php esc_html_e('Run this after plugin updates or if some features are missing. Safe to run at any time — creates missing tables without touching existing data.', WPA_TEXT_DOMAIN); ?>
            </p>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <button type="button" class="button button-primary" id="wpa-update-db">
                    <?php esc_html_e('Update database', WPA_TEXT_DOMAIN); ?>
                </button>
                <span id="wpa-db-feedback" style="font-weight:500"></span>
                <span style="font-size:.8rem;color:#9ca3af">
                    <?php printf(esc_html__('Current DB version: %s', WPA_TEXT_DOMAIN), esc_html(get_option('wpa_db_version', __('not set', WPA_TEXT_DOMAIN)))); ?>
                    &mdash;
                    <?php printf(esc_html__('Plugin version: %s', WPA_TEXT_DOMAIN), esc_html(WPA_VERSION)); ?>
                </span>
            </div>

            <hr />

            <?php /* ---- Rate limits ---- */ ?>
            <h2><?php esc_html_e('Rate limits', WPA_TEXT_DOMAIN); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Per email', WPA_TEXT_DOMAIN); ?></th>
                    <td style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <?php esc_html_e('Max', WPA_TEXT_DOMAIN); ?>
                        <input type="number" name="wpa_rl_email_max" min="1" max="100" style="width:60px"
                               value="<?php echo esc_attr(get_option('wpa_rl_email_max', 3)); ?>" />
                        <?php esc_html_e('requests every', WPA_TEXT_DOMAIN); ?>
                        <input type="number" name="wpa_rl_email_window" min="60" max="86400" style="width:80px"
                               value="<?php echo esc_attr(get_option('wpa_rl_email_window', 900)); ?>" />
                        <?php esc_html_e('seconds', WPA_TEXT_DOMAIN); ?>
                        <span class="description">
                            (<?php echo esc_html(round(get_option('wpa_rl_email_window', 900) / 60)); ?> min)
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Per IP', WPA_TEXT_DOMAIN); ?></th>
                    <td style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <?php esc_html_e('Max', WPA_TEXT_DOMAIN); ?>
                        <input type="number" name="wpa_rl_ip_max" min="1" max="200" style="width:60px"
                               value="<?php echo esc_attr(get_option('wpa_rl_ip_max', 10)); ?>" />
                        <?php esc_html_e('requests every', WPA_TEXT_DOMAIN); ?>
                        <input type="number" name="wpa_rl_ip_window" min="60" max="86400" style="width:80px"
                               value="<?php echo esc_attr(get_option('wpa_rl_ip_window', 3600)); ?>" />
                        <?php esc_html_e('seconds', WPA_TEXT_DOMAIN); ?>
                        <span class="description">
                            (<?php echo esc_html(round(get_option('wpa_rl_ip_window', 3600) / 60)); ?> min)
                        </span>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save rate limit settings', WPA_TEXT_DOMAIN)); ?>

            <?php /* ---- Rate limits live status ---- */ ?>
            <h3 style="margin-top:16px"><?php esc_html_e('Current active limits', WPA_TEXT_DOMAIN); ?></h3>
            <?php
            global $wpdb;
            $active_limits = $wpdb->get_results(
                "SELECT key_hash, hits, window_start
                 FROM {$wpdb->prefix}wpa_rate_limits
                 WHERE window_start > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY hits DESC
                 LIMIT 50"
            );
            if (empty($active_limits)) : ?>
                <p class="description"><?php esc_html_e('No active rate limits.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:600px">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Key (hashed)', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:80px"><?php esc_html_e('Hits', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:150px"><?php esc_html_e('Window start', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:70px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($active_limits as $rl) :
                    $is_ip    = str_starts_with(hash('sha256', 'ip_magic_'), substr($rl->key_hash, 0, 10));
                    $type_label = substr($rl->key_hash, 0, 8);
                ?>
                <tr>
                    <td>
                        <code style="font-size:.75rem"><?php echo esc_html(substr($rl->key_hash, 0, 16)); ?>...</code>
                    </td>
                    <td>
                        <strong><?php echo (int) $rl->hits; ?></strong>
                    </td>
                    <td style="font-size:.85rem"><?php echo esc_html($rl->window_start); ?></td>
                    <td>
                        <button class="button button-small wpa-clear-single-rl"
                                data-hash="<?php echo esc_attr($rl->key_hash); ?>">
                            <?php esc_html_e('Clear', WPA_TEXT_DOMAIN); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div style="margin-top:10px;display:flex;gap:10px;align-items:center">
                <button type="button" class="button" id="wpa-reset-rate-limits">
                    <?php esc_html_e('Reset ALL rate limits', WPA_TEXT_DOMAIN); ?>
                </button>
                <span id="wpa-rate-reset-feedback" style="font-weight:500"></span>
            </div>

            <hr />

            <form method="post" action="options.php">
                <?php settings_fields('wpa_kofi_mappings'); ?>

            <?php /* ---- Amount tiers ---- */ ?>
            <h2><?php esc_html_e('Ko-fi Amount Tiers', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('Map donation amounts to roles. Evaluated top-to-bottom — put highest amounts first.', WPA_TEXT_DOMAIN); ?></p>
            <div id="wpa-tiers-wrap">
            <?php
            $tiers     = get_option('wpa_kofi_tiers', [
                ['min' => 50, 'role' => 'premium_lifetime', 'months' => null],
                ['min' => 10, 'role' => 'premium_pro',      'months' => 1],
                ['min' => 5,  'role' => 'premium_basic',    'months' => 1],
            ]);
            $all_roles = WPA_Roles::get_all_defined_roles();
            foreach ($tiers as $i => $tier) : ?>
                <div class="wpa-tier-row">
                    <span><?php esc_html_e('Min', WPA_TEXT_DOMAIN); ?></span>
                    <input type="number" name="wpa_kofi_tiers[<?php echo $i; ?>][min]"
                           value="<?php echo esc_attr($tier['min']); ?>" min="0" step="1" style="width:70px" /> &euro;
                    <span><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></span>
                    <select name="wpa_kofi_tiers[<?php echo $i; ?>][role]">
                        <?php foreach ($all_roles as $r) : ?>
                        <option value="<?php echo esc_attr($r); ?>" <?php selected($r, $tier['role']); ?>><?php echo esc_html($r); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span><?php esc_html_e('Duration', WPA_TEXT_DOMAIN); ?></span>
                    <input type="number" name="wpa_kofi_tiers[<?php echo $i; ?>][months]"
                           value="<?php echo esc_attr($tier['months'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('months', WPA_TEXT_DOMAIN); ?>"
                           min="1" style="width:70px" />
                    <span class="description"><?php esc_html_e('(empty = lifetime)', WPA_TEXT_DOMAIN); ?></span>
                    <button type="button" class="button wpa-remove-tier">&times;</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wpa-add-tier">
                + <?php esc_html_e('Add tier', WPA_TEXT_DOMAIN); ?>
            </button>

            <hr />

            <?php /* ---- Ko-fi tier name mapping ---- */ ?>
            <h2><?php esc_html_e('Ko-fi Membership Tier Names', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('Map Ko-fi membership tier names (exact match) to roles. Takes priority over amount tiers.', WPA_TEXT_DOMAIN); ?></p>
            <div id="wpa-tiernames-wrap">
            <?php
            $tier_names = get_option('wpa_kofi_tier_names', []);
            foreach ($tier_names as $name => $cfg) : ?>
                <div class="wpa-tier-row">
                    <span><?php esc_html_e('Tier name', WPA_TEXT_DOMAIN); ?></span>
                    <input type="text" name="wpa_kofi_tier_names[<?php echo esc_attr($name); ?>][_name]"
                           value="<?php echo esc_attr($name); ?>" class="wpa-tier-name-key" style="width:160px"
                           placeholder="Gold Member" />
                    <span><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></span>
                    <select name="wpa_kofi_tier_names[<?php echo esc_attr($name); ?>][role]">
                        <?php foreach ($all_roles as $r) : ?>
                        <option value="<?php echo esc_attr($r); ?>" <?php selected($r, $cfg['role']); ?>><?php echo esc_html($r); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span><?php esc_html_e('Duration', WPA_TEXT_DOMAIN); ?></span>
                    <input type="number" name="wpa_kofi_tier_names[<?php echo esc_attr($name); ?>][months]"
                           value="<?php echo esc_attr($cfg['months'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('months', WPA_TEXT_DOMAIN); ?>"
                           min="1" style="width:70px" />
                    <span class="description"><?php esc_html_e('(empty = lifetime)', WPA_TEXT_DOMAIN); ?></span>
                    <button type="button" class="button wpa-remove-tier">&times;</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wpa-add-tiername">
                + <?php esc_html_e('Add tier name mapping', WPA_TEXT_DOMAIN); ?>
            </button>

            <hr />

            <?php /* ---- Shop items ---- */ ?>
            <h2><?php esc_html_e('Ko-fi Shop Items', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('Map Ko-fi shop item direct_link_code to roles.', WPA_TEXT_DOMAIN); ?></p>
            <div id="wpa-shop-wrap">
            <?php
            $shop_items = get_option('wpa_shop_items', []);
            foreach ($shop_items as $code => $cfg) : ?>
                <div class="wpa-tier-row">
                    <span><?php esc_html_e('Code', WPA_TEXT_DOMAIN); ?></span>
                    <input type="text" name="wpa_shop_items[<?php echo esc_attr($code); ?>][_code]"
                           value="<?php echo esc_attr($code); ?>" style="width:140px"
                           placeholder="abc123" />
                    <span><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></span>
                    <select name="wpa_shop_items[<?php echo esc_attr($code); ?>][role]">
                        <?php foreach ($all_roles as $r) : ?>
                        <option value="<?php echo esc_attr($r); ?>" <?php selected($r, $cfg['role']); ?>><?php echo esc_html($r); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span><?php esc_html_e('Duration', WPA_TEXT_DOMAIN); ?></span>
                    <input type="number" name="wpa_shop_items[<?php echo esc_attr($code); ?>][months]"
                           value="<?php echo esc_attr($cfg['months'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('months', WPA_TEXT_DOMAIN); ?>"
                           min="1" style="width:70px" />
                    <span class="description"><?php esc_html_e('(empty = lifetime)', WPA_TEXT_DOMAIN); ?></span>
                    <button type="button" class="button wpa-remove-tier">&times;</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="wpa-add-shop">
                + <?php esc_html_e('Add shop item', WPA_TEXT_DOMAIN); ?>
            </button>

            <p style="margin-top:16px">
                <?php submit_button(__('Save Ko-fi mappings', WPA_TEXT_DOMAIN), 'primary', 'submit', false); ?>
            </p>

            </form>

            <hr />

            <?php /* ---- Manual grant ---- */ ?>
            <h2><?php esc_html_e('Grant a role manually', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('If the email does not exist, a new account is created automatically.', WPA_TEXT_DOMAIN); ?></p>
            <form method="post">
                <?php wp_nonce_field('wpa_grant_role', 'wpa_grant_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="email" name="grant_email" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select name="grant_role">
                                <?php foreach ($all_roles as $role) : ?>
                                    <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Expires at', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="date" name="grant_expires" />
                            <p class="description"><?php esc_html_e('Leave empty for lifetime access.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Grant role', WPA_TEXT_DOMAIN)); ?>
            </form>

            <hr />

            <?php /* ---- Webhook simulator ---- */ ?>
            <h2><?php esc_html_e('Webhook Simulator', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('Send a simulated Ko-fi payload to your webhook endpoint for testing.', WPA_TEXT_DOMAIN); ?></p>
            <div class="wpa-simulator">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Type', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select id="sim-type">
                                <option value="Donation">Donation (one-shot)</option>
                                <option value="Subscription">Subscription (first payment)</option>
                                <option value="Subscription_renewal">Subscription (renewal)</option>
                                <option value="Shop Order">Shop Order</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="sim-tiername-row" style="display:none">
                        <th><?php esc_html_e('Tier name', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" id="sim-tiername" class="regular-text" placeholder="Gold Member" /></td>
                    </tr>
                    <tr id="sim-shopcode-row" style="display:none">
                        <th><?php esc_html_e('Shop item code', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" id="sim-shopcode" class="regular-text" placeholder="abc123" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="email" id="sim-email" class="regular-text" placeholder="test@example.com" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Amount', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="number" id="sim-amount" value="10" min="1" step="1" style="width:80px" />
                            <span class="description"> EUR</span>
                        </td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="wpa-simulate-btn">
                    <?php esc_html_e('Send simulated webhook', WPA_TEXT_DOMAIN); ?>
                </button>
                <span id="wpa-sim-feedback" style="margin-left:1em;font-weight:500"></span>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Roles management page
    // =========================================================================

    public static function render_roles(): void {
        if (!current_user_can('manage_options')) return;

        $all_roles   = WPA_Roles::get_all_defined_roles();
        $active_role = sanitize_key($_GET['role'] ?? ($all_roles[0] ?? 'premium_basic'));
        $all_perms   = WPA_Roles::ALL_PERMISSIONS;
        $all_caps    = WPA_Roles::get_all_capabilities(); // from DB
        $role_perms  = WPA_Roles::get_role_permissions($active_role);
        $is_builtin  = array_key_exists($active_role, WPA_Roles::BUILTIN_PERMISSIONS);

        // Users with this role
        global $wpdb;
        $role_users = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, r.expires_at, u.user_email
             FROM {$wpdb->prefix}wpa_roles r
             JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE r.role = %s
             ORDER BY r.granted_at DESC",
            $active_role
        ));
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Roles & Capabilities', WPA_TEXT_DOMAIN); ?></h1>
            <p class="description">
                <?php esc_html_e('Create roles, assign permissions, map to WordPress capabilities, manage users.', WPA_TEXT_DOMAIN); ?>
            </p>

            <div class="wpa-roles-layout">

                <?php /* ===== SIDEBAR ===== */ ?>
                <div class="wpa-roles-sidebar">
                    <div class="wpa-roles-sidebar__header">
                        <?php esc_html_e('Roles', WPA_TEXT_DOMAIN); ?>
                        <button class="wpa-sidebar-add" id="wpa-show-new-role" title="<?php esc_attr_e('New role', WPA_TEXT_DOMAIN); ?>">+</button>
                    </div>
                    <?php foreach ($all_roles as $role) :
                        $is_active  = ($role === $active_role);
                        $is_bi      = array_key_exists($role, WPA_Roles::BUILTIN_PERMISSIONS);
                        $role_url   = add_query_arg(['page' => 'wpa-roles', 'role' => $role], admin_url('admin.php'));
                    ?>
                    <a href="<?php echo esc_url($role_url); ?>"
                       class="wpa-role-item<?php echo $is_active ? ' active' : ''; ?>">
                        <span><?php echo esc_html($role); ?></span>
                        <span class="wpa-role-item__badges">
                            <?php if ($is_bi) : ?>
                                <span class="wpa-tag wpa-tag--builtin"><?php esc_html_e('built-in', WPA_TEXT_DOMAIN); ?></span>
                            <?php else : ?>
                                <span class="wpa-tag wpa-tag--custom"><?php esc_html_e('custom', WPA_TEXT_DOMAIN); ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                    <?php endforeach; ?>

                    <?php /* New role inline form */ ?>
                    <div class="wpa-new-role-form" id="wpa-new-role-form" style="margin:0;border-radius:0;border-left:none;border-right:none;border-bottom:none">
                        <label><?php esc_html_e('Role slug', WPA_TEXT_DOMAIN); ?></label>
                        <input type="text" id="new-role-slug" placeholder="premium_partner"
                               pattern="[a-z0-9_]+" style="font-family:monospace;font-size:.8rem" />
                        <div style="display:flex;gap:6px">
                            <button class="wpa-btn wpa-btn--primary wpa-btn--sm" id="wpa-create-role">
                                <?php esc_html_e('Create', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <button class="wpa-btn wpa-btn--ghost wpa-btn--sm" id="wpa-cancel-new-role">
                                <?php esc_html_e('Cancel', WPA_TEXT_DOMAIN); ?>
                            </button>
                        </div>
                        <span id="wpa-create-feedback" class="wpa-feedback" style="display:block;margin-top:6px"></span>
                    </div>
                </div>

                <?php /* ===== ROLE PANEL ===== */ ?>
                <div class="wpa-role-panel">
                    <div class="wpa-role-panel__header">
                        <div class="wpa-role-panel__title">
                            <?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?>
                            <code><?php echo esc_html($active_role); ?></code>
                            <?php if ($is_builtin) : ?>
                                <span class="wpa-tag wpa-tag--builtin"><?php esc_html_e('built-in', WPA_TEXT_DOMAIN); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="wpa-role-panel__actions">
                            <?php if (!$is_builtin) : ?>
                            <button class="wpa-btn wpa-btn--danger" id="wpa-delete-role"
                                    data-role="<?php echo esc_attr($active_role); ?>">
                                <?php esc_html_e('Delete role', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <?php endif; ?>
                            <button class="wpa-btn wpa-btn--primary" id="wpa-save-role"
                                    data-role="<?php echo esc_attr($active_role); ?>">
                                <?php esc_html_e('Save', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <span id="wpa-save-feedback" class="wpa-feedback"></span>
                        </div>
                    </div>

                    <div class="wpa-tabs">
                        <div class="wpa-tab active" data-tab="permissions">
                            <?php esc_html_e('Permissions', WPA_TEXT_DOMAIN); ?>
                        </div>
                        <div class="wpa-tab" data-tab="capabilities">
                            <?php esc_html_e('WP Capabilities', WPA_TEXT_DOMAIN); ?>
                        </div>
                        <div class="wpa-tab" data-tab="users">
                            <?php printf(esc_html__('Users (%d)', WPA_TEXT_DOMAIN), count($role_users)); ?>
                        </div>
                    </div>

                    <?php /* --- Tab: Permissions --- */ ?>
                    <div class="wpa-tab-content active" id="tab-permissions">
                        <div class="wpa-info-box">
                            <?php esc_html_e('WPA check:', WPA_TEXT_DOMAIN); ?>
                            <code>WPA_Roles::can($user_id, 'export_md')</code>
                            &nbsp;&middot;&nbsp;
                            <?php esc_html_e('WP check:', WPA_TEXT_DOMAIN); ?>
                            <code>current_user_can('wpa_export_md')</code>
                        </div>

                        <?php if ($active_role === 'admin') : ?>
                            <p><em><?php esc_html_e('Admin has all permissions (*) by default.', WPA_TEXT_DOMAIN); ?></em></p>
                        <?php else : ?>
                        <div class="wpa-perm-grid" id="wpa-perm-grid">
                            <?php foreach ($all_caps as $cap) :
                                $checked = in_array($cap->slug, $role_perms, true);
                            ?>
                            <label class="wpa-perm-card<?php echo $checked ? ' is-checked' : ''; ?>">
                                <input type="checkbox"
                                       class="wpa-perm-input"
                                       data-perm="<?php echo esc_attr($cap->slug); ?>"
                                       <?php checked($checked); ?> />
                                <div class="wpa-perm-card__body">
                                    <div class="wpa-perm-card__name"><?php echo esc_html($cap->label ?: $cap->slug); ?></div>
                                    <div class="wpa-perm-card__cap">&rarr; <?php echo esc_html($cap->wp_cap); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php /* --- Tab: WP Capabilities --- */ ?>
                    <div class="wpa-tab-content" id="tab-capabilities">
                        <p class="description" style="margin-bottom:14px">
                            <?php esc_html_e('WordPress capability names generated for this role active permissions.', WPA_TEXT_DOMAIN); ?>
                        </p>
                        <table class="wpa-cap-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Permission', WPA_TEXT_DOMAIN); ?></th>
                                    <th><?php esc_html_e('WP Capability', WPA_TEXT_DOMAIN); ?></th>
                                    <th><?php esc_html_e('Active', WPA_TEXT_DOMAIN); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_caps as $cap) :
                                    $active = in_array($cap->slug, $role_perms, true);
                                ?>
                                <tr style="<?php echo $active ? '' : 'opacity:.45'; ?>">
                                    <td><?php echo esc_html($cap->label ?: $cap->slug); ?></td>
                                    <td><code><?php echo esc_html($cap->wp_cap); ?></code></td>
                                    <td>
                                        <?php if ($active) : ?>
                                            <span style="color:#16a34a;font-weight:600">&#10003;</span>
                                        <?php else : ?>
                                            <span style="color:#d1d5db">&#8212;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="margin-top:14px;display:flex;gap:8px;align-items:center">
                            <button type="button" class="wpa-btn wpa-btn--ghost wpa-btn--sm" id="wpa-resync-caps">
                                <?php esc_html_e('Resync all users', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <span id="wpa-resync-feedback" class="wpa-feedback"></span>
                        </div>
                    </div>

                    <?php /* --- Tab: Users --- */ ?>
                    <div class="wpa-tab-content" id="tab-users">
                        <?php if (empty($role_users)) : ?>
                            <p class="description"><?php esc_html_e('No users with this role yet.', WPA_TEXT_DOMAIN); ?></p>
                        <?php else : ?>
                        <div class="wpa-users-list">
                            <?php foreach ($role_users as $ru) :
                                $initials = strtoupper(substr($ru->user_email, 0, 2));
                                $expired  = $ru->expires_at && strtotime($ru->expires_at) < time();
                                $soon     = !$expired && $ru->expires_at && strtotime($ru->expires_at) < strtotime('+7 days');
                            ?>
                            <div class="wpa-user-row">
                                <div class="wpa-avatar"><?php echo esc_html($initials); ?></div>
                                <span class="wpa-user-email"><?php echo esc_html($ru->user_email); ?></span>
                                <span class="wpa-user-expiry<?php echo $expired ? ' wpa-expiry--expired' : ($soon ? ' wpa-expiry--soon' : ($ru->expires_at ? '' : ' wpa-expiry--life')); ?>">
                                    <?php if (!$ru->expires_at) : ?>
                                        <?php esc_html_e('Lifetime', WPA_TEXT_DOMAIN); ?>
                                    <?php elseif ($expired) : ?>
                                        <?php printf(esc_html__('Expired %s', WPA_TEXT_DOMAIN), esc_html(date_i18n(get_option('date_format'), strtotime($ru->expires_at)))); ?>
                                    <?php else : ?>
                                        <?php printf(esc_html__('Expires %s', WPA_TEXT_DOMAIN), esc_html(date_i18n(get_option('date_format'), strtotime($ru->expires_at)))); ?>
                                    <?php endif; ?>
                                </span>
                                <button class="wpa-btn wpa-btn--danger wpa-revoke-user"
                                        data-user-id="<?php echo esc_attr($ru->user_id); ?>"
                                        data-role="<?php echo esc_attr($active_role); ?>">
                                    <?php esc_html_e('Revoke', WPA_TEXT_DOMAIN); ?>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="wpa-grant-row">
                            <input type="email" id="grant-email"
                                   placeholder="<?php esc_attr_e('Email address', WPA_TEXT_DOMAIN); ?>" />
                            <input type="date"  id="grant-expires"
                                   title="<?php esc_attr_e('Expiry date — leave empty for lifetime', WPA_TEXT_DOMAIN); ?>" />
                            <button class="wpa-btn wpa-btn--primary" id="wpa-grant-user"
                                    data-role="<?php echo esc_attr($active_role); ?>">
                                <?php esc_html_e('Grant', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <span id="wpa-grant-feedback" class="wpa-feedback"></span>
                        </div>
                    </div>

                </div><!-- .wpa-role-panel -->
            </div><!-- .wpa-roles-layout -->

            <?php /* ===== CAPABILITIES SECTION ===== */ ?>
            <hr style="margin:32px 0" />
            <h2><?php esc_html_e('Available Capabilities', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description">
                <?php esc_html_e('All capabilities that can be assigned to roles. Built-in capabilities cannot be deleted. Custom capabilities appear in the Permissions tab of each role.', WPA_TEXT_DOMAIN); ?>
            </p>

            <table class="wp-list-table widefat fixed striped" style="max-width:700px;margin-bottom:16px">
                <thead>
                    <tr>
                        <th style="width:150px"><?php esc_html_e('Slug', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Label', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:180px"><?php esc_html_e('WP Capability', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:80px"><?php esc_html_e('Type', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_caps as $cap) : ?>
                    <tr>
                        <td><code><?php echo esc_html($cap->slug); ?></code></td>
                        <td>
                            <?php if (!$cap->is_builtin) : ?>
                            <input type="text"
                                   class="wpa-cap-label-edit"
                                   data-slug="<?php echo esc_attr($cap->slug); ?>"
                                   value="<?php echo esc_attr($cap->label); ?>"
                                   style="width:100%;font-size:.85rem" />
                            <?php else : ?>
                            <?php echo esc_html($cap->label); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$cap->is_builtin) : ?>
                            <input type="text"
                                   class="wpa-cap-wpcap-edit"
                                   data-slug="<?php echo esc_attr($cap->slug); ?>"
                                   value="<?php echo esc_attr($cap->wp_cap); ?>"
                                   style="width:100%;font-family:monospace;font-size:.8rem" />
                            <?php else : ?>
                            <code><?php echo esc_html($cap->wp_cap); ?></code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cap->is_builtin) : ?>
                                <span class="wpa-tag wpa-tag--builtin"><?php esc_html_e('built-in', WPA_TEXT_DOMAIN); ?></span>
                            <?php else : ?>
                                <span class="wpa-tag wpa-tag--custom"><?php esc_html_e('custom', WPA_TEXT_DOMAIN); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$cap->is_builtin) : ?>
                            <button class="wpa-btn wpa-btn--ghost wpa-btn--sm wpa-save-cap"
                                    data-slug="<?php echo esc_attr($cap->slug); ?>"
                                    style="margin-right:4px">
                                <?php esc_html_e('Save', WPA_TEXT_DOMAIN); ?>
                            </button>
                            <button class="wpa-btn wpa-btn--danger wpa-delete-cap"
                                    data-slug="<?php echo esc_attr($cap->slug); ?>">
                                &times;
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="wpa-new-cap-form" id="wpa-new-cap-form"
                 style="background:#f9fafb;border:1px solid #e0e0e0;border-radius:7px;padding:14px 16px;max-width:700px">
                <strong style="display:block;margin-bottom:10px;font-size:.875rem">
                    <?php esc_html_e('Add a new capability', WPA_TEXT_DOMAIN); ?>
                </strong>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                    <div>
                        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:3px">
                            <?php esc_html_e('Slug', WPA_TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" id="new-cap-slug" placeholder="beta_access"
                               style="font-family:monospace;font-size:.85rem;width:160px" />
                    </div>
                    <div>
                        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:3px">
                            <?php esc_html_e('Label', WPA_TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" id="new-cap-label" placeholder="Beta access"
                               style="font-size:.85rem;width:180px" />
                    </div>
                    <div>
                        <label style="font-size:.8rem;color:#6b7280;display:block;margin-bottom:3px">
                            <?php esc_html_e('WP Capability', WPA_TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" id="new-cap-wpcap" placeholder="wpa_beta_access"
                               style="font-family:monospace;font-size:.85rem;width:180px" />
                    </div>
                    <button class="wpa-btn wpa-btn--primary" id="wpa-create-cap">
                        <?php esc_html_e('Create', WPA_TEXT_DOMAIN); ?>
                    </button>
                    <span id="wpa-cap-feedback" class="wpa-feedback"></span>
                </div>
                <p class="description" style="margin-top:8px">
                    <?php esc_html_e('The wpa_ prefix is added automatically. After creating, assign it to roles via the Permissions tab.', WPA_TEXT_DOMAIN); ?>
                </p>
            </div>

        </div>
        <?php
    }

    // Remove old render_capabilities — now merged into render_roles
    // The wpa-capabilities submenu redirects here

    public static function render_webhook_log(): void {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wpa_webhook_log ORDER BY received_at DESC LIMIT 200"
        );
        $selected = isset($_GET['log_id']) ? (int) $_GET['log_id'] : 0;
        $detail   = null;
        if ($selected) {
            $detail = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wpa_webhook_log WHERE id = %d", $selected
            ));
        }
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — Webhook Log', WPA_TEXT_DOMAIN); ?></h1>

            <div style="display:grid;grid-template-columns:1fr <?php echo $detail ? '420px' : '0'; ?>;gap:20px;align-items:start">

            <!-- Left: log table -->
            <div>
            <p class="description"><?php esc_html_e('Last 200 Ko-fi &amp; Stripe events. Click a row to inspect payload.', WPA_TEXT_DOMAIN); ?></p>

            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No webhook events yet.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped wpa-log-table" style="table-layout:auto">
                <thead>
                    <tr>
                        <th style="width:145px"><?php esc_html_e('Received', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:100px"><?php esc_html_e('Type', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:65px"><?php esc_html_e('Amount', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:120px"><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:65px"><?php esc_html_e('Status', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Note', WPA_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) :
                        $row_url    = add_query_arg(['page' => 'wpa-webhook-log', 'log_id' => $log->id], admin_url('admin.php'));
                        $is_active  = ($log->id === $selected);
                        $is_error   = in_array($log->status, ['error','unauthorized','invalid'], true);
                    ?>
                    <tr class="wpa-log-row wpa-log-row--<?php echo esc_attr($log->status); ?>"
                        style="cursor:pointer;<?php echo $is_active ? 'background:#eef2ff!important;' : ''; ?>"
                        onclick="window.location='<?php echo esc_url($row_url); ?>'"
                    >
                        <td style="font-size:.82rem"><?php echo esc_html($log->received_at); ?></td>
                        <td><code style="font-size:.78rem"><?php echo esc_html($log->type); ?></code></td>
                        <td style="font-size:.82rem"><?php echo esc_html($log->email); ?></td>
                        <td style="font-size:.82rem"><?php echo $log->amount > 0 ? esc_html(number_format((float)$log->amount, 2)) . ' &euro;' : '—'; ?></td>
                        <td><?php echo $log->role_granted ? '<code style="font-size:.75rem">' . esc_html($log->role_granted) . '</code>' : '—'; ?></td>
                        <td>
                            <span class="wpa-status wpa-status--<?php echo esc_attr($log->status); ?>">
                                <?php echo esc_html($log->status); ?>
                            </span>
                        </td>
                        <td style="font-size:.82rem;color:<?php echo $is_error ? '#dc2626' : 'inherit'; ?>">
                            <?php echo esc_html($log->note); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>

            <!-- Right: payload inspector -->
            <?php if ($detail) : ?>
            <div class="wpa-payload-inspector" style="position:sticky;top:32px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <strong><?php echo esc_html($detail->type); ?> — <?php echo esc_html($detail->received_at); ?></strong>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'wpa-webhook-log'], admin_url('admin.php'))); ?>"
                       style="font-size:.8rem">&times; <?php esc_html_e('Close', WPA_TEXT_DOMAIN); ?></a>
                </div>

                <!-- Summary -->
                <table class="widefat" style="margin-bottom:12px;font-size:.82rem">
                    <tr><th style="width:100px"><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th><td><?php echo esc_html($detail->email); ?></td></tr>
                    <tr><th><?php esc_html_e('Amount', WPA_TEXT_DOMAIN); ?></th><td><?php echo esc_html(number_format((float)$detail->amount, 2)); ?> &euro;</td></tr>
                    <tr><th><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></th><td><?php echo $detail->role_granted ? '<code>' . esc_html($detail->role_granted) . '</code>' : '—'; ?></td></tr>
                    <tr><th><?php esc_html_e('Status', WPA_TEXT_DOMAIN); ?></th>
                        <td><span class="wpa-status wpa-status--<?php echo esc_attr($detail->status); ?>"><?php echo esc_html($detail->status); ?></span></td>
                    </tr>
                    <?php if ($detail->note) : ?>
                    <tr><th><?php esc_html_e('Note', WPA_TEXT_DOMAIN); ?></th>
                        <td style="color:<?php echo in_array($detail->status, ['error','unauthorized'], true) ? '#dc2626' : 'inherit'; ?>">
                            <?php echo esc_html($detail->note); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <!-- Raw payload -->
                <strong style="display:block;margin-bottom:6px;font-size:.82rem"><?php esc_html_e('Raw payload', WPA_TEXT_DOMAIN); ?></strong>
                <?php if ($detail->payload) :
                    $pretty = $detail->payload;
                    $decoded = json_decode($pretty, true);
                    if ($decoded) $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                ?>
                <pre class="wpa-payload-pre"><?php echo esc_html($pretty); ?></pre>
                <button type="button" class="button button-small" style="margin-top:6px"
                        onclick="navigator.clipboard.writeText(<?php echo esc_attr(wp_json_encode($pretty)); ?>).then(()=>this.textContent='Copied!')">
                    <?php esc_html_e('Copy', WPA_TEXT_DOMAIN); ?>
                </button>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('No payload recorded.', WPA_TEXT_DOMAIN); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            </div><!-- grid -->
        </div>

        <style>
        .wpa-payload-inspector {
            background:    #fff;
            border:        1px solid #e0e0e0;
            border-radius: 6px;
            padding:       16px;
        }
        .wpa-payload-pre {
            background:    #f3f4f6;
            border:        1px solid #e5e7eb;
            border-radius: 4px;
            padding:       10px;
            font-size:     .75rem;
            overflow:      auto;
            max-height:    500px;
            white-space:   pre-wrap;
            word-break:    break-all;
            margin:        0;
        }
        </style>
        <?php
    }

    // =========================================================================
    // Users page
    // =========================================================================

    public static function render_users(): void {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT r.user_id, u.user_email,
                    GROUP_CONCAT(r.role ORDER BY r.granted_at DESC SEPARATOR ', ') as roles,
                    MAX(r.granted_at) as last_granted,
                    MIN(r.expires_at) as next_expiry
             FROM {$wpdb->prefix}wpa_roles r
             JOIN {$wpdb->users} u ON u.ID = r.user_id
             GROUP BY r.user_id, u.user_email
             ORDER BY last_granted DESC
             LIMIT 100"
        );
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Users', WPA_TEXT_DOMAIN); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Roles', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Last granted', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Next expiry', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) :
                        $detail_url = add_query_arg(['page' => 'wpa-user-detail', 'user_id' => $row->user_id], admin_url('admin.php'));
                        $expired    = $row->next_expiry && strtotime($row->next_expiry) < time();
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($row->user_email); ?></strong></td>
                        <td>
                            <?php foreach (explode(', ', $row->roles) as $r) : ?>
                            <code style="font-size:.78rem"><?php echo esc_html($r); ?></code>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html($row->last_granted); ?></td>
                        <td style="color:<?php echo $expired ? '#dc2626' : 'inherit'; ?>">
                            <?php echo $row->next_expiry
                                ? esc_html($row->next_expiry)
                                : '<em>' . esc_html__('Lifetime', WPA_TEXT_DOMAIN) . '</em>'; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($detail_url); ?>" class="button button-small">
                                <?php esc_html_e('Detail', WPA_TEXT_DOMAIN); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function render_user_detail(): void {
        if (!current_user_can('manage_options')) return;

        $user_id = (int) ($_GET['user_id'] ?? 0);
        if (!$user_id) wp_die('Invalid user');

        $user = get_user_by('ID', $user_id);
        if (!$user) wp_die('User not found');

        global $wpdb;

        // Handle reset
        if (isset($_POST['wpa_reset_nonce']) && wp_verify_nonce($_POST['wpa_reset_nonce'], 'wpa_reset_' . $user_id)) {
            WPA_Sessions::revoke_all($user_id);
            wp_destroy_other_sessions(); // won't work for other user but we handle via our table
            // Force WP session destroy for this user
            $sessions = WP_Session_Tokens::get_instance($user_id);
            $sessions->destroy_all();
            echo '<div class="notice notice-success"><p>'
                . esc_html__('All sessions revoked. User must sign in again.', WPA_TEXT_DOMAIN)
                . '</p></div>';
        }

        $roles    = WPA_Roles::get_roles($user_id);
        $sessions = WPA_Sessions::get_active($user_id);
        $history  = $wpdb->get_results($wpdb->prepare(
            "SELECT logged_in_at, ip, user_agent
             FROM {$wpdb->prefix}wpa_login_history
             WHERE user_id = %d
             ORDER BY logged_in_at DESC
             LIMIT 30",
            $user_id
        ));

        $back_url = add_query_arg('page', 'wpa-users', admin_url('admin.php'));
        ?>
        <div class="wrap wpa-wrap">
            <h1>
                <a href="<?php echo esc_url($back_url); ?>">&larr;</a>
                <?php echo esc_html($user->user_email); ?>
            </h1>

            <?php /* --- Roles --- */ ?>
            <h2><?php esc_html_e('Roles', WPA_TEXT_DOMAIN); ?></h2>
            <?php if (empty($roles)) : ?>
                <p class="description"><?php esc_html_e('No active roles.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed" style="max-width:600px">
                <thead><tr>
                    <th><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></th>
                    <th><?php esc_html_e('Expires', WPA_TEXT_DOMAIN); ?></th>
                </tr></thead>
                <tbody>
                <?php
                $role_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT role, expires_at FROM {$wpdb->prefix}wpa_roles WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
                    $user_id
                ));
                foreach ($role_rows as $rr) : ?>
                <tr>
                    <td><code><?php echo esc_html($rr->role); ?></code></td>
                    <td><?php echo $rr->expires_at ? esc_html($rr->expires_at) : '<em>' . esc_html__('Lifetime', WPA_TEXT_DOMAIN) . '</em>'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php /* --- Active sessions + Reset --- */ ?>
            <h2 style="margin-top:24px"><?php esc_html_e('Active sessions', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description">
                <?php printf(esc_html__('%d / 3 active sessions', WPA_TEXT_DOMAIN), count($sessions)); ?>
            </p>
            <?php if (!empty($sessions)) : ?>
            <table class="wp-list-table widefat fixed" style="max-width:600px;margin-bottom:12px">
                <thead><tr>
                    <th><?php esc_html_e('Created', WPA_TEXT_DOMAIN); ?></th>
                    <th><?php esc_html_e('Expires', WPA_TEXT_DOMAIN); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sessions as $s) : ?>
                <tr>
                    <td><?php echo esc_html($s->created_at); ?></td>
                    <td><?php echo esc_html($s->expires_at); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('wpa_reset_' . $user_id, 'wpa_reset_nonce'); ?>
                <button type="submit" class="button button-primary"
                        onclick="return confirm('<?php esc_attr_e('Revoke ALL sessions? The user will need to sign in again on all devices.', WPA_TEXT_DOMAIN); ?>')">
                    <?php esc_html_e('Revoke all sessions (all devices)', WPA_TEXT_DOMAIN); ?>
                </button>
            </form>

            <?php /* --- Login history --- */ ?>
            <h2 style="margin-top:28px"><?php esc_html_e('Login history (last 30)', WPA_TEXT_DOMAIN); ?></h2>
            <?php if (empty($history)) : ?>
                <p class="description"><?php esc_html_e('No login history yet.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed" style="max-width:800px">
                <thead><tr>
                    <th><?php esc_html_e('Date', WPA_TEXT_DOMAIN); ?></th>
                    <th style="width:130px"><?php esc_html_e('IP', WPA_TEXT_DOMAIN); ?></th>
                    <th><?php esc_html_e('Browser / Device', WPA_TEXT_DOMAIN); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $h) : ?>
                <tr>
                    <td><?php echo esc_html($h->logged_in_at); ?></td>
                    <td><code><?php echo esc_html($h->ip); ?></code></td>
                    <td style="font-size:.8rem;color:#6b7280"><?php echo esc_html($h->user_agent); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function ajax_reset_user_sessions(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $user_id = (int) ($_POST['user_id'] ?? 0);
        if (!$user_id) wp_send_json_error('Invalid user');

        WPA_Sessions::revoke_all($user_id);
        $wp_sessions = WP_Session_Tokens::get_instance($user_id);
        $wp_sessions->destroy_all();

        wp_send_json_success(['revoked' => true]);
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    public static function ajax_save_role(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $role  = sanitize_key($_POST['role'] ?? '');
        $perms = array_map('sanitize_key', (array) ($_POST['permissions'] ?? []));

        if (empty($role)) wp_send_json_error('Invalid role');

        WPA_Roles::save_role($role, $perms);
        WPA_Capabilities::sync_wp_roles(); // keep WP native roles in sync
        wp_send_json_success(['role' => $role, 'permissions' => $perms]);
    }

    public static function ajax_delete_role(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $role = sanitize_key($_POST['role'] ?? '');
        if (empty($role) || array_key_exists($role, WPA_Roles::BUILTIN_PERMISSIONS)) {
            wp_send_json_error('Cannot delete built-in role');
        }

        WPA_Roles::delete_role($role);
        wp_send_json_success(['deleted' => $role]);
    }

    /**
     * Simulate a Ko-fi webhook payload — sends real request to own endpoint.
     * Bypasses Ko-fi token verification when is_simulation flag is set.
     */
    public static function ajax_simulate_webhook(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $type      = sanitize_text_field($_POST['type']      ?? 'Donation');
        $tiername  = sanitize_text_field($_POST['tiername'] ?? '');
        $shopcode  = sanitize_text_field($_POST['shopcode'] ?? '');
        $is_renewal = ($type === 'Subscription_renewal');
        if ($is_renewal) $type = 'Subscription';
        $email  = sanitize_email($_POST['email']       ?? '');
        $amount = (float) ($_POST['amount']            ?? 10);

        if (!is_email($email)) wp_send_json_error('Invalid email');

        $payload = [
            'type'                            => $type,
            'email'                           => $email,
            'amount'                          => (string) $amount,
            'currency'                        => 'EUR',
            'tier_name'                       => $tiername,
            'is_first_subscription_payment'   => !$is_renewal,
            'is_subscription_payment'         => ($type === 'Subscription'),
            'shop_items'                      => $shopcode ? [['direct_link_code' => $shopcode]] : [],
            'verification_token'              => get_option('wpa_kofi_token', ''),
            '_simulation'                     => true,
        ];

        // Call the webhook handler directly (no HTTP round-trip, avoids SSL issues)
        // Simulate a WP_REST_Request object
        $request = new \WP_REST_Request('POST', '/wpa/v1/kofi-webhook');
        $request->set_body_params(['data' => wp_json_encode($payload)]);
        $request->set_param('data', wp_json_encode($payload));

        $result = WPA_KofiWebhook::handle($request);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $data = $result->get_data();
        $code = $result->get_status();

        if ($code === 200) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error($data);
        }
    }

    // =========================================================================
    // Emails page
    // =========================================================================

    public static function render_emails(): void {
        if (!current_user_can('manage_options')) return;

        $all_roles    = WPA_Roles::get_all_defined_roles();
        $campaign_log = get_option('wpa_email_campaign_log', []);
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — Emails', WPA_TEXT_DOMAIN); ?></h1>

            <?php /* ---- Global settings ---- */ ?>
            <form method="post" action="options.php">
                <?php settings_fields('wpa_email_settings'); ?>
                <h2><?php esc_html_e('Sender & appearance', WPA_TEXT_DOMAIN); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('From name', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" name="wpa_email_from_name"
                                   value="<?php echo esc_attr(get_option('wpa_email_from_name', get_bloginfo('name'))); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('From email', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="email" name="wpa_email_from_email"
                                   value="<?php echo esc_attr(get_option('wpa_email_from_email', get_option('admin_email'))); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Accent color (HTML emails)', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="color" name="wpa_email_accent_color"
                                   value="<?php echo esc_attr(get_option('wpa_email_accent_color', '#5046e5')); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Ko-fi page URL', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="url" name="wpa_kofi_url"
                                   value="<?php echo esc_attr(get_option('wpa_kofi_url', 'https://ko-fi.com')); ?>"
                                   class="regular-text" placeholder="https://ko-fi.com/yourpage" /></td>
                    </tr>
                </table>

                <?php /* ---- Welcome email ---- */ ?>
                <h2><?php esc_html_e('Welcome email', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description"><?php esc_html_e('Sent on first premium login. Variables: {{site}}, {{email}}, {{login_url}}', WPA_TEXT_DOMAIN); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Format', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select name="wpa_email_format_welcome">
                                <option value="html" <?php selected(get_option('wpa_email_format_welcome', 'html'), 'html'); ?>>HTML</option>
                                <option value="text" <?php selected(get_option('wpa_email_format_welcome', 'html'), 'text'); ?>><?php esc_html_e('Plain text', WPA_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Subject', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" name="wpa_email_subject_welcome"
                                   value="<?php echo esc_attr(get_option('wpa_email_subject_welcome', '')); ?>"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e('Leave empty to use default', WPA_TEXT_DOMAIN); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Body', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea name="wpa_email_body_welcome" rows="8" class="large-text"
                                      placeholder="<?php esc_attr_e('Leave empty to use default template', WPA_TEXT_DOMAIN); ?>"><?php
                                echo esc_textarea(get_option('wpa_email_body_welcome', ''));
                            ?></textarea>
                            <button type="button" class="button wpa-preview-email"
                                    data-type="welcome"><?php esc_html_e('Preview', WPA_TEXT_DOMAIN); ?></button>
                        </td>
                    </tr>
                </table>

                <?php /* ---- Expiry warning email ---- */ ?>
                <h2><?php esc_html_e('Expiry warning email', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description"><?php esc_html_e('Sent J-7 and J-1 before expiry. Variables: {{site}}, {{email}}, {{login_url}}, {{kofi_url}}, {{days}}, {{expires_at}}', WPA_TEXT_DOMAIN); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Format', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select name="wpa_email_format_expiry">
                                <option value="html" <?php selected(get_option('wpa_email_format_expiry', 'html'), 'html'); ?>>HTML</option>
                                <option value="text" <?php selected(get_option('wpa_email_format_expiry', 'html'), 'text'); ?>><?php esc_html_e('Plain text', WPA_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Subject', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" name="wpa_email_subject_expiry_warning"
                                   value="<?php echo esc_attr(get_option('wpa_email_subject_expiry_warning', '')); ?>"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e('Leave empty to use default', WPA_TEXT_DOMAIN); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Body', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea name="wpa_email_body_expiry_warning" rows="8" class="large-text"
                                      placeholder="<?php esc_attr_e('Leave empty to use default template', WPA_TEXT_DOMAIN); ?>"><?php
                                echo esc_textarea(get_option('wpa_email_body_expiry_warning', ''));
                            ?></textarea>
                            <button type="button" class="button wpa-preview-email"
                                    data-type="expiry_warning"><?php esc_html_e('Preview', WPA_TEXT_DOMAIN); ?></button>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save email settings', WPA_TEXT_DOMAIN)); ?>
            </form>

            <div id="wpa-email-preview-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center">
                <div style="background:#fff;width:640px;max-width:95vw;max-height:80vh;border-radius:8px;overflow:hidden;display:flex;flex-direction:column">
                    <div style="padding:.75rem 1rem;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center">
                        <strong><?php esc_html_e('Email preview', WPA_TEXT_DOMAIN); ?></strong>
                        <button type="button" id="wpa-close-preview" class="button">&times;</button>
                    </div>
                    <iframe id="wpa-preview-frame" style="flex:1;border:none;width:100%;min-height:400px"></iframe>
                </div>
            </div>

            <hr />

            <?php /* ---- Manual send ---- */ ?>
            <h2><?php esc_html_e('Send a manual email', WPA_TEXT_DOMAIN); ?></h2>
            <div class="wpa-simulator">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Target', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select id="manual-target-type">
                                <option value="role"><?php esc_html_e('All users with role', WPA_TEXT_DOMAIN); ?></option>
                                <option value="email"><?php esc_html_e('Single email address', WPA_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="manual-role-row">
                        <th><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select id="manual-role">
                                <?php foreach ($all_roles as $role) : ?>
                                <option value="<?php echo esc_attr($role); ?>"><?php echo esc_html($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="manual-email-row" style="display:none">
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="email" id="manual-email" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Format', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <select id="manual-format">
                                <option value="html">HTML</option>
                                <option value="text"><?php esc_html_e('Plain text', WPA_TEXT_DOMAIN); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Subject', WPA_TEXT_DOMAIN); ?></th>
                        <td><input type="text" id="manual-subject" class="large-text" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Body', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea id="manual-body" rows="8" class="large-text"></textarea>
                            <p class="description"><?php esc_html_e('HTML is allowed. Variables: {{site}}, {{email}}, {{login_url}}, {{kofi_url}}', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="wpa-send-manual">
                    <?php esc_html_e('Send', WPA_TEXT_DOMAIN); ?>
                </button>
                <span id="wpa-manual-feedback" style="margin-left:1em;font-weight:500"></span>
            </div>

            <?php if (!empty($campaign_log)) : ?>
            <hr />
            <h2><?php esc_html_e('Campaign log', WPA_TEXT_DOMAIN); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Target', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Subject', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Sent', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Failed', WPA_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaign_log as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html($entry['date']); ?></td>
                        <td>
                            <code><?php echo esc_html($entry['target_type']); ?></code>:
                            <?php echo esc_html($entry['target']); ?>
                        </td>
                        <td><?php echo esc_html($entry['subject']); ?></td>
                        <td style="color:#16a34a;font-weight:600"><?php echo (int) $entry['sent']; ?></td>
                        <td style="color:<?php echo $entry['failed'] > 0 ? '#dc2626' : '#9ca3af'; ?>;font-weight:600">
                            <?php echo (int) $entry['failed']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Email AJAX handlers
    // =========================================================================

    public static function ajax_send_manual_email(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $target_type = sanitize_key($_POST['target_type']    ?? 'role');
        $target      = sanitize_text_field($_POST['target']  ?? '');
        $subject     = sanitize_text_field($_POST['subject'] ?? '');
        $body        = wp_kses_post($_POST['body']           ?? '');
        $format      = sanitize_key($_POST['format']         ?? 'text');

        if (empty($subject) || empty($body) || empty($target)) {
            wp_send_json_error(__('Subject, body and target are required.', WPA_TEXT_DOMAIN));
        }

        [$sent, $failed] = WPA_Mailer::send_manual($target_type, $target, $subject, $body, $format);

        wp_send_json_success([
            'sent'   => $sent,
            'failed' => $failed,
            'message' => sprintf(
                __('%d sent, %d failed.', WPA_TEXT_DOMAIN),
                $sent, $failed
            ),
        ]);
    }

    public static function ajax_preview_email(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $type    = sanitize_key($_POST['type']   ?? 'welcome');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $body    = wp_kses_post($_POST['body']   ?? '');
        $format  = sanitize_key($_POST['format'] ?? 'html');

        // Use current admin user as preview recipient
        $user    = wp_get_current_user();

        // If custom body provided use it, else fall through to default
        if (!empty($body)) {
            $html = $format === 'html'
                ? WPA_Mailer::send($user->user_email, $subject, $body, 'html')
                : '<pre>' . esc_html($body) . '</pre>';
            // We need the rendered HTML — call wrap_html via reflection trick
            $rendered = self::render_preview_html($body, $format);
        } else {
            $rendered = self::render_preview_html('', $format, $type, $user);
        }

        wp_send_json_success(['html' => $rendered]);
    }

    private static function render_preview_html(string $body, string $format, string $type = '', ?\WP_User $user = null): string {
        if (!$user) $user = wp_get_current_user();

        // Access protected method via calling mailer directly
        // Build preview by calling send() with output buffering trick
        add_filter('wp_mail', function ($args) use (&$captured) {
            $captured = $args;
            return $args;
        });

        if (empty($body) && !empty($type)) {
            // Use default template
            $vars = $type === 'expiry_warning' ? ['days' => 7, 'expires_at' => date('Y-m-d', strtotime('+7 days'))] : [];
            ob_start();
            WPA_Mailer::send($user->user_email, 'Preview', '', $format);
            ob_end_clean();
            // Re-render directly
            $body = $type === 'expiry_warning'
                ? get_option('wpa_email_body_expiry_warning', '')
                : get_option('wpa_email_body_welcome', '');
        }

        if ($format === 'html') {
            // Inline the HTML wrapper
            if (!preg_match('/<(p|div|h[1-6])\b/i', $body)) {
                $body_out = nl2br(esc_html($body));
            } else {
                $body_out = $body;
            }
            $accent   = get_option('wpa_email_accent_color', '#5046e5');
            $site     = get_bloginfo('name');
            $site_url = home_url('/');
            return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
                <body style='margin:0;padding:16px;background:#f4f5f7;font-family:Arial,sans-serif;font-size:15px'>
                <div style='max-width:580px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden'>
                <div style='background:{$accent};padding:16px 24px;color:#fff;font-weight:700'>{$site}</div>
                <div style='padding:24px;line-height:1.7'>{$body_out}</div>
                <div style='padding:12px 24px;background:#f9f9f9;font-size:.8rem;color:#9ca3af'>&copy; {$site}</div>
                </div></body></html>";
        }

        return '<pre style="font-family:monospace;white-space:pre-wrap;padding:16px">' . esc_html($body) . '</pre>';
    }

    // =========================================================================
    // Stripe settings page
    // =========================================================================

    public static function render_stripe(): void {
        if (!current_user_can('manage_options')) return;

        $webhook_url = rest_url('wpa/v1/stripe-webhook');
        $all_roles   = WPA_Roles::get_all_defined_roles();
        $products    = get_option('wpa_stripe_products', []);
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — Stripe', WPA_TEXT_DOMAIN); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('wpa_stripe_settings'); ?>

                <?php /* ---- API Keys ---- */ ?>
                <h2><?php esc_html_e('API Keys', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description">
                    <?php esc_html_e('Found in Stripe Dashboard → Developers → API keys.', WPA_TEXT_DOMAIN); ?>
                    <?php esc_html_e('Use test keys during development.', WPA_TEXT_DOMAIN); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Publishable key', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="text" name="wpa_stripe_publishable_key"
                                   value="<?php echo esc_attr(get_option('wpa_stripe_publishable_key', '')); ?>"
                                   class="regular-text" placeholder="pk_live_..." />
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Secret key', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="password" name="wpa_stripe_secret_key"
                                   value="<?php echo esc_attr(get_option('wpa_stripe_secret_key', '')); ?>"
                                   class="regular-text" placeholder="sk_live_..." />
                            <p class="description"><?php esc_html_e('Never shared publicly.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Webhook secret', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="password" name="wpa_stripe_webhook_secret"
                                   value="<?php echo esc_attr(get_option('wpa_stripe_webhook_secret', '')); ?>"
                                   class="regular-text" placeholder="whsec_..." />
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Webhook URL', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <code class="wpa-copyable"><?php echo esc_html($webhook_url); ?></code>
                            <p class="description">
                                <?php esc_html_e('Add this URL in Stripe Dashboard → Developers → Webhooks.', WPA_TEXT_DOMAIN); ?><br>
                                <?php esc_html_e('Events to listen: checkout.session.completed, customer.subscription.*, invoice.payment_succeeded, invoice.payment_failed', WPA_TEXT_DOMAIN); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Billing portal URL', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="url" name="wpa_stripe_billing_portal_url"
                                   value="<?php echo esc_attr(get_option('wpa_stripe_billing_portal_url', '')); ?>"
                                   class="regular-text" placeholder="https://billing.stripe.com/..." />
                            <p class="description"><?php esc_html_e('Used in payment failure emails. Find it in Stripe → Customer portal.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <?php /* ---- Products ---- */ ?>
                <h2><?php esc_html_e('Products — Price ID → Role mapping', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description">
                    <?php esc_html_e('Map each Stripe Price ID to a role. Find Price IDs in Stripe Dashboard → Products.', WPA_TEXT_DOMAIN); ?><br>
                    <?php esc_html_e('Shortcode usage: [wpa_stripe_button price_id="price_xxx" label="Buy premium"]', WPA_TEXT_DOMAIN); ?>
                </p>
                <div id="wpa-stripe-products-wrap">
                <?php foreach ($products as $price_id => $cfg) : ?>
                    <div class="wpa-tier-row">
                        <span><?php esc_html_e('Price ID', WPA_TEXT_DOMAIN); ?></span>
                        <input type="text" name="wpa_stripe_products[<?php echo esc_attr($price_id); ?>][_price_id]"
                               value="<?php echo esc_attr($price_id); ?>"
                               style="width:200px" placeholder="price_xxx" />
                        <span><?php esc_html_e('Mode', WPA_TEXT_DOMAIN); ?></span>
                        <select name="wpa_stripe_products[<?php echo esc_attr($price_id); ?>][mode]">
                            <option value="payment"      <?php selected($cfg['mode'] ?? '', 'payment'); ?>><?php esc_html_e('One-time', WPA_TEXT_DOMAIN); ?></option>
                            <option value="subscription" <?php selected($cfg['mode'] ?? '', 'subscription'); ?>><?php esc_html_e('Subscription', WPA_TEXT_DOMAIN); ?></option>
                        </select>
                        <span><?php esc_html_e('Role', WPA_TEXT_DOMAIN); ?></span>
                        <select name="wpa_stripe_products[<?php echo esc_attr($price_id); ?>][role]">
                            <?php foreach ($all_roles as $r) : ?>
                            <option value="<?php echo esc_attr($r); ?>" <?php selected($cfg['role'] ?? '', $r); ?>><?php echo esc_html($r); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span><?php esc_html_e('Duration', WPA_TEXT_DOMAIN); ?></span>
                        <input type="number" name="wpa_stripe_products[<?php echo esc_attr($price_id); ?>][months]"
                               value="<?php echo esc_attr($cfg['months'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('months', WPA_TEXT_DOMAIN); ?>"
                               min="1" style="width:70px" />
                        <span class="description"><?php esc_html_e('(empty = lifetime)', WPA_TEXT_DOMAIN); ?></span>
                        <button type="button" class="button wpa-remove-tier">&times;</button>
                    </div>
                <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="wpa-add-stripe-product">
                    + <?php esc_html_e('Add product', WPA_TEXT_DOMAIN); ?>
                </button>

                <?php submit_button(__('Save Stripe settings', WPA_TEXT_DOMAIN)); ?>
            </form>
        </div>
        <?php
    }

    public static function sanitize_stripe_products($input): array {
        if (!is_array($input)) return [];
        $out = [];
        foreach ($input as $price_id => $cfg) {
            $key = sanitize_text_field($cfg['_price_id'] ?? $price_id);
            if (empty($key) || !preg_match('/^price_[a-zA-Z0-9]+$/', $key)) continue;
            $out[$key] = [
                'mode'   => in_array($cfg['mode'] ?? '', ['payment','subscription'], true) ? $cfg['mode'] : 'payment',
                'role'   => sanitize_key($cfg['role']   ?? 'premium_basic'),
                'months' => isset($cfg['months']) && $cfg['months'] !== '' ? (int) $cfg['months'] : null,
            ];
        }
        return $out;
    }

    public static function sanitize_capability_map($input): array {
        if (!is_array($input)) return [];
        $out = [];

        foreach ($input as $perm_key => $cap_value) {
            $perm = sanitize_key($perm_key);
            $cap  = sanitize_key($cap_value);

            // Skip placeholder custom_ keys — they use the separate val field
            // Custom rows: name field = permission slug, val field = capability name
            if (str_starts_with($perm, 'custom_')) {
                // $cap here IS the permission slug (from the name input)
                // capability comes from wpa_capability_map_val[custom_N]
                $cap_name = sanitize_key($_POST['wpa_capability_map_val'][$perm] ?? '');
                if (empty($cap) || empty($cap_name)) continue;
                if (!str_starts_with($cap_name, 'wpa_')) $cap_name = 'wpa_' . $cap_name;
                $out[$cap] = $cap_name;
                continue;
            }

            if (empty($perm) || empty($cap)) continue;
            if (!str_starts_with($cap, 'wpa_')) $cap = 'wpa_' . $cap;
            $out[$perm] = $cap;
        }
        return $out;
    }

    // =========================================================================
    // Capabilities page
    // =========================================================================

    public static function render_capabilities(): void {
        if (!current_user_can('manage_options')) return;

        $default_map = WPA_Capabilities::DEFAULT_MAP;
        $current_map = get_option('wpa_capability_map', []);
        $active_map  = array_merge($default_map, $current_map);
        $all_perms   = WPA_Roles::ALL_PERMISSIONS;
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — WP Capabilities', WPA_TEXT_DOMAIN); ?></h1>
            <p class="description">
                <?php esc_html_e('Each WPA permission is mapped to a native WordPress capability. Any plugin can then use:', WPA_TEXT_DOMAIN); ?>
                <code>current_user_can('wpa_no_ads')</code>
            </p>

            <div class="notice notice-info inline">
                <p>
                    <strong><?php esc_html_e('Generic capability always available:', WPA_TEXT_DOMAIN); ?></strong>
                    <code><?php echo esc_html(WPA_Capabilities::CAP_PREMIUM); ?></code>
                    — <?php esc_html_e('Granted to any user with an active premium role.', WPA_TEXT_DOMAIN); ?>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wpa_cap_settings'); ?>
                <h2><?php esc_html_e('Permission → Capability mapping', WPA_TEXT_DOMAIN); ?></h2>
                <p class="description">
                    <?php esc_html_e('Capabilities are prefixed with wpa_ automatically. Changes take effect on next role grant/revoke or after a full resync.', WPA_TEXT_DOMAIN); ?>
                </p>
                <table class="wp-list-table widefat fixed" style="max-width:600px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WPA Permission', WPA_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('WP Capability', WPA_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Used by', WPA_TEXT_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_perms as $perm_key => $perm_label) :
                            $cap = $active_map[$perm_key] ?? ('wpa_' . $perm_key);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($perm_label); ?></strong><br>
                                <code style="font-size:.8em"><?php echo esc_html($perm_key); ?></code>
                            </td>
                            <td>
                                <input type="text"
                                       name="wpa_capability_map[<?php echo esc_attr($perm_key); ?>]"
                                       value="<?php echo esc_attr($cap); ?>"
                                       style="width:200px"
                                       placeholder="wpa_<?php echo esc_attr($perm_key); ?>" />
                            </td>
                            <td>
                                <code style="font-size:.8em">current_user_can('<?php echo esc_html($cap); ?>')</code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Save mapping', WPA_TEXT_DOMAIN)); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Full resync', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description">
                <?php esc_html_e('Force resync of WP capabilities for all active premium users. Use after changing the mapping or after a bulk import.', WPA_TEXT_DOMAIN); ?>
            </p>
            <button type="button" class="button button-secondary" id="wpa-resync-caps">
                <?php esc_html_e('Resync all users now', WPA_TEXT_DOMAIN); ?>
            </button>
            <span id="wpa-resync-feedback" style="margin-left:1em;font-weight:500"></span>
        </div>
        <?php
    }

    // =========================================================================
    // Resync AJAX
    // =========================================================================

    public static function ajax_resync_capabilities(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $count = WPA_Capabilities::sync_all();
        WPA_Capabilities::sync_wp_roles();

        wp_send_json_success([
            'count'   => $count,
            'message' => sprintf(
                __('%d user(s) synced successfully.', WPA_TEXT_DOMAIN),
                $count
            ),
        ]);
    }

    public static function ajax_grant_user_role(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $email      = sanitize_email($_POST['email']   ?? '');
        $role       = sanitize_key($_POST['role']      ?? '');
        $expires    = sanitize_text_field($_POST['expires'] ?? '') ?: null;

        if (!is_email($email) || empty($role)) {
            wp_send_json_error(__('Email and role are required.', WPA_TEXT_DOMAIN));
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            $uid = wp_create_user($email, wp_generate_password(32, true, true), $email);
            if (is_wp_error($uid)) wp_send_json_error($uid->get_error_message());
            $u = new WP_User($uid);
            $u->set_role('subscriber');
            $user = get_user_by('ID', $uid);
        }

        $expires_at = $expires ? $expires . ' 00:00:00' : null;
        WPA_Roles::grant($user->ID, $role, 'admin', $expires_at);

        wp_send_json_success([
            'user_id'   => $user->ID,
            'email'     => $user->user_email,
            'expires'   => $expires_at,
            'initials'  => strtoupper(substr($user->user_email, 0, 2)),
        ]);
    }

    public static function ajax_revoke_user_role(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $user_id = (int) ($_POST['user_id'] ?? 0);
        $role    = sanitize_key($_POST['role'] ?? '');

        if (!$user_id || !$role) wp_send_json_error('Invalid params');

        WPA_Roles::revoke($user_id, $role);
        wp_send_json_success(['revoked' => true]);
    }


    // =========================================================================
    // Capability AJAX handlers
    // =========================================================================

    public static function ajax_create_capability(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $slug   = sanitize_key($_POST['slug']   ?? '');
        $label  = sanitize_text_field($_POST['label']  ?? '');
        $wp_cap = sanitize_key($_POST['wp_cap'] ?? '');

        if (empty($slug)) wp_send_json_error(__('Slug is required.', WPA_TEXT_DOMAIN));
        if (empty($label)) $label = $slug;
        if (empty($wp_cap)) $wp_cap = 'wpa_' . $slug;
        if (!str_starts_with($wp_cap, 'wpa_')) $wp_cap = 'wpa_' . $wp_cap;

        // Check not already existing
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}wpa_capabilities WHERE slug = %s",
            $slug
        ));
        if ($exists) wp_send_json_error(__('A capability with this slug already exists.', WPA_TEXT_DOMAIN));

        WPA_Roles::save_capability($slug, $label, $wp_cap, false);
        WPA_Capabilities::declare_in_subscriber();

        wp_send_json_success([
            'slug'      => $slug,
            'label'     => $label,
            'wp_cap'    => $wp_cap,
            'is_builtin'=> 0,
        ]);
    }

    public static function ajax_save_capability(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $slug   = sanitize_key($_POST['slug']   ?? '');
        $label  = sanitize_text_field($_POST['label']  ?? '');
        $wp_cap = sanitize_key($_POST['wp_cap'] ?? '');

        if (empty($slug)) wp_send_json_error('Invalid slug');

        // Cannot edit built-in capabilities
        global $wpdb;
        $is_builtin = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_builtin FROM {$wpdb->prefix}wpa_capabilities WHERE slug = %s",
            $slug
        ));
        if ($is_builtin) wp_send_json_error(__('Built-in capabilities cannot be edited.', WPA_TEXT_DOMAIN));

        WPA_Roles::save_capability($slug, $label, $wp_cap, false);
        WPA_Capabilities::declare_in_subscriber();

        wp_send_json_success(['saved' => true]);
    }

    public static function ajax_delete_capability(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $slug = sanitize_key($_POST['slug'] ?? '');
        if (empty($slug)) wp_send_json_error('Invalid slug');

        // Cannot delete built-in capabilities
        global $wpdb;
        $is_builtin = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT is_builtin FROM {$wpdb->prefix}wpa_capabilities WHERE slug = %s",
            $slug
        ));
        if ($is_builtin) wp_send_json_error(__('Built-in capabilities cannot be deleted.', WPA_TEXT_DOMAIN));

        WPA_Roles::delete_capability($slug);
        WPA_Capabilities::declare_in_subscriber();

        wp_send_json_success(['deleted' => $slug]);
    }


    // =========================================================================
    // Magic link log page
    // =========================================================================

    public static function render_magic_log(): void {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $logs      = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wpa_magic_link_log ORDER BY requested_at DESC LIMIT 200"
        );
        $test_mode = (bool) get_option('wpa_test_mode', false);
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Magic Link Log', WPA_TEXT_DOMAIN); ?></h1>

            <?php if ($test_mode) : ?>
            <div class="notice notice-warning inline"><p>
                <strong><?php esc_html_e('Test mode active.', WPA_TEXT_DOMAIN); ?></strong>
                <?php esc_html_e('Rate limits are bypassed. Magic link URLs are shown below.', WPA_TEXT_DOMAIN); ?>
            </p></div>
            <?php endif; ?>

            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No requests yet.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped wpa-log-table">
                <thead>
                    <tr>
                        <th style="width:150px"><?php esc_html_e('Date', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Email', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:120px"><?php esc_html_e('IP', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:70px"><?php esc_html_e('Known', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:70px"><?php esc_html_e('Sent', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:110px"><?php esc_html_e('Result', WPA_TEXT_DOMAIN); ?></th>
                        <?php if ($test_mode) : ?>
                        <th><?php esc_html_e('Test link', WPA_TEXT_DOMAIN); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) :
                        $blocked  = !empty($log->blocked_reason);
                        $test_link = $test_mode
                            ? get_transient('wpa_test_last_link_' . md5($log->email))
                            : false;
                    ?>
                    <tr style="<?php echo $blocked ? 'background:#fff5f5!important' : ''; ?>">
                        <td><?php echo esc_html($log->requested_at); ?></td>
                        <td><?php echo esc_html($log->email); ?></td>
                        <td><code><?php echo esc_html($log->ip); ?></code></td>
                        <td>
                            <?php echo $log->email_exists
                                ? '<span style="color:#16a34a">&#10003;</span>'
                                : '<span style="color:#9ca3af">&#8212;</span>'; ?>
                        </td>
                        <td>
                            <?php echo $log->sent
                                ? '<span style="color:#16a34a">&#10003;</span>'
                                : '<span style="color:#9ca3af">&#8212;</span>'; ?>
                        </td>
                        <td>
                            <?php if ($blocked) : ?>
                                <span class="wpa-status wpa-status--error"><?php echo esc_html($log->blocked_reason); ?></span>
                            <?php elseif ($log->sent) : ?>
                                <span class="wpa-status wpa-status--ok">sent</span>
                            <?php else : ?>
                                <span class="wpa-status wpa-status--ignored"><?php echo esc_html($log->blocked_reason ?: 'silent'); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($test_mode) : ?>
                        <td>
                            <?php if ($test_link) : ?>
                                <a href="<?php echo esc_url($test_link); ?>" class="button button-small" target="_blank">
                                    <?php esc_html_e('Use link', WPA_TEXT_DOMAIN); ?>
                                </a>
                                <br><small style="font-size:.7rem;color:#9ca3af;word-break:break-all">
                                    <?php echo esc_html(substr($test_link, 0, 60)); ?>...
                                </small>
                            <?php else : ?>
                                <span style="color:#9ca3af;font-size:.8rem"><?php esc_html_e('Expired or not sent', WPA_TEXT_DOMAIN); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Rate limit reset AJAX
    // =========================================================================

    public static function ajax_reset_rate_limits(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->prefix}wpa_rate_limits WHERE key_hash LIKE 'ip_magic_%' OR key_hash LIKE '%magic_link_%'"
        );

        wp_send_json_success([
            'deleted' => $deleted,
            'message' => sprintf(__('%d rate limit entries cleared.', WPA_TEXT_DOMAIN), $deleted),
        ]);
    }


    // =========================================================================
    // Manual database update
    // =========================================================================

    public static function ajax_update_database(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        WPA_Install::create_tables();
        WPA_Install::migrate_roles_unique_key(); // add UNIQUE KEY if missing on existing installs
        WPA_Capabilities::declare_in_subscriber();
        WPA_Capabilities::sync_wp_roles();
        WPA_Install::seed_builtin_roles();
        flush_rewrite_rules(false); // ensure REST routes are registered

        wp_send_json_success([
            'version' => WPA_VERSION,
            'message' => sprintf(
                __('Database updated to version %s. All tables created, capabilities declared.', WPA_TEXT_DOMAIN),
                WPA_VERSION
            ),
        ]);
    }


    public static function ajax_clear_single_rl(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $hash = sanitize_text_field($_POST['hash'] ?? '');
        if (empty($hash)) wp_send_json_error('Invalid hash');

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpa_rate_limits', ['key_hash' => $hash], ['%s']);
        wp_send_json_success(['cleared' => true]);
    }


    // =========================================================================
    // API page
    // =========================================================================

    public static function render_api(): void {
        if (!current_user_can('manage_options')) return;

        $tokens  = WPA_API::get_tokens();
        $api_url = rest_url('wpa/v1/api/');
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — REST API', WPA_TEXT_DOMAIN); ?></h1>

            <!-- New token -->
            <div class="wpa-api-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:7px;padding:20px;max-width:600px;margin-bottom:24px">
                <h2 style="margin-top:0"><?php esc_html_e('Generate a new token', WPA_TEXT_DOMAIN); ?></h2>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px">
                            <?php esc_html_e('Description', WPA_TEXT_DOMAIN); ?>
                        </label>
                        <input type="text" id="wpa-api-desc" class="regular-text"
                               placeholder="<?php esc_attr_e('e.g. Authentik integration', WPA_TEXT_DOMAIN); ?>"
                               style="width:100%" />
                    </div>
                    <button type="button" class="button button-primary" id="wpa-generate-token">
                        <?php esc_html_e('Generate token', WPA_TEXT_DOMAIN); ?>
                    </button>
                </div>
                <div id="wpa-new-token-wrap" style="display:none;margin-top:16px">
                    <div class="notice notice-success inline" style="padding:10px 14px">
                        <p style="margin:0 0 8px;font-weight:600">
                            <?php esc_html_e('Token generated — copy it now, it will not be shown again.', WPA_TEXT_DOMAIN); ?>
                        </p>
                        <div style="display:flex;gap:8px;align-items:center">
                            <code id="wpa-new-token-val" style="font-size:.85rem;word-break:break-all;flex:1;background:#f3f4f6;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb"></code>
                            <button type="button" class="button" id="wpa-copy-token">
                                <?php esc_html_e('Copy', WPA_TEXT_DOMAIN); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Token list -->
            <h2><?php esc_html_e('Active tokens', WPA_TEXT_DOMAIN); ?></h2>
            <?php if (empty($tokens)) : ?>
                <p class="description"><?php esc_html_e('No tokens yet.', WPA_TEXT_DOMAIN); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed" style="max-width:900px">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Description', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:160px"><?php esc_html_e('Created', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:160px"><?php esc_html_e('Last used', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:70px"><?php esc_html_e('Status', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:160px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t) : ?>
                <tr id="wpa-token-row-<?php echo (int) $t->id; ?>">
                    <td>
                        <strong><?php echo esc_html($t->description ?: __('(no description)', WPA_TEXT_DOMAIN)); ?></strong>
                        <br><small style="color:#9ca3af;font-size:.75rem">ID <?php echo (int) $t->id; ?></small>
                    </td>
                    <td style="font-size:.82rem"><?php echo esc_html($t->created_at); ?></td>
                    <td style="font-size:.82rem">
                        <?php echo $t->last_used
                            ? esc_html($t->last_used)
                            : '<em style="color:#9ca3af">' . esc_html__('Never', WPA_TEXT_DOMAIN) . '</em>'; ?>
                    </td>
                    <td>
                        <?php if ($t->is_active) : ?>
                            <span class="wpa-status wpa-status--ok">active</span>
                        <?php else : ?>
                            <span class="wpa-status wpa-status--error">revoked</span>
                        <?php endif; ?>
                    </td>
                    <td style="display:flex;gap:6px">
                        <?php if ($t->is_active) : ?>
                        <button class="button button-small wpa-revoke-token"
                                data-id="<?php echo (int) $t->id; ?>"
                                title="<?php esc_attr_e('Revoke token', WPA_TEXT_DOMAIN); ?>">
                            <?php esc_html_e('Revoke', WPA_TEXT_DOMAIN); ?>
                        </button>
                        <?php endif; ?>
                        <button class="button button-small wpa-delete-token"
                                data-id="<?php echo (int) $t->id; ?>"
                                style="color:#dc2626;border-color:#dc2626">
                            <?php esc_html_e('Delete', WPA_TEXT_DOMAIN); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <hr style="margin:32px 0" />

            <!-- API Documentation -->
            <h2><?php esc_html_e('API Documentation', WPA_TEXT_DOMAIN); ?></h2>

            <div class="wpa-doc-card">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag">Authentication</span>
                    <span class="wpa-doc-card__desc"><?php esc_html_e('All requests require a Bearer token in the Authorization header.', WPA_TEXT_DOMAIN); ?></span>
                </div>
                <div class="wpa-doc-card__section">
                    <pre class="wpa-doc-example">Authorization: Bearer wpa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
                </div>
                <div class="wpa-doc-card__note">
                    <?php esc_html_e('Tokens can also be passed as ?api_token=wpa_xxx for testing (not recommended in production).', WPA_TEXT_DOMAIN); ?>
                </div>
            </div>

            <?php
            $base = rtrim($api_url, '/');
            $endpoints = [
                [
                    'method'  => 'GET',
                    'path'    => $base . '/status',
                    'desc'    => __('API status and version information.', WPA_TEXT_DOMAIN),
                    'params'  => [],
                    'example' => '{"status":"ok","plugin":"WP Premium Access","version":"1.1.0","api":"v1"}',
                ],
                [
                    'method'  => 'GET',
                    'path'    => $base . '/users',
                    'desc'    => __('List all WordPress users with their WPA premium data. Supports pagination, search and premium filtering.', WPA_TEXT_DOMAIN),
                    'params'  => [
                        'page'     => __('Page number (default: 1)', WPA_TEXT_DOMAIN),
                        'per_page' => __('Results per page, max 200 (default: 50)', WPA_TEXT_DOMAIN),
                        'search'   => __('Search by username, email or display name', WPA_TEXT_DOMAIN),
                        'premium'  => __('Filter: true = premium users only', WPA_TEXT_DOMAIN),
                    ],
                    'example' => '{"users":[...],"total":42,"page":1,"per_page":50,"pages":1}',
                ],
                [
                    'method'  => 'GET',
                    'path'    => $base . '/users/{id}',
                    'desc'    => __('Get a single user by their WordPress user ID.', WPA_TEXT_DOMAIN),
                    'params'  => ['id' => __('WordPress user ID (integer)', WPA_TEXT_DOMAIN)],
                    'example' => null,
                ],
                [
                    'method'  => 'GET',
                    'path'    => $base . '/users/email/{email}',
                    'desc'    => __('Get a single user by their email address. URL-encode the email.', WPA_TEXT_DOMAIN),
                    'params'  => ['email' => __('Email address (URL-encoded)', WPA_TEXT_DOMAIN)],
                    'example' => null,
                ],
            ];

            foreach ($endpoints as $ep) :
            ?>
            <div class="wpa-doc-card" style="margin-top:12px">
                <div class="wpa-doc-card__header">
                    <code class="wpa-doc-card__tag" style="color:#059669"><?php echo esc_html($ep['method']); ?></code>
                    <code style="font-size:.85rem;color:#374151;word-break:break-all"><?php echo esc_html($ep['path']); ?></code>
                </div>
                <div class="wpa-doc-card__section">
                    <strong><?php esc_html_e('Description', WPA_TEXT_DOMAIN); ?></strong>
                    <p style="margin:.25rem 0 0"><?php echo esc_html($ep['desc']); ?></p>
                </div>
                <?php if (!empty($ep['params'])) : ?>
                <div class="wpa-doc-card__section">
                    <strong><?php esc_html_e('Parameters', WPA_TEXT_DOMAIN); ?></strong>
                    <table class="wpa-doc-attrs">
                        <?php foreach ($ep['params'] as $param => $pdesc) : ?>
                        <tr>
                            <td><code><?php echo esc_html($param); ?></code></td>
                            <td><?php echo esc_html($pdesc); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($ep['example']) : ?>
                <div class="wpa-doc-card__section">
                    <strong><?php esc_html_e('Response example', WPA_TEXT_DOMAIN); ?></strong>
                    <pre class="wpa-doc-example"><?php echo esc_html($ep['example']); ?></pre>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- User object reference -->
            <div class="wpa-doc-card" style="margin-top:24px">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag">User object</span>
                    <span class="wpa-doc-card__desc"><?php esc_html_e('Structure returned for each user in all user endpoints.', WPA_TEXT_DOMAIN); ?></span>
                </div>
                <div class="wpa-doc-card__section">
                    <pre class="wpa-doc-example"><?php echo esc_html('{
  "id": 35,
  "email": "romain@rdr-it.com",
  "first_name": "Romain",
  "last_name": "Doe",
  "display_name": "Romain",
  "username": "romain",
  "registered_at": "2026-01-15 10:22:00",
  "wp_roles": ["subscriber", "wpa_premium_basic"],
  "wp_caps": ["read", "wpa_premium", "wpa_no_ads", "wpa_early_access"],
  "wpa": {
    "is_premium": true,
    "is_active": true,
    "expires_at": "2026-05-27 00:00:00",
    "is_lifetime": false,
    "roles": [
      {
        "role": "premium_basic",
        "granted_at": "2026-03-26 12:00:00",
        "granted_by": "kofi",
        "expires_at": "2026-05-27 00:00:00",
        "is_active": true,
        "is_lifetime": false
      }
    ],
    "roles_expired": [],
    "permissions": ["no_ads", "early_access", "export_md"]
  }
}'); ?></pre>
                </div>
            </div>

            <!-- Error codes -->
            <div class="wpa-doc-card" style="margin-top:12px">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag">Error codes</span>
                </div>
                <div class="wpa-doc-card__section">
                    <table class="wpa-doc-attrs">
                        <tr><td><code>401</code></td><td><?php esc_html_e('Missing, invalid or revoked token', WPA_TEXT_DOMAIN); ?></td></tr>
                        <tr><td><code>404</code></td><td><?php esc_html_e('User not found', WPA_TEXT_DOMAIN); ?></td></tr>
                        <tr><td><code>200</code></td><td><?php esc_html_e('Success', WPA_TEXT_DOMAIN); ?></td></tr>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }

    // =========================================================================
    // API token AJAX
    // =========================================================================

    public static function ajax_generate_api_token(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $desc = sanitize_text_field($_POST['description'] ?? '');
        $result = WPA_API::generate_token($desc, get_current_user_id());

        wp_send_json_success($result);
    }

    public static function ajax_revoke_api_token(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $id = (int) ($_POST['id'] ?? 0);
        wp_send_json_success(['revoked' => WPA_API::revoke_token($id)]);
    }

    public static function ajax_delete_api_token(): void {
        check_ajax_referer('wpa_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

        $id = (int) ($_POST['id'] ?? 0);
        wp_send_json_success(['deleted' => WPA_API::delete_token($id)]);
    }


    // =========================================================================
    // SSO page
    // =========================================================================

    public static function render_sso(): void {
        if (!current_user_can('manage_options')) return;

        $init_url   = rest_url('wpa/v1/sso/init');
        $verify_url = rest_url('wpa/v1/sso/verify');
        $me_url     = rest_url('wpa/v1/api/me');
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — SSO', WPA_TEXT_DOMAIN); ?></h1>

            <!-- Settings -->
            <form method="post" action="options.php">
                <?php settings_fields('wpa_sso_settings'); ?>
                <h2><?php esc_html_e('Configuration', WPA_TEXT_DOMAIN); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Allowed origins', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea name="wpa_sso_allowed_origins" rows="6" class="large-text"
                                      placeholder="https://app.rdr-it.com&#10;https://*.mon-app.com&#10;https://autre-domaine.com"
                            ><?php echo esc_textarea(get_option('wpa_sso_allowed_origins', '')); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('One URL per line. Wildcards supported: https://*.rdr-it.com', WPA_TEXT_DOMAIN); ?>
                                <br><?php esc_html_e('The site itself is always allowed. Only origins that will redirect back to WP for SSO login.', WPA_TEXT_DOMAIN); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('JWT lifetime', WPA_TEXT_DOMAIN); ?></th>
                        <td>
                            <input type="number" name="wpa_sso_jwt_ttl" min="60" max="3600" style="width:80px"
                                   value="<?php echo esc_attr(get_option('wpa_sso_jwt_ttl', 300)); ?>" />
                            <?php esc_html_e('seconds', WPA_TEXT_DOMAIN); ?>
                            <p class="description"><?php esc_html_e('Default: 300 (5 minutes). JWT is single-use — once verified, it cannot be reused.', WPA_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save SSO settings', WPA_TEXT_DOMAIN)); ?>
            </form>

            <hr style="margin:28px 0" />

            <!-- Endpoint reference -->
            <h2><?php esc_html_e('Endpoints', WPA_TEXT_DOMAIN); ?></h2>
            <table class="wp-list-table widefat fixed" style="max-width:700px;margin-bottom:20px">
                <tbody>
                    <tr>
                        <td style="width:60px"><strong>GET</strong></td>
                        <td><code class="wpa-copyable"><?php echo esc_html($init_url); ?>?redirect=URL</code></td>
                    </tr>
                    <tr>
                        <td><strong>POST</strong></td>
                        <td><code class="wpa-copyable"><?php echo esc_html($verify_url); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>GET</strong></td>
                        <td><code class="wpa-copyable"><?php echo esc_html($me_url); ?></code></td>
                    </tr>
                </tbody>
            </table>

            <!-- Flow docs -->
            <?php
            $flows = [
                [
                    'title' => __('Same-domain flow (*.rdr-it.com)', WPA_TEXT_DOMAIN),
                    'desc'  => __('For subdomains sharing the same root domain. No redirect needed — the WPA session cookie is shared automatically.', WPA_TEXT_DOMAIN),
                    'code'  => "// app.rdr-it.com — check current user\nconst res = await fetch('" . esc_js($me_url) . "', {\n  credentials: 'include', // sends cookies\n});\nconst { user } = await res.json();\nif (!user) { /* redirect to login */ }",
                ],
                [
                    'title' => __('Cross-domain flow — initiate SSO', WPA_TEXT_DOMAIN),
                    'desc'  => __('Redirect the user to WP SSO endpoint. WP will authenticate them and redirect back with a JWT.', WPA_TEXT_DOMAIN),
                    'code'  => "// Step 1 — redirect user to WP SSO\nconst callback = encodeURIComponent(window.location.origin + '/callback');\nwindow.location.href = '" . esc_js($init_url) . "?redirect=' + callback;\n\n// Step 2 — on /callback page, extract JWT from URL\nconst jwt = new URLSearchParams(location.search).get('jwt');",
                ],
                [
                    'title' => __('Cross-domain flow — verify JWT', WPA_TEXT_DOMAIN),
                    'desc'  => __('After receiving the JWT in the callback, verify it server-side. The JWT is single-use (5 min TTL).', WPA_TEXT_DOMAIN),
                    'code'  => "// JavaScript (Node.js backend)\nconst res = await fetch('" . esc_js($verify_url) . "', {\n  method: 'POST',\n  headers: { 'Content-Type': 'application/json' },\n  body: JSON.stringify({ token: jwt }),\n});\nconst data = await res.json();\nif (!data.valid) { /* reject */ }\nconst user = data.user; // full user object",
                ],
                [
                    'title' => __('PHP — verify JWT', WPA_TEXT_DOMAIN),
                    'desc'  => __('Server-side verification in PHP.', WPA_TEXT_DOMAIN),
                    'code'  => '$jwt = $_GET[\'jwt\'] ?? \'\';' . "\n" . '$res = file_get_contents(\'' . esc_js($verify_url) . '\', false,' . "\n  stream_context_create(['http' => [\n    'method'  => 'POST',\n    'header'  => 'Content-Type: application/json'," . "\n    'content' => json_encode(['token' => \$jwt]),\n  ]])\n);\n\$data = json_decode(\$res, true);\nif (!\$data['valid']) { /* reject */ }\n\$user = \$data['user'];",
                ],
                [
                    'title' => __('Bearer JWT for /api/me', WPA_TEXT_DOMAIN),
                    'desc'  => __('After SSO, store the JWT and use it as Bearer token for subsequent /api/me calls.', WPA_TEXT_DOMAIN),
                    'code'  => "const res = await fetch('" . esc_js($me_url) . "', {\n  headers: { Authorization: 'Bearer ' + jwt },\n});\nconst { user } = await res.json();",
                ],
            ];

            foreach ($flows as $flow) : ?>
            <div class="wpa-doc-card" style="margin-bottom:12px">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag"><?php echo esc_html($flow['title']); ?></span>
                    <span class="wpa-doc-card__desc"><?php echo esc_html($flow['desc']); ?></span>
                </div>
                <div class="wpa-doc-card__section">
                    <pre class="wpa-doc-example"><?php echo esc_html($flow['code']); ?></pre>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- JWT payload reference -->
            <div class="wpa-doc-card" style="margin-top:20px">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag">JWT Payload</span>
                    <span class="wpa-doc-card__desc"><?php esc_html_e('Structure of the JWT payload returned after SSO.', WPA_TEXT_DOMAIN); ?></span>
                </div>
                <div class="wpa-doc-card__section">
                    <pre class="wpa-doc-example"><?php echo esc_html('{
  "iss": "https://rdr-it.com/",
  "aud": "https://app.autre.com",
  "sub": 35,
  "email": "romain@rdr-it.com",
  "iat": 1234567890,
  "exp": 1234568190,
  "jti": "uuid4-single-use-id",
  "wpa": {
    "is_premium": true,
    "roles": ["premium_basic"],
    "permissions": ["no_ads", "early_access", "export_md"]
  }
}'); ?></pre>
                </div>
            </div>

            <!-- Security notes -->
            <div class="wpa-doc-card" style="margin-top:12px">
                <div class="wpa-doc-card__header">
                    <span class="wpa-doc-card__tag">Security</span>
                </div>
                <div class="wpa-doc-card__section" style="font-size:.875rem;line-height:1.7">
                    <ul style="margin:.5rem 0;padding-left:1.2rem">
                        <li><?php esc_html_e('JWT signed with HMAC-SHA256 using AUTH_KEY from wp-config.php', WPA_TEXT_DOMAIN); ?></li>
                        <li><?php esc_html_e('Single-use: jti (JWT ID) stored server-side after first verify — replay attacks rejected', WPA_TEXT_DOMAIN); ?></li>
                        <li><?php esc_html_e('Short TTL: 5 minutes by default', WPA_TEXT_DOMAIN); ?></li>
                        <li><?php esc_html_e('redirect= URL validated against allowlist before generating JWT', WPA_TEXT_DOMAIN); ?></li>
                        <li><?php esc_html_e('CORS headers sent only for origins in the allowlist', WPA_TEXT_DOMAIN); ?></li>
                        <li><?php esc_html_e('Always verify JWT server-side — never trust client-side JWT decoding alone', WPA_TEXT_DOMAIN); ?></li>
                    </ul>
                </div>
            </div>

        </div>
        <?php
    }
}
