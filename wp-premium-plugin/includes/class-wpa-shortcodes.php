<?php
defined('ABSPATH') || exit;

/**
 * All shortcodes for the WP Premium Access plugin.
 *
 * Available shortcodes:
 *   [wpa_login_form]              — Magic link request form
 *   [wpa_premium_gate]            — Show content only to premium users
 *   [wpa_guest_only]              — Show content only to guests
 *   [wpa_account]                 — Account / session management panel
 *   [wpa_export_button id="123"]  — Export .md button for a post
 *   [wpa_premium_badge]           — Shows premium badge if user is premium
 */
class WPA_Shortcodes {

    private static function get_user_id(): int {
        $user_id = WPA_Sessions::current_user_id();
        if (!$user_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
        }
        return (int) $user_id;
    }

    public static function init(): void {
        $shortcodes = [
            'wpa_login_form'    => [self::class, 'login_form'],
            'wpa_premium_gate'  => [self::class, 'premium_gate'],
            'wpa_guest_only'    => [self::class, 'guest_only'],
            'wpa_account'       => [self::class, 'account'],
            'wpa_export_button' => [self::class, 'export_button'],
            'wpa_premium_badge'   => [self::class, 'premium_badge'],
            'wpa_stripe_button'   => [self::class, 'stripe_button'],
            'wpa_early_access'    => [self::class, 'early_access_posts'],
        ];

        foreach ($shortcodes as $tag => $callback) {
            add_shortcode($tag, $callback);
        }

        // Enqueue assets when shortcodes are present
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void {
        // Always enqueue on frontend — session verify runs on every page
        wp_enqueue_style(
            'wpa-frontend',
            WPA_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            WPA_VERSION
        );
        wp_enqueue_script(
            'wpa-frontend',
            WPA_PLUGIN_URL . 'assets/js/frontend.js',
            [],          // no jQuery dependency
            WPA_VERSION,
            true         // in footer
        );
        wp_localize_script('wpa-frontend', 'WPA', [
            'apiUrl'  => rest_url('wpa/v1'),
            'nonce'   => wp_create_nonce('wp_rest'),
            'kofiUrl' => get_option('wpa_kofi_url', 'https://ko-fi.com'),
            'sync'    => [
                // Polylang current language — falls back to 'fr'
                'lang' => function_exists('pll_current_language')
                    ? pll_current_language('slug')
                    : (get_locale() !== '' ? substr(get_locale(), 0, 2) : 'fr'),
            ],
            'i18n'    => [
                'sending'         => __('Sending...', WPA_TEXT_DOMAIN),
                'sent'            => __('Check your inbox!', WPA_TEXT_DOMAIN),
                'error'           => __('Something went wrong. Please try again.', WPA_TEXT_DOMAIN),
                'logout_confirm'  => __('Sign out of your premium account?', WPA_TEXT_DOMAIN),
                'sync_on'         => __('Sync enabled', WPA_TEXT_DOMAIN),
                'sync_off'        => __('Sync disabled. Server data deleted.', WPA_TEXT_DOMAIN),
                'sync_confirm'          => __('This will delete your favorites and read-later list from our servers. Your local data will be kept. Continue?', WPA_TEXT_DOMAIN),
                'premium_expired'       => __('Your premium access has expired.', WPA_TEXT_DOMAIN),
                'premium_expiring_soon' => __('Your premium access expires on {date}.', WPA_TEXT_DOMAIN),
                'renew'                 => __('Renew support', WPA_TEXT_DOMAIN),
                'payment_success'       => __('Payment successful! Check your inbox for your access link.', WPA_TEXT_DOMAIN),
                'payment_cancel'        => __('Payment cancelled.', WPA_TEXT_DOMAIN),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // [wpa_login_form]
    // -------------------------------------------------------------------------

    public static function login_form(array $atts): string {
        $atts = shortcode_atts(['redirect' => ''], $atts);

        // Already logged in?
        $user_id = self::get_user_id();
        if ($user_id) {
            return '<div class="wpa-already-logged-in" data-wpa-theme>'
                . '<span>&#10003; </span>'
                . esc_html__('You are already connected.', WPA_TEXT_DOMAIN)
                . '</div>';
        }

        // Show error/info messages
        $message = '';
        if (isset($_GET['wpa_error']) && $_GET['wpa_error'] === 'expired') {
            $message = '<p class="wpa-notice wpa-notice--error">'
                . esc_html__('This link has expired or is invalid. Please request a new one.', WPA_TEXT_DOMAIN)
                . '</p>';
        }
        if (isset($_GET['wpa_info']) && $_GET['wpa_info'] === 'use_magic_link') {
            $message = '<p class="wpa-notice wpa-notice--info">'
                . esc_html__('Please use your magic link to sign in. Enter your email below.', WPA_TEXT_DOMAIN)
                . '</p>';
        }

        ob_start(); ?>
        <div class="wpa-login-form" data-wpa-theme>
            <?php echo $message; ?>
            <form class="wpa-form" id="wpa-magic-link-form" novalidate>
                <label for="wpa-email" class="wpa-label">
                    <?php esc_html_e('Email address', WPA_TEXT_DOMAIN); ?>
                </label>
                <div class="wpa-input-group">
                    <input
                        type="email"
                        id="wpa-email"
                        name="email"
                        class="wpa-input"
                        placeholder="<?php esc_attr_e('your@email.com', WPA_TEXT_DOMAIN); ?>"
                        required
                        autocomplete="email"
                    />
                    <button type="submit" class="wpa-btn wpa-btn--primary">
                        <?php esc_html_e('Receive my link', WPA_TEXT_DOMAIN); ?>
                    </button>
                </div>
                <p class="wpa-hint">
                    <?php esc_html_e('A sign-in link will be sent if your email is recognized.', WPA_TEXT_DOMAIN); ?>
                </p>
                <div class="wpa-form-feedback" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // [wpa_premium_gate]content here[/wpa_premium_gate]
    // -------------------------------------------------------------------------

    public static function premium_gate(array $atts, ?string $content = ''): string {
        $atts = shortcode_atts([
            'permission' => 'early_access',
            'message'    => '',
        ], $atts);

        $user_id = self::get_user_id();

        if ($user_id && WPA_Roles::can($user_id, $atts['permission'])) {
            return do_shortcode($content);
        }

        $default_msg = sprintf(
            /* translators: %s: login page link */
            __('This content is reserved for premium members. <a href="%s">Access your account</a>.', WPA_TEXT_DOMAIN),
            esc_url(home_url('/connexion-premium/'))
        );

        $msg = !empty($atts['message']) ? $atts['message'] : $default_msg;

        return '<div class="wpa-gate" data-wpa-theme>'
            . '<span class="wpa-gate__icon">&#9733;</span>'
            . '<p class="wpa-gate__message">' . wp_kses_post($msg) . '</p>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // [wpa_guest_only]content[/wpa_guest_only]
    // -------------------------------------------------------------------------

    public static function guest_only(array $atts, ?string $content = ''): string {
        $user_id = self::get_user_id();
        if ($user_id && WPA_Roles::is_premium($user_id)) return '';
        return do_shortcode($content);
    }

    // -------------------------------------------------------------------------
    // [wpa_account]
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // [wpa_account] — account panel
    //
    // Attributes:
    //   show_expiry      yes|no — show expiry date under each role. Default: yes
    //   show_devices     yes|no — show connected devices count + sign out. Default: yes
    //   early_access_url URL    — if set, show an "Early access" button. Default: empty (hidden)
    //   early_access_label text — button label. Default: "Early access"
    //   early_access_perm perm  — WPA permission required to show the button. Default: early_access
    // -------------------------------------------------------------------------

    public static function account(array $atts): string {
        $atts = shortcode_atts([
            'show_expiry'         => 'yes',
            'show_devices'        => 'yes',
            'early_access_url'    => '',
            'early_access_label'  => __('Early access', WPA_TEXT_DOMAIN),
            'early_access_perm'   => 'early_access',
        ], $atts);

        $user_id = self::get_user_id();

        if (!$user_id) {
            return '<div class="wpa-not-connected" data-wpa-theme>'
                . '<span class="wpa-not-connected__icon">&#128274;</span>'
                . '<div class="wpa-not-connected__body">'
                . '<p class="wpa-not-connected__text">'
                . esc_html__('You are not connected to your premium account.', WPA_TEXT_DOMAIN)
                . '</p>'
                . '<a href="' . esc_url(home_url('/connexion-premium/')) . '" class="wpa-btn wpa-btn--primary">'
                . esc_html__('Sign in', WPA_TEXT_DOMAIN)
                . '</a>'
                . '</div>'
                . '</div>';
        }

        $show_expiry      = $atts['show_expiry'] !== 'no';
        $show_devices     = $atts['show_devices'] !== 'no';
        $ea_url           = esc_url($atts['early_access_url']);
        $ea_label         = esc_html($atts['early_access_label']);
        $ea_perm          = sanitize_key($atts['early_access_perm']);
        $show_ea_btn      = !empty($ea_url) && WPA_Roles::can($user_id, $ea_perm);

        $user     = get_user_by('ID', $user_id);
        $sessions = WPA_Sessions::get_active($user_id);
        $sync_on  = (bool) get_user_meta($user_id, '_wpa_sync_enabled', true);

        // Get roles with expiry dates
        global $wpdb;
        $role_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT role, expires_at FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY expires_at ASC",
            $user_id
        ));

        ob_start(); ?>
        <div class="wpa-account" data-wpa-theme>

            <section class="wpa-account__section">
                <h3 class="wpa-account__heading"><?php esc_html_e('Your account', WPA_TEXT_DOMAIN); ?></h3>
                <p class="wpa-account__email"><?php echo esc_html($user->user_email ?? ''); ?></p>

                <?php if (!empty($role_rows)) : ?>
                <div class="wpa-account__roles">
                    <?php foreach ($role_rows as $row) :
                        $is_expiring = $row->expires_at && (strtotime($row->expires_at) - time()) < 7 * DAY_IN_SECONDS;
                        $is_lifetime = $row->expires_at === null;
                    ?>
                    <div class="wpa-account__role <?php echo $is_expiring ? 'wpa-account__role--expiring' : ''; ?>">
                        <span class="wpa-badge"><?php echo esc_html($row->role); ?></span>
                        <?php if ($show_expiry) : ?>
                            <span class="wpa-account__expiry">
                                <?php if ($is_lifetime) : ?>
                                    <span class="wpa-account__expiry--lifetime"><?php esc_html_e('Lifetime', WPA_TEXT_DOMAIN); ?></span>
                                <?php elseif ($is_expiring) : ?>
                                    <span class="wpa-account__expiry--soon">
                                        <?php printf(
                                            esc_html__('Expires %s', WPA_TEXT_DOMAIN),
                                            esc_html(date_i18n(get_option('date_format'), strtotime($row->expires_at)))
                                        ); ?>
                                    </span>
                                <?php else : ?>
                                    <?php printf(
                                        esc_html__('Until %s', WPA_TEXT_DOMAIN),
                                        esc_html(date_i18n(get_option('date_format'), strtotime($row->expires_at)))
                                    ); ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </section>

            <?php if ($show_ea_btn) : ?>
            <section class="wpa-account__section">
                <h3 class="wpa-account__heading"><?php esc_html_e('Premium content', WPA_TEXT_DOMAIN); ?></h3>
                <a href="<?php echo $ea_url; ?>" class="wpa-btn wpa-btn--secondary wpa-account__ea-btn">
                    &#9733; <?php echo $ea_label; ?>
                </a>
            </section>
            <?php endif; ?>

            <section class="wpa-account__section">
                <?php if ($show_devices) : ?>
                <h3 class="wpa-account__heading"><?php esc_html_e('Connected devices', WPA_TEXT_DOMAIN); ?></h3>
                <p class="wpa-hint">
                    <?php printf(
                        esc_html__('%d / 3 active sessions', WPA_TEXT_DOMAIN),
                        count($sessions)
                    ); ?>
                </p>
                <?php if (!empty($sessions)) : ?>
                <ul class="wpa-account__devices">
                    <?php foreach ($sessions as $i => $s) :
                        $is_current = isset($_COOKIE[WPA_Sessions::COOKIE_NAME])
                            && hash_equals(hash('sha256', sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME])), $s->token_hash ?? '');
                    ?>
                    <li class="wpa-account__device">
                        <span class="wpa-account__device-icon">&#128421;</span>
                        <span class="wpa-account__device-info">
                            <?php echo sprintf(esc_html__('Device %d', WPA_TEXT_DOMAIN), $i + 1); ?>
                            <?php if ($is_current) : ?>
                                <em><?php esc_html_e('(this device)', WPA_TEXT_DOMAIN); ?></em>
                            <?php endif; ?>
                        </span>
                        <span class="wpa-account__device-date">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($s->created_at))); ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php endif; // show_devices ?>
                <!-- Sign out button always visible regardless of show_devices -->
                <button class="wpa-btn wpa-btn--danger" id="wpa-logout" style="margin-top:.5rem">
                    <?php esc_html_e('Sign out of this device', WPA_TEXT_DOMAIN); ?>
                </button>
            </section>

            <?php if (WPA_Roles::can($user_id, 'sync')) : ?>
            <section class="wpa-account__section">
                <h3 class="wpa-account__heading"><?php esc_html_e('Synchronization', WPA_TEXT_DOMAIN); ?></h3>
                <p class="wpa-hint">
                    <?php esc_html_e('Sync your favorites and read-later list across devices.', WPA_TEXT_DOMAIN); ?>
                </p>
                <label class="wpa-toggle">
                    <input type="checkbox" id="wpa-sync-toggle" <?php checked($sync_on); ?>>
                    <span class="wpa-toggle__track"></span>
                    <span class="wpa-toggle__label">
                        <?php esc_html_e('Sync across devices', WPA_TEXT_DOMAIN); ?>
                    </span>
                </label>
            </section>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // [wpa_export_button id="123"]
    // -------------------------------------------------------------------------

    public static function export_button(array $atts): string {
        $atts    = shortcode_atts(['id' => get_the_ID()], $atts);
        $post_id = (int) $atts['id'];
        $user_id = self::get_user_id();

        if (!$user_id || !WPA_Roles::can($user_id, 'export_md')) return '';

        $url = rest_url("wpa/v1/export/{$post_id}");
        return '<a href="' . esc_url($url) . '" class="wpa-btn wpa-btn--secondary wpa-export-btn" download>'
            . '<span class="wpa-export-btn__icon">&#8595;</span> '
            . esc_html__('Export Markdown', WPA_TEXT_DOMAIN)
            . '</a>';
    }

    // -------------------------------------------------------------------------
    // [wpa_premium_badge]
    // -------------------------------------------------------------------------

    public static function premium_badge(array $atts): string {
        $user_id = self::get_user_id();
        if (!$user_id || !WPA_Roles::is_premium($user_id)) return '';

        return '<span class="wpa-premium-badge" title="' . esc_attr__('Premium member', WPA_TEXT_DOMAIN) . '">&#9733; '
            . esc_html__('Premium', WPA_TEXT_DOMAIN) . '</span>';
    }

    // -------------------------------------------------------------------------
    // [wpa_stripe_button price_id="price_xxx" label="Buy premium"]
    // -------------------------------------------------------------------------

    /**
     * Renders a Stripe Checkout button.
     * Redirects to Stripe hosted payment page on click.
     *
     * Attributes:
     *   price_id  — Stripe price ID (required)
     *   label     — Button text
     *   class     — Extra CSS classes
     */
    // -------------------------------------------------------------------------
    // [wpa_early_access] — list scheduled posts available to premium users
    //
    // Attributes:
    //   post_type  — post type slug. Default: post. Accepts comma-separated list.
    //   limit      — max number of posts to show. Default: 10.
    //   orderby    — date|title|rand. Default: date.
    //   order      — ASC|DESC. Default: ASC (soonest first).
    //   show_date  — yes|no — show scheduled publish date. Default: yes.
    //   show_type  — yes|no — show post type label. Default: no.
    //   gate_msg   — custom message shown to guests. Default: built-in.
    //   class      — extra CSS class on the wrapper.
    // -------------------------------------------------------------------------

    public static function early_access_posts(array $atts): string {
        $atts = shortcode_atts([
            'post_type' => 'post',
            'limit'     => 10,
            'orderby'   => 'date',
            'order'     => 'ASC',
            'show_date' => 'yes',
            'show_type' => 'no',
            'gate_msg'  => '',
            'class'     => '',
        ], $atts);

        // Check permission
        $user_id = self::get_user_id();
        $can     = $user_id && WPA_Roles::can($user_id, 'early_access');

        // Guest — show gate
        if (!$can) {
            $default_msg = sprintf(
                /* translators: %s: login page URL */
                __('This content is reserved for premium members. <a href="%s">Sign in</a> or <a href="%s">support us</a> to get early access.', WPA_TEXT_DOMAIN),
                esc_url(home_url('/connexion-premium/')),
                esc_url(get_option('wpa_kofi_url', '#'))
            );
            $msg = !empty($atts['gate_msg']) ? $atts['gate_msg'] : $default_msg;
            return '<div class="wpa-early-access-gate wpa-gate" data-wpa-theme>'
                . '<span class="wpa-gate__icon">&#9733;</span>'
                . '<p class="wpa-gate__message">' . wp_kses_post($msg) . '</p>'
                . '</div>';
        }

        // Parse post types
        $post_types = array_map('trim', explode(',', $atts['post_type']));
        $post_types = array_map('sanitize_key', $post_types);
        $post_types = array_filter($post_types);
        if (empty($post_types)) $post_types = ['post'];

        // Query scheduled posts only
        // post_status='future' already means publish_date > NOW() in WP
        // date_query adds explicit check to exclude any edge-case past-scheduled posts
        $query = new WP_Query([
            'post_type'        => $post_types,
            'post_status'      => 'future',
            'posts_per_page'   => (int) $atts['limit'],
            'orderby'          => in_array($atts['orderby'], ['date','title','rand'], true) ? $atts['orderby'] : 'date',
            'order'            => in_array(strtoupper($atts['order']), ['ASC','DESC']) ? strtoupper($atts['order']) : 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,  // do NOT let allow_scheduled_posts modify this query
            'date_query'       => [[
                'column'    => 'post_date',
                'after'     => current_time('mysql'),
                'inclusive' => false,
            ]],
        ]);

        if (!$query->have_posts()) {
            return '<p class="wpa-early-access-empty">'
                . esc_html__('No upcoming content at the moment.', WPA_TEXT_DOMAIN)
                . '</p>';
        }

        $show_date  = $atts['show_date'] !== 'no';
        $show_type  = $atts['show_type'] === 'yes';
        $extra_cls  = $atts['class'] ? ' ' . esc_attr($atts['class']) : '';
        $can_export = WPA_Roles::can($user_id, 'export_md');

        // Collect post IDs only for async REST fetch
        $post_ids = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_ids[] = get_the_ID();
            }
            wp_reset_postdata();
        }

        if (empty($post_ids)) {
            return '<p class="wpa-ea-empty">' . esc_html__('No upcoming content at the moment.', WPA_TEXT_DOMAIN) . '</p>';
        }

        $uid          = 'wpa-ea-' . substr(md5(serialize($atts)), 0, 6);
        $show_date_js = $show_date ? 'true' : 'false';
        $show_type_js = $show_type ? 'true' : 'false';
        $label_avail  = esc_js(__('Available', WPA_TEXT_DOMAIN));
        $label_export = esc_js(__('Export .md', WPA_TEXT_DOMAIN));
        $label_search = esc_attr__('Search articles...', WPA_TEXT_DOMAIN);
        $label_empty  = esc_js(__('No results found.', WPA_TEXT_DOMAIN));
        $label_error  = esc_js(__('Failed to load articles.', WPA_TEXT_DOMAIN));
        $rest_url     = esc_js(rest_url('wpa/v1/early-access'));
        $rest_params  = esc_js(http_build_query([
            'ids'   => implode(',', $post_ids),
            'nonce' => wp_create_nonce('wp_rest'),
        ]));

        ob_start(); ?>
        <div class="wpa-early-access<?php echo $extra_cls; ?>" id="<?php echo esc_attr($uid); ?>" data-wpa-theme>
            <div class="wpa-ea-search">
                <input type="search"
                       class="wpa-ea-search__input"
                       placeholder="<?php echo $label_search; ?>"
                       aria-label="<?php echo $label_search; ?>" />
            </div>
            <div class="wpa-ea-grid" aria-live="polite">
                <?php /* Skeleton cards shown before JS renders */ ?>
                <?php for ($s = 0; $s < min(3, count($post_ids ?? [])); $s++) : ?>
                <div class="wpa-ea-card wpa-ea-card--skeleton" aria-hidden="true">
                    <div class="wpa-ea-card__thumb-wrap wpa-skel"></div>
                    <div class="wpa-ea-card__body">
                        <div class="wpa-skel wpa-skel--text" style="width:60%"></div>
                        <div class="wpa-skel wpa-skel--title"></div>
                        <div class="wpa-skel wpa-skel--text"></div>
                        <div class="wpa-skel wpa-skel--text" style="width:80%"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
            <p class="wpa-ea-empty" style="display:none"><?php echo esc_html__('No upcoming content at the moment.', WPA_TEXT_DOMAIN); ?></p>
        </div>
        <script>
        (function () {
            var allPosts  = [];
            var container = document.getElementById('<?php echo esc_js($uid); ?>');
            var grid      = container.querySelector('.wpa-ea-grid');
            var emptyMsg  = container.querySelector('.wpa-ea-empty');
            var searchInput = container.querySelector('.wpa-ea-search__input');
            var showDate  = <?php echo $show_date_js; ?>;
            var showType  = <?php echo $show_type_js; ?>;

            function card(p) {
                var thumb = p.thumb
                    ? '<div class="wpa-ea-card__thumb-wrap"><img src="' + p.thumb + '" alt="" class="wpa-ea-card__thumb" loading="lazy"></div>'
                    : '<div class="wpa-ea-card__thumb-wrap wpa-ea-card__thumb--placeholder"></div>';
                var meta = '';
                if (showType && p.type)  meta += '<span class="wpa-early-access__type">' + p.type + '</span>';
                if (showDate && p.date)  meta += '<time datetime="' + p.date_iso + '" class="wpa-early-access__date"><?php echo $label_avail; ?> ' + p.date + '</time>';
                return '<article class="wpa-ea-card">'
                    + '<a href="' + p.permalink + '" class="wpa-ea-card__link" tabindex="-1" aria-hidden="true">' + thumb + '</a>'
                    + '<div class="wpa-ea-card__body">'
                    + '<div class="wpa-ea-card__meta">' + meta + '</div>'
                    + '<h3 class="wpa-ea-card__title"><a href="' + p.permalink + '">' + p.title + '</a></h3>'
                    + (p.excerpt ? '<p class="wpa-ea-card__excerpt">' + p.excerpt + '</p>' : '')
                    + '</div>'
                    + '</article>';
            }

            function render(filtered) {
                if (filtered.length === 0) {
                    grid.innerHTML = '';
                    emptyMsg.style.display = '';
                } else {
                    emptyMsg.style.display = 'none';
                    grid.innerHTML = filtered.map(card).join('');
                }
            }

            // Async fetch — skeletons stay visible until data arrives
            fetch('<?php echo $rest_url; ?>?<?php echo $rest_params; ?>', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>' }
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                allPosts = data.posts || [];
                render(allPosts);
            })
            .catch(function () {
                grid.innerHTML = '<p style="color:var(--wpa-danger);padding:1rem"><?php echo $label_error; ?></p>';
            });

            // Search
            var searchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    var q = searchInput.value.trim().toLowerCase();
                    if (!q) { render(allPosts); return; }
                    render(allPosts.filter(function (p) {
                        return p.title.toLowerCase().includes(q) || p.excerpt.toLowerCase().includes(q);
                    }));
                }, 200);
            });
        }());
        </script>
        <?php
        return ob_get_clean();
    }

    public static function stripe_button(array $atts): string {
        $atts = shortcode_atts([
            'price_id' => '',
            'label'    => __('Become a supporter', WPA_TEXT_DOMAIN),
            'class'    => '',
        ], $atts);

        if (empty($atts['price_id'])) return '';

        // Already premium — optionally hide
        $user_id = self::get_user_id();
        if ($user_id && WPA_Roles::is_premium($user_id)) {
            return '<p class="wpa-already-premium">'
                . esc_html__('You already have premium access.', WPA_TEXT_DOMAIN)
                . '</p>';
        }

        $extra = $atts['class'] ? ' ' . esc_attr($atts['class']) : '';

        return '<button
            class="wpa-btn wpa-btn--primary wpa-stripe-btn' . $extra . '"
            data-price-id="' . esc_attr($atts['price_id']) . '"
            data-checkout-url="' . esc_url(rest_url('wpa/v1/stripe/checkout')) . '"
            data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '"
        >'
        . esc_html($atts['label'])
        . '<span class="wpa-stripe-btn__spinner" aria-hidden="true" style="display:none"> ⟳</span>'
        . '</button>';
    }

}