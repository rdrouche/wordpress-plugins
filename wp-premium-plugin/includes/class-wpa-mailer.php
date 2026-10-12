<?php
defined('ABSPATH') || exit;

/**
 * WPA_Mailer — All transactional emails for WP Premium Access.
 *
 * Email types:
 *   magic_link      — login link (sent by WPA_MagicLink)
 *   welcome         — first premium login
 *   expiry_warning  — J-7 and J-1 before expiry
 *
 * Polylang: if active, subject/body are retrieved in the user's language.
 * Filters: wpa_mail_headers, wpa_mail_subject, wpa_mail_body
 */
class WPA_Mailer {

    public static function init(): void {
        add_action('wpa_daily_cron',   [self::class, 'run_expiry_warnings']);
        add_action('wpa_send_welcome', [self::class, 'send_welcome'], 10, 1);

        if (!wp_next_scheduled('wpa_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'wpa_daily_cron');
        }
    }

    // =========================================================================
    // Magic link email — called from WPA_MagicLink
    // =========================================================================

    public static function send_magic_link(\WP_User $user, string $link, string $ip, string $ua): bool {
        $site     = get_bloginfo('name');
        $hours    = WPA_MagicLink::TOKEN_EXPIRY_HOURS;

        // Polylang — switch to user's language if known
        self::maybe_switch_language($user);

        $first_name = self::get_first_name($user);
        $greeting   = $first_name
            ? sprintf(__('Hello %s,', WPA_TEXT_DOMAIN), $first_name)
            : __('Hello,', WPA_TEXT_DOMAIN);

        $subject = sprintf(__('[%s] Your login link', WPA_TEXT_DOMAIN), $site);
        $subject = apply_filters('wpa_mail_subject', $subject, 'magic_link', $user);

        // Split URL to defeat security gateways that rewrite <a> hrefs
        $mid      = (int)(strlen($link) / 2);
        $link_pt1 = substr($link, 0, $mid);
        $link_pt2 = substr($link, $mid);

        $btn_label      = __('Sign in to my account', WPA_TEXT_DOMAIN);
        $fallback_intro = sprintf(
            __('If the button does not work, copy and paste this link into your browser (valid %d hours, single use):', WPA_TEXT_DOMAIN),
            $hours
        );
        $security_msg   = __('If you did not request this link, ignore this email — your account is safe. No one can sign in without clicking the link.', WPA_TEXT_DOMAIN);
        $ip_label       = __('Request from IP:', WPA_TEXT_DOMAIN);
        $browser_label  = __('Browser:', WPA_TEXT_DOMAIN);

        // Sanitize UA for display
        $ua_display = strlen($ua) > 80 ? substr($ua, 0, 80) . '…' : $ua;

        $body = self::build_magic_link_body(
            $greeting, $link, $link_pt1, $link_pt2,
            $btn_label, $fallback_intro, $security_msg,
            $ip, $ua_display, $ip_label, $browser_label, $site
        );

        $result = self::send_html($user->user_email, $subject, $body, 'magic_link');

        self::maybe_restore_language();
        return $result;
    }

    private static function build_magic_link_body(
        string $greeting,
        string $link,
        string $link_pt1,
        string $link_pt2,
        string $btn_label,
        string $fallback_intro,
        string $security_msg,
        string $ip,
        string $ua,
        string $ip_label,
        string $browser_label,
        string $site
    ): string {
        $accent = get_option('wpa_email_accent_color', '#5046e5');
        return '
            <p style="margin:0 0 20px;font-size:16px">' . esc_html($greeting) . '</p>
            <p style="margin:0 0 28px">'
                . sprintf(
                    /* translators: %s: site name */
                    __('Click the button below to access your premium account on <strong>%s</strong>.', WPA_TEXT_DOMAIN),
                    esc_html($site)
                )
            . '</p>

            <!-- CTA button -->
            <table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px">
              <tr>
                <td style="background:' . esc_attr($accent) . ';border-radius:6px">
                  <a href="' . esc_url($link) . '"
                     style="display:inline-block;padding:14px 36px;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;border-radius:6px;letter-spacing:.02em">'
                     . esc_html($btn_label) . ' &rarr;
                  </a>
                </td>
              </tr>
            </table>

            <!-- Plain text fallback — URL split to avoid gateway rewriting -->
            <p style="margin:0 0 8px;font-size:13px;color:#6b7280">' . esc_html($fallback_intro) . '</p>
            <p style="margin:0 0 28px;font-size:12px;font-family:monospace;word-break:break-all;color:#374151;background:#f3f4f6;padding:10px 12px;border-radius:4px;border:1px solid #e5e7eb">'
                . esc_html($link_pt1) . '<wbr>' . esc_html($link_pt2) .
            '</p>

            <!-- Security info -->
            <div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#6b7280">
              <p style="margin:0 0 6px;font-weight:600;color:#374151">&#x1F6E1; ' . esc_html(__('Security information', WPA_TEXT_DOMAIN)) . '</p>
              <p style="margin:0 0 4px">' . esc_html($ip_label) . ' <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px">' . esc_html($ip) . '</code></p>
              <p style="margin:0 0 8px">' . esc_html($browser_label) . ' <span style="color:#374151">' . esc_html($ua) . '</span></p>
              <p style="margin:0;font-style:italic">' . esc_html($security_msg) . '</p>
            </div>';
    }

    // =========================================================================
    // Welcome email
    // =========================================================================

    public static function send_welcome(int $user_id): void {
        if (get_user_meta($user_id, '_wpa_welcome_sent', true)) return;

        $user = get_user_by('ID', $user_id);
        if (!$user) return;

        self::maybe_switch_language($user);

        $site       = get_bloginfo('name');
        $login_url  = home_url('/connexion-premium/');
        $first_name = self::get_first_name($user);
        $greeting   = $first_name
            ? sprintf(__('Hello %s,', WPA_TEXT_DOMAIN), $first_name)
            : __('Hello,', WPA_TEXT_DOMAIN);

        $subject = get_option('wpa_email_subject_welcome',
            sprintf(__('[%s] Welcome to your premium account!', WPA_TEXT_DOMAIN), $site)
        );
        $subject = self::interpolate($subject, $user);
        $subject = apply_filters('wpa_mail_subject', $subject, 'welcome', $user);

        $stored_body = (string) (get_option('wpa_email_body_welcome', '') ?: '');
        if ($stored_body) {
            $body_html = wpautop(self::interpolate($stored_body, $user));
        } else {
            $accent = get_option('wpa_email_accent_color', '#5046e5');
            $body_html = '
                <p style="margin:0 0 20px;font-size:16px">' . esc_html($greeting) . '</p>
                <p style="margin:0 0 16px">'
                    . sprintf(
                        __('Your premium access on <strong>%s</strong> is now active. Welcome!', WPA_TEXT_DOMAIN),
                        esc_html($site)
                    )
                . '</p>
                <p style="margin:0 0 28px">' . __('You can sign in at any time using a magic link:', WPA_TEXT_DOMAIN) . '</p>
                <table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px">
                  <tr>
                    <td style="background:' . esc_attr($accent) . ';border-radius:6px">
                      <a href="' . esc_url($login_url) . '"
                         style="display:inline-block;padding:14px 36px;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;border-radius:6px">'
                         . __('Access my account', WPA_TEXT_DOMAIN) . ' &rarr;
                      </a>
                    </td>
                  </tr>
                </table>
                <p style="margin:0;font-size:14px;color:#6b7280">'
                    . sprintf(__('Thank you for your support! If you have any questions, just reply to this email.', WPA_TEXT_DOMAIN))
                . '</p>';
        }

        $body_html = apply_filters('wpa_mail_body', $body_html, 'welcome', $user, []);

        if (self::send_html($user->user_email, $subject, $body_html, 'welcome')) {
            update_user_meta($user_id, '_wpa_welcome_sent', 1);
        }

        self::maybe_restore_language();
    }

    // =========================================================================
    // Expiry warning cron
    // =========================================================================

    public static function run_expiry_warnings(): void {
        global $wpdb;

        $windows = [
            ['days' => 7, 'meta_key' => '_wpa_warn_7d'],
            ['days' => 1, 'meta_key' => '_wpa_warn_1d'],
        ];

        foreach ($windows as $w) {
            $start = date('Y-m-d 00:00:00', strtotime("+{$w['days']} days"));
            $end   = date('Y-m-d 23:59:59', strtotime("+{$w['days']} days"));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT r.user_id, MIN(r.expires_at) as expires_at
                 FROM {$wpdb->prefix}wpa_roles r
                 WHERE r.expires_at BETWEEN %s AND %s
                 GROUP BY r.user_id",
                $start, $end
            ));

            foreach ($rows as $row) {
                if (get_user_meta($row->user_id, $w['meta_key'], true)) continue;

                $user = get_user_by('ID', $row->user_id);
                if (!$user) continue;

                self::maybe_switch_language($user);
                self::send_expiry_warning($user, $w['days'], $row->expires_at);
                update_user_meta($row->user_id, $w['meta_key'], current_time('mysql'));
                self::maybe_restore_language();
            }
        }
    }

    private static function send_expiry_warning(\WP_User $user, int $days, string $expires_at): void {
        $site       = get_bloginfo('name');
        $kofi_url   = get_option('wpa_kofi_url', 'https://ko-fi.com');
        $expires    = date_i18n(get_option('date_format'), strtotime($expires_at));
        $first_name = self::get_first_name($user);
        $greeting   = $first_name
            ? sprintf(__('Hello %s,', WPA_TEXT_DOMAIN), $first_name)
            : __('Hello,', WPA_TEXT_DOMAIN);

        $vars    = ['days' => $days, 'expires_at' => $expires_at];
        $subject = get_option('wpa_email_subject_expiry_warning',
            sprintf(__('[%s] Your premium access expires soon', WPA_TEXT_DOMAIN), $site)
        );
        $subject = self::interpolate($subject, $user, $vars);
        $subject = apply_filters('wpa_mail_subject', $subject, 'expiry_warning', $user);

        $stored_body = (string) (get_option('wpa_email_body_expiry_warning', '') ?: '');
        if ($stored_body) {
            $body_html = wpautop(self::interpolate($stored_body, $user, $vars));
        } else {
            $accent = get_option('wpa_email_accent_color', '#5046e5');
            $day_str = $days === 1
                ? __('tomorrow', WPA_TEXT_DOMAIN)
                : sprintf(__('in %d days', WPA_TEXT_DOMAIN), $days);

            $body_html = '
                <p style="margin:0 0 20px;font-size:16px">' . esc_html($greeting) . '</p>
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:14px 16px;margin-bottom:20px">
                  <p style="margin:0;font-weight:600;color:#c2410c">&#x26A0; '
                    . sprintf(
                        __('Your premium access on %s expires %s (%s).', WPA_TEXT_DOMAIN),
                        '<strong>' . esc_html($site) . '</strong>',
                        '<strong>' . esc_html($day_str) . '</strong>',
                        '<strong>' . esc_html($expires) . '</strong>'
                    )
                  . '</p>
                </div>
                <p style="margin:0 0 28px">' . __('Renew your support to keep your premium access:', WPA_TEXT_DOMAIN) . '</p>
                <table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px">
                  <tr>
                    <td style="background:' . esc_attr($accent) . ';border-radius:6px">
                      <a href="' . esc_url($kofi_url) . '"
                         style="display:inline-block;padding:14px 36px;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;border-radius:6px">'
                         . __('Renew my support', WPA_TEXT_DOMAIN) . ' &rarr;
                      </a>
                    </td>
                  </tr>
                </table>';
        }

        $body_html = apply_filters('wpa_mail_body', $body_html, 'expiry_warning', $user, $vars);
        self::send_html($user->user_email, $subject, $body_html, 'expiry_warning');
    }

    // =========================================================================
    // Manual send
    // =========================================================================

    public static function send_manual(
        string $target_type,
        string $target,
        string $subject,
        string $body,
        string $format
    ): array {
        global $wpdb;

        $recipients = [];

        if ($target_type === 'email') {
            $user = get_user_by('email', sanitize_email($target));
            if ($user) $recipients[] = $user->user_email;
        } else {
            $recipients = $wpdb->get_col($wpdb->prepare(
                "SELECT u.user_email
                 FROM {$wpdb->prefix}wpa_roles r
                 JOIN {$wpdb->users} u ON u.ID = r.user_id
                 WHERE r.role = %s
                   AND (r.expires_at IS NULL OR r.expires_at > NOW())",
                $target
            ));
        }

        $sent = $failed = 0;

        foreach ($recipients as $email) {
            if ($sent > 0 && $sent % 50 === 0) sleep(1);
            if ($format === 'html') {
                self::send_html($email, $subject, wpautop($body), 'manual') ? $sent++ : $failed++;
            } else {
                self::send_plain($email, $subject, $body) ? $sent++ : $failed++;
            }
        }

        self::log_campaign($target_type, $target, $subject, $sent, $failed);
        return [$sent, $failed];
    }

    // =========================================================================
    // Core send methods
    // =========================================================================

    /**
     * Send an HTML email wrapped in the branded template.
     */
    public static function send_html(string $to, string $subject, string $body, string $type = 'generic'): bool {
        $body = $body ?? ''; // guard against null
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $headers = apply_filters('wpa_mail_headers', $headers, $type);

        // Ensure wp_mail sends HTML
        add_filter('wp_mail_content_type', fn() => 'text/html');
        $result = wp_mail($to, $subject, self::wrap_html($body), $headers);
        remove_filter('wp_mail_content_type', fn() => 'text/html');

        return $result;
    }

    public static function send_plain(string $to, string $subject, string $body): bool {
        return wp_mail($to, $subject, $body);
    }

    // =========================================================================
    // HTML email template — branded, dark-mode aware
    // =========================================================================

    public static function wrap_html(string $body): string {
        $body = $body ?? '';
        $site     = get_bloginfo('name');
        $site_url = home_url('/');
        $accent   = get_option('wpa_email_accent_color', '#5046e5') ?: '#5046e5';
        $logo_url = get_option('wpa_email_logo_url', '');
        $year     = date('Y');
        $lang     = function_exists('pll_current_language') ? pll_current_language('slug') : get_locale();

        $logo_html = $logo_url
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site) . '" height="36" style="display:block;height:36px;width:auto">'
            : '<span style="color:#ffffff;font-size:1.2rem;font-weight:700;letter-spacing:.02em">' . esc_html($site) . '</span>';

        // Accent darker variant for hover-like uses
        $accent_dark = self::darken_hex($accent, 15);

        return '<!DOCTYPE html>
