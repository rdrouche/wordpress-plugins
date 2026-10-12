<?php
defined('ABSPATH') || exit;

/**
 * WPA_Diagnostic — System check and documentation page.
 */
class WPA_Diagnostic {

    /** All tables the plugin requires */
    public const REQUIRED_TABLES = [
        'wpa_sessions',
        'wpa_roles',
        'wpa_role_definitions',
        'wpa_role_perms',
        'wpa_capabilities',
        'wpa_favorites',
        'wpa_read_later',
        'wpa_webhook_log',
        'wpa_processed_messages',
        'wpa_rate_limits',
    ];

    /** All shortcodes with documentation */
    public const SHORTCODES = [
        '[wpa_login_form]' => [
            'desc'    => 'Magic link sign-in form. Displays an email input that sends the user a one-click login link.',
            'attrs'   => [],
            'example' => '[wpa_login_form]',
            'notes'   => 'Auto-placed on the /connexion-premium/ page created at activation.',
        ],
        '[wpa_premium_gate]' => [
            'desc'    => 'Wraps content that should only be visible to users with a specific permission.',
            'attrs'   => [
                'permission' => 'WPA permission slug. Default: early_access',
                'message'    => 'Custom message shown to guests. HTML allowed.',
            ],
            'example' => '[wpa_premium_gate permission="export_md"]Exclusive content here[/wpa_premium_gate]',
            'notes'   => 'Closing shortcode required.',
        ],
        '[wpa_guest_only]' => [
            'desc'    => 'Wraps content that should only be visible to non-premium users (guests).',
            'attrs'   => [],
            'example' => '[wpa_guest_only]<p>Support us to unlock premium access.</p>[/wpa_guest_only]',
            'notes'   => 'Useful to show upgrade CTAs only to guests.',
        ],
        '[wpa_account]' => [
            'desc'    => 'Full account management panel: roles, expiry, devices, sync, and optional early access button.',
            'attrs'   => [
                'show_expiry'        => 'Show expiry date under each role: yes|no. Default: yes',
                'show_devices'       => 'Show connected devices list: yes|no. Default: yes',
                'early_access_url'   => 'URL of early access page. Button only shown if set.',
                'early_access_label' => 'Button label. Default: Early access',
                'early_access_perm'  => 'WPA permission required to show the button. Default: early_access',
            ],
            'example' => '[wpa_account show_expiry="yes" show_devices="yes" early_access_url="/avant-premiere/" early_access_label="Avant-première ✨"]',
            'notes'   => 'Auto-placed on /mon-compte-premium/. Shows sign-in link if not connected. Expiry warning shown in orange when < 7 days.',
        ],
        '[wpa_export_button]' => [
            'desc'    => 'Inline download button to export the current post as a Markdown (.md) file.',
            'attrs'   => [
                'id' => 'Post ID to export. Defaults to current post.',
            ],
            'example' => '[wpa_export_button id="123"]',
            'notes'   => 'Only visible to users with the export_md permission. Also available as floating button (automatic).',
        ],
        '[wpa_premium_badge]' => [
            'desc'    => 'Displays a small premium badge if the current user has any active premium role.',
            'attrs'   => [],
            'example' => '[wpa_premium_badge]',
            'notes'   => 'Returns empty string for guests.',
        ],
        '[wpa_early_access]' => [
            'desc'    => 'Lists scheduled (future) posts available early to premium users. Shows a gate message to guests.',
            'attrs'   => [
                'post_type' => 'Post type slug(s), comma-separated. Default: post',
                'limit'     => 'Max posts to display. Default: 10',
                'orderby'   => 'Sort by: date, title, rand. Default: date',
                'order'     => 'ASC (soonest first) or DESC. Default: ASC',
                'show_date' => 'Show scheduled publish date: yes|no. Default: yes',
                'show_type' => 'Show post type label: yes|no. Default: no',
                'gate_msg'  => 'Custom HTML message for guests.',
                'class'     => 'Extra CSS class on the wrapper.',
            ],
            'example' => '[wpa_early_access post_type="post,tutoriel" limit="5" show_date="yes"]',
            'notes'   => 'Export .md button appears automatically if user has export_md permission.',
        ],
        '[wpa_stripe_button]' => [
            'desc'    => 'Stripe Checkout button. Redirects to the Stripe hosted payment page.',
            'attrs'   => [
                'price_id' => 'Stripe Price ID (required). Found in Stripe Dashboard → Products.',
                'label'    => 'Button text. Default: Become a supporter',
                'class'    => 'Extra CSS classes to add to the button.',
            ],
            'example' => '[wpa_stripe_button price_id="price_xxx" label="Buy premium access"]',
            'notes'   => 'Automatically hidden if user is already premium. Configure Price ID → Role mapping in Settings → Stripe.',
        ],
    ];

