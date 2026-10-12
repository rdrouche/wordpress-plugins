<?php
defined('ABSPATH') || exit;

class WPA_Content {

    /**
     * Resolve current user ID — tries WPA session cookie first,
     * then falls back to WP native session (for WP admins not using WPA login).
     */
    private static function get_user_id(): int {
        $user_id = WPA_Sessions::current_user_id();
        if (!$user_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
        }
        return (int) $user_id;
    }

    public static function init(): void {
        add_action('pre_get_posts',  [self::class, 'allow_scheduled_posts']);
        add_action('wp',             [self::class, 'allow_singular_future_post']);
        add_filter('the_content',    [self::class, 'gate_content']);
        add_action('rest_api_init',  [self::class, 'register_early_access_route']);
        add_action('rest_api_init',  [self::class, 'register_export_route']);
        add_action('wp_footer',      [self::class, 'inject_floating_export_button']);
        // Note: ads are managed by a separate plugin.
        // The 'no_ads' permission is available via WPA_Roles::can($user_id, 'no_ads').
    }

    // -------------------------------------------------------------------------
    // Allow premium to view singular future posts directly
    // -------------------------------------------------------------------------

    public static function allow_singular_future_post(): void {
        global $wp_query;
        if (!$wp_query->is_singular()) return;
        $post = get_queried_object();
        if (!$post instanceof WP_Post) return;
        if ($post->post_status !== 'future') return;
        $user_id = self::get_user_id();
        if (!$user_id || !WPA_Roles::can($user_id, 'early_access')) return;
        $wp_query->is_404      = false;
        $wp_query->is_singular = true;
        $wp_query->is_single   = ($post->post_type === 'post');
        $wp_query->is_page     = ($post->post_type === 'page');
        $wp_query->queried_object = $post;
        status_header(200);
    }

    // -------------------------------------------------------------------------
    // Early access — show scheduled posts to premium users
    // -------------------------------------------------------------------------

    public static function allow_scheduled_posts(\WP_Query $query): void {
        if (is_admin()) return;

        // Never touch preview requests — let WP handle them natively
        if (isset($_GET['preview']) || isset($_GET['preview_id']) || isset($_GET['preview_nonce'])) return;

        // ONLY apply to the main query — never touch secondary/custom queries
        if (!$query->is_main_query()) return;

        // Only on singular post views or archive/home listing pages
        if (!$query->is_singular() && !$query->is_home() && !$query->is_archive() && !$query->is_search()) return;

        // WP admins always see future posts
        if (is_user_logged_in() && current_user_can('manage_options')) {
            $current_statuses = (array) ($query->get('post_status') ?: ['publish']);
            if (!in_array('future', $current_statuses, true)) {
                $current_statuses[] = 'future';
                $query->set('post_status', $current_statuses);
            }
            return;
        }

        $user_id = self::get_user_id();
        if (!$user_id || !WPA_Roles::can($user_id, 'early_access')) return;

        $current_statuses = (array) ($query->get('post_status') ?: ['publish']);
        if (!in_array('future', $current_statuses, true)) {
            $current_statuses[] = 'future';
            $query->set('post_status', $current_statuses);
        }
    }

    // -------------------------------------------------------------------------
    // Content gating
    // -------------------------------------------------------------------------

    public static function gate_content(string $content): string {
        global $post;
        if (!$post || $post->post_status !== 'future') return $content;
        $user_id = self::get_user_id();
        if ($user_id && WPA_Roles::can($user_id, 'early_access')) return $content;
        $teaser      = wp_trim_words($content, 40);
        $login_url   = home_url('/connexion-premium/');
        $template    = '<div class="wpa-gate" data-wpa-theme>'
            . '<span class="wpa-gate__icon">&#9733;</span>'
            . '<p class="wpa-gate__message">'
            . sprintf(
                /* translators: %s: login page URL */
                __('This content is reserved for premium members. <a href="%s">Access your account</a>.', WPA_TEXT_DOMAIN),
                esc_url($login_url)
              )
            . '</p></div>';
        return $teaser . $template;
    }

    // -------------------------------------------------------------------------
    // Floating export button
    // -------------------------------------------------------------------------

