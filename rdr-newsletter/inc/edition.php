<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   CPT rdrnl_edition  — web publication of each newsletter issue
   URL: /newsletter/{slug}/   Archive: /newsletter/
   ============================================================ */
add_action( 'init', 'rdrnl_register_edition_cpt' );
/* ── Tell GeneratePress to use right-sidebar layout ── */
add_filter( 'generate_sidebar_layout', 'rdrnl_gp_sidebar_layout' );
function rdrnl_gp_sidebar_layout( $layout ) {
    // CPT pages
    // Single edition pages
    if ( is_singular('rdrnl_edition') || is_singular('rdrnl_profile') ) {
        return 'right-sidebar';
    }

    // WP pages using our shortcodes
    if ( is_page() ) {
        global $post;
        if ( $post && (
            has_shortcode($post->post_content, 'rdrnl_newsletter_page') ||
            has_shortcode($post->post_content, 'rdrnl_subscribe') ||
            has_shortcode($post->post_content, 'rdrnl_newsletter') ||
            has_shortcode($post->post_content, 'rdrnl_profile_request')
        )) {
            return 'right-sidebar';
        }
    }

    return $layout;
}

// Enqueue front.css on edition pages (single + archive)
add_action( 'wp_enqueue_scripts', 'rdrnl_edition_assets' );
function rdrnl_edition_assets() {
    if ( is_singular('rdrnl_edition') || is_post_type_archive('rdrnl_edition') ) {
        $ver = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.css' );
        wp_enqueue_style( 'rdrnl-front', RDRNL_URL . 'assets/front.css', [], $ver );
    }
}


function rdrnl_register_edition_cpt() {
    register_post_type( 'rdrnl_edition', [
        'labels'      => [
            'name'          => 'Éditions newsletter',
            'singular_name' => 'Édition newsletter',
            'add_new_item'  => 'Nouvelle édition',
            'edit_item'     => 'Modifier l\'édition',
        ],
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => 'rdr-newsletter',
        'query_var'          => true,
        'rewrite'            => [ 'slug' => 'editions', 'with_front' => false ],
        'capability_type'    => 'post',
        'has_archive'        => false,  // Archive via WP page with [rdrnl_newsletter_page]
        'hierarchical'       => false,
        'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
        'show_in_feed'       => false, // excluded from main WP feed
    ]);
}

/* ── RSS feed for editions ── */
add_action( 'init', 'rdrnl_add_edition_feed' );
function rdrnl_add_edition_feed() {
    add_feed( 'rdrnl-editions', 'rdrnl_edition_feed_handler' );
}