    public static function init(): void {
        add_action('admin_menu', [self::class, 'add_menu']);
    }

    public static function add_menu(): void {
        add_submenu_page(
            'wpa-settings',
            __('Diagnostic & Docs', WPA_TEXT_DOMAIN),
            __('Diagnostic & Docs', WPA_TEXT_DOMAIN),
            'manage_options',
            'wpa-diagnostic',
            [self::class, 'render']
        );
    }

    // =========================================================================
    // System check
    // =========================================================================

    public static function run_checks(): array {
        global $wpdb;
        $checks = [];

        // 1. Database tables
        foreach (self::REQUIRED_TABLES as $table) {
            $full   = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full'") === $full;
            $checks['tables'][] = [
                'name'   => $full,
                'ok'     => $exists,
                'detail' => $exists ? __('Present', WPA_TEXT_DOMAIN) : __('MISSING — click "Fix" to create', WPA_TEXT_DOMAIN),
            ];
        }

        // 2. Ko-fi token configured
        $kofi_token = get_option('wpa_kofi_token', '');
        $checks['config'][] = [
            'name'   => __('Ko-fi verification token', WPA_TEXT_DOMAIN),
            'ok'     => !empty($kofi_token),
            'detail' => !empty($kofi_token)
                ? __('Configured', WPA_TEXT_DOMAIN)
                : __('Not set — webhook payloads will not be verified', WPA_TEXT_DOMAIN),
        ];

        // 3. Stripe keys
        $stripe_key = get_option('wpa_stripe_secret_key', '');
        $checks['config'][] = [
            'name'   => __('Stripe secret key', WPA_TEXT_DOMAIN),
            'ok'     => !empty($stripe_key),
            'detail' => !empty($stripe_key)
                ? (str_starts_with($stripe_key, 'sk_test_')
                    ? __('Configured (test mode)', WPA_TEXT_DOMAIN)
                    : __('Configured (live)', WPA_TEXT_DOMAIN))
                : __('Not set', WPA_TEXT_DOMAIN),
        ];

        // 4. Required pages
        $pages = [
            'connexion-premium'  => __('Sign-in page (/connexion-premium/)', WPA_TEXT_DOMAIN),
            'mon-compte-premium' => __('Account page (/mon-compte-premium/)', WPA_TEXT_DOMAIN),
        ];
        foreach ($pages as $slug => $label) {
            $page = get_page_by_path($slug);
            $checks['pages'][] = [
                'name'   => $label,
                'ok'     => (bool) $page,
                'detail' => $page
                    ? sprintf(__('Published — <a href="%s" target="_blank">view</a>', WPA_TEXT_DOMAIN), get_permalink($page))
                    : __('Missing — deactivate and reactivate to create', WPA_TEXT_DOMAIN),
            ];
        }

        // 5. Capabilities declared in subscriber
        $subscriber   = get_role('subscriber');
        $cap_map      = WPA_Capabilities::get_map();
        $declared     = 0;
        $total        = count($cap_map) + 1; // +1 for wpa_premium
        foreach ($cap_map as $cap) {
            if (isset($subscriber->capabilities[$cap])) $declared++;
        }
        if (isset($subscriber->capabilities[WPA_Capabilities::CAP_PREMIUM])) $declared++;
        $checks['config'][] = [
            'name'   => __('Capabilities declared in subscriber role', WPA_TEXT_DOMAIN),
            'ok'     => $declared === $total,
            'detail' => sprintf('%d / %d %s', $declared, $total, __('declared', WPA_TEXT_DOMAIN)),
        ];

        // 6. PHP version
        $php_ok = version_compare(PHP_VERSION, '8.0', '>=');
        $checks['system'][] = [
            'name'   => 'PHP version',
            'ok'     => $php_ok,
            'detail' => PHP_VERSION . ($php_ok ? '' : ' — PHP 8.0+ required'),
        ];

        // 7. WP Cron
        $cron_ok = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
        $daily   = wp_next_scheduled('wpa_daily_cron');
        $checks['system'][] = [
            'name'   => 'WP Cron (wpa_daily_cron)',
            'ok'     => $cron_ok && $daily,
            'detail' => $cron_ok
                ? ($daily ? sprintf(__('Scheduled — next run: %s', WPA_TEXT_DOMAIN), date_i18n('Y-m-d H:i', $daily)) : __('Not scheduled', WPA_TEXT_DOMAIN))
                : __('DISABLE_WP_CRON is true — expiry warning emails will not send', WPA_TEXT_DOMAIN),
        ];

        // 7b. REST API routes registered
        $rest_url  = rest_url('wpa/v1/kofi-webhook');
        $rest_routes = rest_get_server()->get_routes();
        $route_ok = isset($rest_routes['/wpa/v1/kofi-webhook']);
        $checks['system'][] = [
            'name'   => 'REST API — /wpa/v1/kofi-webhook',
            'ok'     => $route_ok,
            'detail' => $route_ok
                ? sprintf('<a href="%s" target="_blank">%s</a>', esc_url($rest_url), esc_html($rest_url))
                : __('Route not registered — try "Fix" or deactivate/reactivate plugin', WPA_TEXT_DOMAIN),
        ];

        // 8. Polylang
        $checks['system'][] = [
            'name'   => 'Polylang',
            'ok'     => function_exists('pll_current_language'),
            'detail' => function_exists('pll_current_language')
                ? sprintf(__('Active — current language: %s', WPA_TEXT_DOMAIN), pll_current_language('slug') ?: 'n/a')
                : __('Not active — localStorage sync will use fallback locale', WPA_TEXT_DOMAIN),
        ];

        // 9. OpenSSL (encryption)
        $checks['system'][] = [
            'name'   => 'OpenSSL (AES-256 encryption)',
            'ok'     => extension_loaded('openssl'),
            'detail' => extension_loaded('openssl')
                ? __('Available', WPA_TEXT_DOMAIN)
                : __('Missing — favorites sync encryption will not work', WPA_TEXT_DOMAIN),
        ];

        return $checks;
    }