    public static function inject_floating_export_button(): void {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post) return;
        if (!self::is_exportable_post_type($post->post_type)) return;
        if (!in_array($post->post_status, ['publish', 'future'], true)) return;
        $user_id = self::get_user_id();
        if (!$user_id || !WPA_Roles::can($user_id, 'export_md')) return;
        if ($post->post_status === 'future' && !WPA_Roles::can($user_id, 'early_access')) return;

        $export_url = rest_url('wpa/v1/export/' . $post->ID);
        $label      = esc_html__('Export Markdown', WPA_TEXT_DOMAIN);
        $title      = esc_attr__('Download this article as a Markdown file', WPA_TEXT_DOMAIN);
        ?>
        <button
            class="wpa-export-float"
            id="wpa-export-float-btn"
            data-export-url="<?php echo esc_url($export_url); ?>"
            title="<?php echo $title; ?>"
            aria-label="<?php echo $title; ?>"
        >
            <span class="wpa-export-float__icon" aria-hidden="true">&#8595; .md</span>
            <span class="wpa-export-float__label"><?php echo $label; ?></span>
        </button>
        <script>
        (function () {
            var btn = document.getElementById('wpa-export-float-btn');
            if (!btn) return;

            function register() {
                if (window.rdrFloatingStack) {
                    window.rdrFloatingStack.register(btn, 'right', 70);
                } else {
                    btn.style.position = 'fixed';
                    btn.style.bottom   = '24px';
                    btn.style.right    = '24px';
                    btn.style.zIndex   = '9990';
                    setTimeout(function () {
                        if (window.rdrFloatingStack) {
                            btn.style.position = '';
                            btn.style.bottom   = '';
                            btn.style.right    = '';
                            window.rdrFloatingStack.register(btn, 'right', 70);
                        }
                    }, 300);
                }
            }

            btn.addEventListener('click', function () {
                var url = btn.dataset.exportUrl;
                btn.setAttribute('aria-busy', 'true');
                btn.disabled = true;

                fetch(url, { method: 'GET', credentials: 'same-origin' })
                    .then(function (res) {
                        if (!res.ok) throw new Error('Export failed');
                        return res.blob();
                    })
                    .then(function (blob) {
                        var a   = document.createElement('a');
                        a.href  = URL.createObjectURL(blob);
                        a.download = document.title.replace(/[^a-z0-9\-_]/gi, '-').toLowerCase() + '.md';
                        a.click();
                        URL.revokeObjectURL(a.href);
                    })
                    .catch(function () {
                        alert('<?php echo esc_js(__('Export failed. Please try again.', WPA_TEXT_DOMAIN)); ?>');
                    })
                    .finally(function () {
                        btn.removeAttribute('aria-busy');
                        btn.disabled = false;
                    });
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', register);
            } else {
                register();
            }
        }());
        </script>
        <?php
    }

    public static function is_exportable_post_type(string $post_type): bool {
        $allowed = get_option('wpa_export_post_types', ['post']);
        return in_array($post_type, (array) $allowed, true);
    }

    // -------------------------------------------------------------------------
    // Export Markdown — premium only
    // -------------------------------------------------------------------------