function rdrnl_edition_feed_handler() {
    $editions = get_posts([
        'post_type'      => 'rdrnl_edition',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?php echo esc_html(get_bloginfo('name')); ?> — Newsletter</title>
    <link><?php echo esc_url(home_url('/editions/')); ?></link>
    <description>Récap hebdomadaire IT — <?php echo esc_html(get_bloginfo('name')); ?></description>
    <language>fr-FR</language>
    <atom:link href="<?php echo esc_url(home_url('/feed/rdrnl-editions/')); ?>" rel="self" type="application/rss+xml"/>
    <?php foreach ($editions as $e) : ?>
    <item>
      <title><?php echo esc_html(get_the_title($e->ID)); ?></title>
      <link><?php echo esc_url(get_permalink($e->ID)); ?></link>
      <guid isPermaLink="true"><?php echo esc_url(get_permalink($e->ID)); ?></guid>
      <pubDate><?php echo get_the_date('r',$e->ID); ?></pubDate>
      <description><![CDATA[<?php echo wp_kses_post(get_the_excerpt($e->ID)); ?>]]></description>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
<?php
}

/* ── Create or update edition post from draft ── */
function rdrnl_publish_edition( $draft ) {
    $settings = get_option( RDRNL_OPTION, [] );
    if ( empty($settings['publish_editions']) ) return false;

    $week = date('Y-W');
    $slug = 'semaine-' . date('W') . '-' . date('Y');

    // Build HTML content from draft (same as email but stripped of email-specific styles)
    $content = rdrnl_edition_html_content( $draft );

    // Check if edition for this week already exists
    $existing = get_posts([
        'post_type'      => 'rdrnl_edition',
        'post_status'    => ['publish','draft'],
        'posts_per_page' => 1,
        'meta_key'       => '_rdrnl_week',
        'meta_value'     => $week,
    ]);

    $post_data = [
        'post_type'    => 'rdrnl_edition',
        'post_title'   => $draft['subject'] ?? ('Récap — Semaine ' . date('W') . ' ' . date('Y')),
        'post_name'    => $slug,
        'post_content' => $content,
        'post_excerpt' => rdrnl_edition_excerpt( $draft ),
        'post_status'  => 'publish',
        'post_date'    => current_time('mysql'),
    ];

    if ( $existing ) {
        $post_data['ID'] = $existing[0]->ID;
        $pid = wp_update_post($post_data);
    } else {
        $pid = wp_insert_post($post_data);
    }

    if ( $pid && ! is_wp_error($pid) ) {
        update_post_meta($pid, '_rdrnl_week', $week);
        update_post_meta($pid, '_rdrnl_item_count', rdrnl_draft_count($draft));
        // Set featured image from first available item image
        if ( ! empty($hero_image) ) {
            rdrnl_set_featured_image_from_url($pid, $hero_image);
        }
        return $pid;
    }
    return false;
}

function rdrnl_edition_excerpt( $draft ) {
    $n     = rdrnl_draft_count($draft);
    $cats  = [];
    foreach ( $draft['own_posts'] ?? [] as $p ) {
        if ($p['selected'] && !empty($p['categories'])) {
            $cats = array_merge($cats,(array)$p['categories']);
        }
    }
    $cats = array_unique($cats);
    return $n . ' ressource' . ($n>1?'s':'') . ' sélectionnée' . ($n>1?'s':'') . ( $cats ? ' · ' . implode(', ', array_slice($cats,0,4)) : '' );
}

function rdrnl_edition_html_content( $draft ) {
    $categorized = rdrnl_draft_get_categorized($draft);
    ob_start();
    // Featured image from first own post with image
    $hero_image = '';
    foreach ($draft['own_posts'] ?? [] as $p) {
        if ($p['selected'] && !empty($p['image'])) { $hero_image = $p['image']; break; }
    }
    if (!$hero_image) {
        foreach ($draft['manual_links'] ?? [] as $l) {
            if (!empty($l['image'])) { $hero_image = $l['image']; break; }
        }
    }
    if (!$hero_image) {
        foreach ($draft['feed_sections'] ?? [] as $s) {
            foreach ($s['items'] as $i) {
                if ($i['selected'] && !empty($i['image'])) { $hero_image = $i['image']; break 2; }
            }
        }
    }

    if ( ! empty(trim($draft['personal_message'] ?? '')) ) {
        echo '<blockquote class="rdrnl-edition-intro">';
        echo wp_kses_post( nl2br($draft['personal_message']) );
        echo '</blockquote>';
    }

    foreach ( $categorized as $cat_key => $items ) {
        if ( empty($items) ) continue;
        $label = rdrnl_section_label($cat_key);
        echo '<h2>' . esc_html($label) . '</h2>';
        echo '<ul class="rdrnl-edition-list">';
        foreach ( $items as $item ) {
            $url  = esc_url($item['url'] ?? '');
            $ext  = !str_starts_with($url, home_url()) ? ' rel="nofollow" target="_blank"' : '';
            $note = trim($item['note'] ?? '');
            echo '<li>';
            echo '<strong><a href="' . $url . '"' . $ext . '>' . esc_html($item['title'] ?? '') . '</a></strong>';
            if ( !empty($item['description']) ) echo '<br><span class="rdrnl-edition-desc">' . esc_html($item['description']) . '</span>';
            if ( $note ) {
                echo '<br><em class="rdrnl-edition-note">' . rdrnl_kses_note($note) . '</em>';
            }
            if ( !empty($item['date']) ) echo '<br><small class="rdrnl-edition-date">' . esc_html($item['date']) . '</small>';
            // Alt links
            $alt_links = $item['alt_links'] ?? [];
            if ( ! empty($alt_links) ) {
                echo '<br><span class="rdrnl-edition-alt-label">Voir aussi&nbsp;: </span>';
                foreach ($alt_links as $idx => $al) {
                    $al_url = esc_url($al['url'] ?? '');
                    $al_ext = ! str_starts_with($al_url, home_url()) ? ' rel="nofollow" target="_blank"' : '';
                    $al_lbl = esc_html($al['label'] ?: parse_url($al_url, PHP_URL_HOST));
                    if ($idx > 0) echo ' <span aria-hidden="true">·</span> ';
                    echo '<a href="' . $al_url . '"' . $al_ext . ' class="rdrnl-edition-alt-link">' . $al_lbl . '</a>';
                }
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    return ob_get_clean();
}

/* ── Template for single edition ── */
add_filter( 'single_template', 'rdrnl_edition_template' );
function rdrnl_edition_template( $template ) {
    if ( get_post_type() === 'rdrnl_edition' ) {
        $custom = RDRNL_DIR . 'templates/edition-single.php';
        if ( file_exists($custom) ) return $custom;
    }
    return $template;
}

/* Archive disabled — use WP page with [rdrnl_newsletter_page] shortcode */

/**
 * Download an external image and set it as featured image for a post.
 * Uses WP's media sideload functions.
 */
function rdrnl_set_featured_image_from_url( $post_id, $url ) {
    // Only for external images (internal WP images are handled differently)
    $existing = get_post_thumbnail_id($post_id);
    if ($existing) return; // already has one

    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // Only sideload http(s) URLs
    if (!preg_match('/^https?:\/\//i', $url)) return;

    $attachment_id = media_sideload_image($url, $post_id, null, 'id');
    if (!is_wp_error($attachment_id)) {
        set_post_thumbnail($post_id, $attachment_id);
    }
}