    // =========================================================================
    // Render
    // =========================================================================

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        // Handle fix action
        if (isset($_POST['wpa_fix_nonce']) && wp_verify_nonce($_POST['wpa_fix_nonce'], 'wpa_fix')) {
            WPA_Install::create_tables();
            WPA_Capabilities::declare_in_subscriber();
            WPA_Install::seed_builtin_roles();
            flush_rewrite_rules(false); // ensure REST routes are registered
            echo '<div class="notice notice-success"><p>'
                . esc_html__('Fix applied. Tables created and capabilities declared.', WPA_TEXT_DOMAIN)
                . '</p></div>';
        }

        $checks = self::run_checks();
        $all_ok = true;
        foreach ($checks as $group) {
            foreach ($group as $c) {
                if (!$c['ok']) { $all_ok = false; break 2; }
            }
        }
        ?>
        <div class="wrap wpa-wrap">
            <h1><?php esc_html_e('Premium Access — Diagnostic & Documentation', WPA_TEXT_DOMAIN); ?></h1>

            <?php /* ===== SYSTEM CHECK ===== */ ?>
            <h2><?php esc_html_e('System check', WPA_TEXT_DOMAIN); ?></h2>

            <?php if ($all_ok) : ?>
            <div class="notice notice-success inline"><p>
                <strong><?php esc_html_e('All checks passed.', WPA_TEXT_DOMAIN); ?></strong>
            </p></div>
            <?php else : ?>
            <div class="notice notice-warning inline"><p>
                <?php esc_html_e('Some checks failed. Click "Fix" to attempt automatic repair.', WPA_TEXT_DOMAIN); ?>
                <form method="post" style="display:inline;margin-left:12px">
                    <?php wp_nonce_field('wpa_fix', 'wpa_fix_nonce'); ?>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Fix automatically', WPA_TEXT_DOMAIN); ?>
                    </button>
                </form>
            </p></div>
            <?php endif; ?>