    public static function register_early_access_route(): void {
        register_rest_route('wpa/v1', '/early-access', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'rest_early_access_posts'],
            'permission_callback' => '__return_true',
            'args'                => [
                'ids' => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    /**
     * Return post data for early access cards.
     * Only serves posts that are genuinely future-dated.
     */
    public static function rest_early_access_posts(\WP_REST_Request $request): \WP_REST_Response {
        $token   = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        $user_id = WPA_Sessions::validate($token);

        if (!$user_id || !WPA_Roles::can($user_id, 'early_access')) {
            return new \WP_REST_Response(['error' => 'Forbidden'], 403);
        }

        $ids_raw = sanitize_text_field($request->get_param('ids') ?? '');
        $ids     = array_filter(array_map('intval', explode(',', $ids_raw)));

        if (empty($ids)) {
            return new \WP_REST_Response(['posts' => []], 200);
        }

        $posts_data = [];
        foreach ($ids as $pid) {
            $post = get_post($pid);
            if (!$post || $post->post_status !== 'future') continue;
            // Extra safety — must still be in the future
            if (strtotime($post->post_date) <= time()) continue;

            $thumb  = get_the_post_thumbnail_url($pid, 'medium') ?: '';
            $pt_obj = get_post_type_object($post->post_type);
            $posts_data[] = [
                'id'        => $pid,
                'title'     => get_the_title($post),
                'excerpt'   => wp_trim_words(
                    $post->post_excerpt ?: wp_strip_all_tags($post->post_content), 20
                ),
                'permalink' => get_permalink($post),
                'date'      => get_the_date(get_option('date_format'), $post),
                'date_iso'  => get_the_date('c', $post),
                'type'      => $pt_obj ? $pt_obj->labels->singular_name : '',
                'thumb'     => $thumb,
            ];
        }

        return new \WP_REST_Response(['posts' => $posts_data], 200);
    }

    public static function register_export_route(): void {
        register_rest_route('wpa/v1', '/export/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'export_markdown'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => ['validate_callback' => fn($v) => is_numeric($v)],
            ],
        ]);
    }