<html lang="' . esc_attr($lang) . '" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <title>' . esc_html($site) . '</title>
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <style>
    @media (prefers-color-scheme: dark) {
      .email-bg    { background:#0f1117 !important }
      .email-card  { background:#1c1f2e !important;border-color:#2d3148 !important }
      .email-body  { color:#e2e4ef !important }
      .email-footer{ background:#131622 !important;border-color:#2d3148 !important;color:#6b7280 !important }
      .email-footer a { color:#6b7280 !important }
    }
    body,table,td,a { -webkit-text-size-adjust:100%;-ms-text-size-adjust:100% }
    a { color:' . esc_attr($accent) . ' }
    a:hover { color:' . esc_attr($accent_dark) . ' }
  </style>
</head>
<body class="email-bg" style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;font-size:15px;color:#1a1d23">

  <table class="email-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f5f7;padding:32px 16px">
    <tr><td align="center">
      <table class="email-card" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">

        <!-- Header -->
        <tr>
          <td style="background:' . esc_attr($accent) . ';padding:20px 32px">
            <a href="' . esc_url($site_url) . '" style="text-decoration:none;display:inline-block">
              ' . $logo_html . '
            </a>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td class="email-body" style="padding:32px 32px 28px;line-height:1.75;color:#1a1d23;font-size:15px">
            ' . $body . '
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td class="email-footer" style="padding:16px 32px;background:#f9fafb;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;line-height:1.6">
            &copy; ' . $year . ' <a href="' . esc_url($site_url) . '" style="color:#9ca3af;text-decoration:none">' . esc_html($site) . '</a>
            &mdash; ' . esc_html(parse_url($site_url, PHP_URL_HOST)) . '
          </td>
        </tr>

      </table>
    </td></tr>
  </table>

</body>
</html>';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public static function interpolate(string $tpl, \WP_User $user, array $vars = []): string {
        $first_name = self::get_first_name($user);
        $expires    = isset($vars['expires_at'])
            ? date_i18n(get_option('date_format'), strtotime($vars['expires_at']))
            : '';
        $replacements = [
            '{{site}}'       => get_bloginfo('name'),
            '{{first_name}}' => $first_name,
            '{{email}}'      => $user->user_email,
            '{{login_url}}'  => home_url('/connexion-premium/'),
            '{{kofi_url}}'   => get_option('wpa_kofi_url', 'https://ko-fi.com'),
            '{{days}}'       => (string) ($vars['days'] ?? ''),
            '{{expires_at}}' => $expires,
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $tpl);
    }

    public static function get_first_name(\WP_User $user): string {
        $first = get_user_meta($user->ID, 'first_name', true);
        if ($first) return $first;
        // Fallback: use part before @ in email
        $parts = explode('@', $user->user_email);
        $name  = ucfirst(preg_replace('/[^a-zA-ZÀ-ÿ]/', '', $parts[0]));
        return $name ?: '';
    }

    /**
     * Darken a hex color by $percent (0-100).
     */
    private static function darken_hex(string $hex, int $percent): string {
        $hex = $hex ?? '#5046e5';
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = max(0, hexdec(substr($hex, 0, 2)) - round(255 * $percent / 100));
        $g = max(0, hexdec(substr($hex, 2, 2)) - round(255 * $percent / 100));
        $b = max(0, hexdec(substr($hex, 4, 2)) - round(255 * $percent / 100));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    // =========================================================================
    // Polylang support
    // =========================================================================

    private static string $lang_before = '';

    private static function maybe_switch_language(\WP_User $user): void {
        if (!function_exists('pll_get_post_language') || !function_exists('PLL')) return;
        $user_lang = get_user_meta($user->ID, 'locale', true);
        if (!$user_lang) return;
        self::$lang_before = function_exists('pll_current_language') ? (pll_current_language() ?: '') : '';
        // Switch locale for translations
        switch_to_locale($user_lang);
    }

    private static function maybe_restore_language(): void {
        restore_previous_locale();
    }

    // =========================================================================
    // Campaign log
    // =========================================================================

    private static function log_campaign(string $target_type, string $target, string $subject, int $sent, int $failed): void {
        $log = get_option('wpa_email_campaign_log', []);
        array_unshift($log, [
            'date'        => current_time('mysql'),
            'target_type' => $target_type,
            'target'      => $target,
            'subject'     => $subject,
            'sent'        => $sent,
            'failed'      => $failed,
        ]);
        update_option('wpa_email_campaign_log', array_slice($log, 0, 50));
    }
}