            <?php
            $group_labels = [
                'tables' => __('Database tables', WPA_TEXT_DOMAIN),
                'config' => __('Configuration', WPA_TEXT_DOMAIN),
                'pages'  => __('WordPress pages', WPA_TEXT_DOMAIN),
                'system' => __('System', WPA_TEXT_DOMAIN),
            ];
            foreach ($checks as $group_key => $items) : ?>
            <h3 style="margin-top:20px"><?php echo esc_html($group_labels[$group_key] ?? $group_key); ?></h3>
            <table class="wp-list-table widefat fixed" style="max-width:800px">
                <tbody>
                    <?php foreach ($items as $check) : ?>
                    <tr>
                        <td style="width:32px;text-align:center;font-size:1.1rem">
                            <?php echo $check['ok'] ? '<span style="color:#16a34a">&#10003;</span>' : '<span style="color:#dc2626">&#10007;</span>'; ?>
                        </td>
                        <td style="width:280px;font-weight:<?php echo $check['ok'] ? '400' : '600'; ?>">
                            <?php echo esc_html($check['name']); ?>
                        </td>
                        <td style="color:<?php echo $check['ok'] ? '#6b7280' : '#dc2626'; ?>;font-size:.875rem">
                            <?php echo wp_kses_post($check['detail']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>

            <hr style="margin:32px 0" />

            <?php /* ===== WEBHOOK URLS ===== */ ?>
            <h2><?php esc_html_e('Webhook URLs', WPA_TEXT_DOMAIN); ?></h2>
            <table class="wp-list-table widefat fixed" style="max-width:800px">
                <tbody>
                    <tr>
                        <td style="width:140px;font-weight:600">Ko-fi</td>
                        <td><code class="wpa-copyable"><?php echo esc_html(rest_url('wpa/v1/kofi-webhook')); ?></code></td>
                    </tr>
                    <tr>
                        <td style="font-weight:600">Stripe</td>
                        <td><code class="wpa-copyable"><?php echo esc_html(rest_url('wpa/v1/stripe-webhook')); ?></code></td>
                    </tr>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('Click to copy. Paste these URLs in Ko-fi Settings > Webhooks and Stripe Dashboard > Developers > Webhooks.', WPA_TEXT_DOMAIN); ?></p>

            <hr style="margin:32px 0" />

            <?php /* ===== SHORTCODES ===== */ ?>
            <h2><?php esc_html_e('Shortcodes', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description" style="margin-bottom:16px">
                <?php esc_html_e('All shortcodes provided by this plugin. Compatible with Gutenberg, Elementor, Divi, and classic editor.', WPA_TEXT_DOMAIN); ?>
            </p>

            <?php foreach (self::SHORTCODES as $tag => $info) : ?>
            <div class="wpa-doc-card">
                <div class="wpa-doc-card__header">
                    <code class="wpa-doc-card__tag"><?php echo esc_html($tag); ?></code>
                    <span class="wpa-doc-card__desc"><?php echo esc_html($info['desc']); ?></span>
                </div>
                <?php if (!empty($info['attrs'])) : ?>
                <div class="wpa-doc-card__section">
                    <strong><?php esc_html_e('Attributes', WPA_TEXT_DOMAIN); ?></strong>
                    <table class="wpa-doc-attrs">
                        <?php foreach ($info['attrs'] as $attr => $desc) : ?>
                        <tr>
                            <td><code><?php echo esc_html($attr); ?></code></td>
                            <td><?php echo esc_html($desc); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
                <div class="wpa-doc-card__section">
                    <strong><?php esc_html_e('Example', WPA_TEXT_DOMAIN); ?></strong>
                    <pre class="wpa-doc-example"><?php echo esc_html($info['example']); ?></pre>
                </div>
                <?php if (!empty($info['notes'])) : ?>
                <div class="wpa-doc-card__note">
                    <?php echo esc_html($info['notes']); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <hr style="margin:32px 0" />

            <?php /* ===== HOOKS & FILTERS ===== */ ?>
            <h2><?php esc_html_e('Available hooks & filters', WPA_TEXT_DOMAIN); ?></h2>
            <table class="wp-list-table widefat fixed" style="max-width:900px">
                <thead>
                    <tr>
                        <th style="width:280px"><?php esc_html_e('Hook', WPA_TEXT_DOMAIN); ?></th>
                        <th style="width:80px"><?php esc_html_e('Type', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Description', WPA_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $hooks = [
                        ['wpa_role_granted',          'action', 'Fired after a role is granted. Args: $user_id, $role'],
                        ['wpa_role_revoked',          'action', 'Fired after a single role is revoked. Args: $user_id, $role'],
                        ['wpa_roles_revoked_all',     'action', 'Fired after all roles are revoked. Args: $user_id'],
                        ['wpa_send_welcome',          'action', 'Fired on first premium login. Args: $user_id'],
                        ['wpa_daily_cron',            'action', 'Daily cron event — expiry warnings, session cleanup'],
                        ['wpa_mail_headers',          'filter', 'Modify email headers. Args: $headers, $type'],
                        ['wpa_mail_subject',          'filter', 'Modify email subject. Args: $subject, $type, $user'],
                        ['wpa_mail_body',             'filter', 'Modify email body. Args: $body, $type, $format'],
                        ['wpa_ad_block_html',         'filter', 'Override the ad block HTML. Return empty string to disable'],
                    ];
                    foreach ($hooks as [$name, $type, $desc]) : ?>
                    <tr>
                        <td><code><?php echo esc_html($name); ?></code></td>
                        <td><span class="wpa-tag wpa-tag--<?php echo $type === 'action' ? 'builtin' : 'custom'; ?>">
                            <?php echo esc_html($type); ?>
                        </span></td>
                        <td style="font-size:.875rem"><?php echo esc_html($desc); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php /* ===== WP CAPABILITIES REFERENCE ===== */ ?>
            <hr style="margin:32px 0" />
            <h2><?php esc_html_e('WP Capabilities reference', WPA_TEXT_DOMAIN); ?></h2>
            <p class="description"><?php esc_html_e('Use these in any plugin or theme with current_user_can().', WPA_TEXT_DOMAIN); ?></p>
            <table class="wp-list-table widefat fixed" style="max-width:700px">
                <thead>
                    <tr>
                        <th><?php esc_html_e('WP Capability', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Usage', WPA_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Description', WPA_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>wpa_premium</code></td>
                        <td><code style="font-size:.78rem">current_user_can('wpa_premium')</code></td>
                        <td style="font-size:.875rem"><?php esc_html_e('Any active premium role', WPA_TEXT_DOMAIN); ?></td>
                    </tr>
                    <?php
                    $caps = WPA_Roles::get_all_capabilities();
                    foreach ($caps as $cap) : ?>
                    <tr>
                        <td><code><?php echo esc_html($cap->wp_cap); ?></code></td>
                        <td><code style="font-size:.78rem">current_user_can('<?php echo esc_html($cap->wp_cap); ?>')</code></td>
                        <td style="font-size:.875rem"><?php echo esc_html($cap->label ?: $cap->slug); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>
        <?php
    }
}