    public static function export_markdown(\WP_REST_Request $request): mixed {
        $token   = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        $user_id = WPA_Sessions::validate($token);

        if (!$user_id || !WPA_Roles::can($user_id, 'export_md')) {
            return new \WP_REST_Response(
                ['error' => __('Premium access required.', WPA_TEXT_DOMAIN)],
                403
            );
        }

        $post_id = (int) $request->get_param('id');
        $post    = get_post($post_id);

        if (!$post || !in_array($post->post_status, ['publish', 'future'], true)) {
            return new \WP_REST_Response(['error' => __('Post not found.', WPA_TEXT_DOMAIN)], 404);
        }

        $md = self::post_to_markdown($post);

        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($post->post_title) . '.md"');
        header('X-Robots-Tag: noindex');
        echo $md;
        exit;
    }

    // -------------------------------------------------------------------------
    // HTML → Markdown conversion
    // -------------------------------------------------------------------------

    private static function post_to_markdown(\WP_Post $post): string {
        $content = $post->post_content;
        $content = do_shortcode($content);

        // --- Step 1: fenced code blocks ---
        $content = preg_replace_callback(
            '#<pre[^>]*>\s*<code([^>]*)>(.*?)</code>\s*</pre>#si',
            function ($m) {
                $attrs = $m[1];
                $code  = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $lang  = '';
                // Detect language via stripos — no mixed quotes
                if (stripos($attrs, 'language-') !== false && preg_match('~language-([a-z0-9_+\-]+)~i', $attrs, $lm)) {
                    $lang = $lm[1];
                } elseif (stripos($attrs, 'lang-') !== false && preg_match('~lang-([a-z0-9_+\-]+)~i', $attrs, $lm)) {
                    $lang = $lm[1];
                }
                return "\n\n```" . $lang . "\n" . trim($code) . "\n```\n\n";
            },
            $content
        );

        // <pre> without code
        $content = preg_replace_callback(
            '#<pre[^>]*>(.*?)</pre>#si',
            function ($m) {
                $code = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
                return "\n\n```\n" . trim($code) . "\n```\n\n";
            },
            $content
        );

        // --- Step 2: figures with images ---
        $content = preg_replace_callback(
            '#<figure[^>]*>(.*?)</figure>#si',
            function ($m) {
                $inner   = $m[1];
                $img_md  = '';
                $caption = '';

                // Extract img tag
                if (preg_match('#<img[^>]+>#i', $inner, $itag)) {
                    $img_tag = $itag[0];
                    $src = '';
                    $alt = '';
                    if (preg_match('~\bsrc=([^\s>]+)~i', $img_tag, $sm)) {
                        $src = trim($sm[1], "\"' ");
                    }
                    if (preg_match('~\balt=([^\s>]+)~i', $img_tag, $am)) {
                        $alt = trim($am[1], "\"' ");
                    }
                    if ($src) $img_md = "![{$alt}]({$src})";
                }

                // Extract figcaption
                if (preg_match('#<figcaption[^>]*>(.*?)</figcaption>#si', $inner, $cm)) {
                    $caption = strip_tags($cm[1]);
                }

                $out = $img_md ? "\n\n{$img_md}" : '';
                if ($caption) $out .= "\n*{$caption}*";
                return $out . "\n\n";
            },
            $content
        );

        // --- Step 3: standalone images ---
        $content = preg_replace_callback(
            '#<img[^>]+>#i',
            function ($m) {
                $tag = $m[0];
                $src = '';
                $alt = '';
                if (preg_match('~\bsrc=([^\s>]+)~i', $tag, $sm)) {
                    $src = trim($sm[1], "\"' ");
                }
                if (preg_match('~\balt=([^\s>]+)~i', $tag, $am)) {
                    $alt = trim($am[1], "\"' ");
                }
                return $src ? "![{$alt}]({$src})" : '';
            },
            $content
        );

        // --- Step 4: headings ---
        for ($i = 6; $i >= 1; $i--) {
            $hashes  = str_repeat('#', $i);
            $content = preg_replace("#<h{$i}[^>]*>(.*?)</h{$i}>#si", "\n\n{$hashes} \$1\n\n", $content);
        }

        // --- Step 5: links ---
        $content = preg_replace_callback(
            '#<a[^>]+href=([^\s>]+)[^>]*>(.*?)</a>#si',
            function ($m) {
                $url  = trim($m[1], "\"' ");
                $text = strip_tags($m[2]);
                return $text ? "[{$text}]({$url})" : $url;
            },
            $content
        );

        // --- Step 6: inline formatting ---
        $content = preg_replace(['#<strong[^>]*>(.*?)</strong>#si', '#<b[^>]*>(.*?)</b>#si'], '**$1**', $content);
        $content = preg_replace(['#<em[^>]*>(.*?)</em>#si', '#<i[^>]*>(.*?)</i>#si'], '*$1*', $content);
        $content = preg_replace('#<code[^>]*>(.*?)</code>#si', '`$1`', $content);

        // --- Step 7: blockquotes ---
        $content = preg_replace_callback(
            '#<blockquote[^>]*>(.*?)</blockquote>#si',
            function ($m) {
                $inner = trim(strip_tags($m[1]));
                $lines = explode("\n", $inner);
                return "\n\n" . implode("\n", array_map(fn($l) => '> ' . $l, $lines)) . "\n\n";
            },
            $content
        );

        // --- Step 8: ordered lists ---
        $content = preg_replace_callback(
            '#<ol[^>]*>(.*?)</ol>#si',
            function ($m) {
                $items = [];
                $n     = 1;
                preg_replace_callback('#<li[^>]*>(.*?)</li>#si', function ($lm) use (&$items, &$n) {
                    $items[] = $n++ . '. ' . trim(strip_tags($lm[1]));
                }, $m[1]);
                return "\n\n" . implode("\n", $items) . "\n\n";
            },
            $content
        );

        // --- Step 9: unordered lists ---
        $content = preg_replace_callback(
            '#<ul[^>]*>(.*?)</ul>#si',
            function ($m) {
                $items = [];
                preg_replace_callback('#<li[^>]*>(.*?)</li>#si', function ($lm) use (&$items) {
                    $items[] = '- ' . trim(strip_tags($lm[1]));
                }, $m[1]);
                return "\n\n" . implode("\n", $items) . "\n\n";
            },
            $content
        );

        // --- Step 10: paragraphs and breaks ---
        $content = preg_replace('#<p[^>]*>(.*?)</p>#si', "$1\n\n", $content);
        $content = preg_replace('#<br\s*/?>#i', "\n", $content);

        // --- Step 11: strip remaining tags ---
        $content = strip_tags($content);

        // --- Step 12: decode entities ---
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // --- Step 13: clean whitespace ---
        $content = str_replace("\r\n", "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        $date   = get_the_date('Y-m-d', $post);
        $author = get_the_author_meta('display_name', $post->post_author);
        $url    = get_permalink($post);

        return "# {$post->post_title}\n\n"
            . "_Published: {$date} — {$author}_  \n"
            . "_Source: {$url}_\n\n"
            . "---\n\n"
            . trim($content) . "\n";
    }
}
