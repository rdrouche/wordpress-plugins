<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'rdrnl_subscribe', 'rdrnl_subscribe_shortcode' );

function rdrnl_subscribe_shortcode( $atts ) {
    $atts = shortcode_atts([
        'title'       => 'Recevoir le récap hebdomadaire',
        'description' => 'Chaque vendredi — actus IT et nouveaux tutoriels RDR-IT.',
        'show_name'   => 'yes',
        'button'      => "S'inscrire",
        'style'       => 'card', // card | inline | minimal
    ], $atts, 'rdrnl_subscribe' );

    // Show status message from redirect
    $status  = sanitize_text_field( $_GET['rdrnl_status']  ?? '' );
    $msg_raw = sanitize_text_field( urldecode( $_GET['rdrnl_message'] ?? '' ) );

    $settings  = get_option( RDRNL_OPTION, [] );
    $max       = intval( $settings['max_subscribers'] ?? 0 );
    $active    = rdrnl_count_active();
    $waitlist  = rdrnl_count_waitlist();
    $is_full   = ( $max > 0 && $active >= $max );

    ob_start();

    wp_enqueue_style(  'rdrnl-front', RDRNL_URL . 'assets/front.css', [], RDRNL_VERSION );
    wp_enqueue_script( 'rdrnl-front', RDRNL_URL . 'assets/front.js', [], RDRNL_VERSION, true );
    // wp_add_inline_script injecte le nonce directement dans le HTML — non mis en cache par LiteSpeed
    wp_add_inline_script( 'rdrnl-front',
        'window.rdrnlFront=window.rdrnlFront||{};window.rdrnlFront.ajaxurl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';window.rdrnlFront.nonce=' . wp_json_encode(wp_create_nonce('rdrnl_front')) . ';window.rdrnlFront.rdrnl_tok=window.rdrnlFront.rdrnl_tok||"";',
        'before'
    );

    $style_class = 'rdrnl-form-' . esc_attr( $atts['style'] );
    ?>
    <div class="rdrnl-subscribe-wrap <?php echo $style_class; ?>">

      <?php if ( $status && $msg_raw ) : ?>
      <div class="rdrnl-notice rdrnl-notice-<?php echo esc_attr($status); ?>">
        <?php echo esc_html( $msg_raw ); ?>
      </div>
      <?php endif; ?>

      <?php if ( ! in_array( $status, ['active','waitlist','unsubscribed'] ) ) : ?>

        <?php if ( $atts['title'] ) : ?>
        <h3 class="rdrnl-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
        <?php endif; ?>

        <?php if ( $atts['description'] ) : ?>
        <p class="rdrnl-form-desc"><?php echo esc_html( $atts['description'] ); ?></p>
        <?php endif; ?>

        <?php if ( $is_full ) : ?>
        <p class="rdrnl-waitlist-notice">
          La liste est actuellement complète (<?php echo $active; ?>/<?php echo $max; ?>).
          <?php if ( $waitlist > 0 ) : ?>Il y a déjà <?php echo $waitlist; ?> personne(s) en liste d'attente.<?php endif; ?>
          Vous pouvez rejoindre la liste d'attente et serez notifié(e) dès qu'une place se libère.
        </p>
        <?php endif; ?>

        <div id="rdrnl-sub-msg" class="rdrnl-ajax-msg" style="display:none"></div>

        <div class="rdrnl-form-fields">
          <?php if ( $atts['show_name'] === 'yes' ) : ?>
          <input type="text" id="rdrnl-sub-name" placeholder="Votre prénom (optionnel)" autocomplete="given-name">
          <?php endif; ?>
          <div class="rdrnl-form-row">
            <input type="email" id="rdrnl-sub-email" placeholder="votre@email.com" required autocomplete="email">
            <button type="button" id="rdrnl-sub-btn" class="rdrnl-btn-primary">
              <?php echo esc_html( $is_full ? "Rejoindre la liste d'attente" : $atts['button'] ); ?>
            </button>
          </div>
          <p class="rdrnl-consent">En vous inscrivant, vous acceptez de recevoir un email hebdomadaire. Désinscription à tout moment.</p>
        </div>

      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* AJAX handler for subscribe form */
add_action( 'wp_ajax_nopriv_rdrnl_subscribe_ajax', 'rdrnl_ajax_subscribe_front' );
add_action( 'wp_ajax_rdrnl_subscribe_ajax',         'rdrnl_ajax_subscribe_front' );
function rdrnl_ajax_subscribe_front() {
    check_ajax_referer( 'rdrnl_front', 'nonce' );
    $email  = sanitize_email( $_POST['email'] ?? '' );
    $name   = sanitize_text_field( $_POST['name']  ?? '' );
    if ( ! is_email( $email ) ) {
        wp_send_json( ['status' => 'error', 'message' => 'Adresse email invalide.'] );
    }
    $result = rdrnl_subscribe( $email, $name );
    wp_send_json( $result );
}

/* ============================================================
   SHORTCODE [rdrnl_profile_request]
   Affiche un formulaire standalone pour recevoir le lien
   de personnalisation par email.
   Usage : [rdrnl_profile_request title="..." button="..."]
   ============================================================ */
add_shortcode( 'rdrnl_profile_request', 'rdrnl_profile_request_shortcode' );

function rdrnl_profile_request_shortcode( $atts ) {
    $atts = shortcode_atts([
        'title'       => 'Accéder à mes préférences',
        'description' => 'Entrez votre adresse email pour recevoir votre lien de personnalisation (valable 24h).',
        'button'      => 'Recevoir mon lien',
        'style'       => 'card',
    ], $atts, 'rdrnl_profile_request' );

    // Handle status messages from redirect
    $status  = sanitize_text_field( $_GET['rdrnl_status']  ?? '' );
    $msg_raw = sanitize_text_field( urldecode( $_GET['rdrnl_message'] ?? '' ) );

    $css_ver = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.css' );
    $js_ver  = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.js'  );
    wp_enqueue_style(  'rdrnl-front', RDRNL_URL . 'assets/front.css', [], $css_ver );
    wp_enqueue_script( 'rdrnl-front', RDRNL_URL . 'assets/front.js', [], $js_ver, true );
    // Inline nonce (cache-safe). Uses rdrnl_profile_nonce; the request-link
    // handler also accepts rdrnl_front so a subscribe form on the same page
    // never causes a nonce collision.
    wp_add_inline_script( 'rdrnl-front',
        'window.rdrnlFront=window.rdrnlFront||{};window.rdrnlFront.ajaxurl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';window.rdrnlFront.nonceProfile=' . wp_json_encode(wp_create_nonce('rdrnl_profile_nonce')) . ';window.rdrnlFront.nonce=window.rdrnlFront.nonce||' . wp_json_encode(wp_create_nonce('rdrnl_front')) . ';window.rdrnlFront.rdrnl_tok=window.rdrnlFront.rdrnl_tok||"";',
        'before'
    );

    $style_class = 'rdrnl-form-' . esc_attr( $atts['style'] );

    ob_start();
    ?>
    <div class="rdrnl-subscribe-wrap rdrnl-profile-request-wrap <?php echo $style_class; ?>">

      <?php if ( $status && $msg_raw ) : ?>
      <div class="rdrnl-notice rdrnl-notice-<?php echo esc_attr($status); ?>">
        <?php echo esc_html($msg_raw); ?>
      </div>
      <?php endif; ?>

      <?php if ( ! in_array($status, ['reengagement_ok']) ) : ?>

        <?php if ( $atts['title'] ) : ?>
        <h3 class="rdrnl-form-title"><?php echo esc_html($atts['title']); ?></h3>
        <?php endif; ?>

        <?php if ( $atts['description'] ) : ?>
        <p class="rdrnl-form-desc"><?php echo esc_html($atts['description']); ?></p>
        <?php endif; ?>

        <div id="rdrnl-req-msg-sc" class="rdrnl-ajax-msg" style="display:none"></div>

        <div class="rdrnl-form-fields">
          <div class="rdrnl-form-row">
            <input type="email"
                   id="rdrnl-req-email-sc"
                   placeholder="votre@email.com"
                   autocomplete="email"
                   required>
            <button type="button" id="rdrnl-req-btn-sc" class="rdrnl-btn-primary">
              <?php echo esc_html($atts['button']); ?>
            </button>
          </div>
          <p class="rdrnl-consent">
            Réservé aux abonnés confirmés. Si vous n'êtes pas encore abonné(e),
            utilisez le formulaire d'inscription.
          </p>
        </div>

      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   SHORTCODE [rdrnl_newsletter]
   Affiche la liste des éditions publiées (comme l'archive /newsletter/)
   sans avoir besoin de naviguer vers la page d'archive.
   Usage : [rdrnl_newsletter count="5" columns="2"]
   ============================================================ */
add_shortcode( 'rdrnl_newsletter', 'rdrnl_newsletter_shortcode' );

function rdrnl_newsletter_shortcode( $atts ) {
    $atts = shortcode_atts([
        'count'   => 6,
        'columns' => 2,
        'show_excerpt' => 'yes',
        'show_count'   => 'yes',
    ], $atts, 'rdrnl_newsletter' );

    $editions = get_posts([
        'post_type'      => 'rdrnl_edition',
        'post_status'    => 'publish',
        'posts_per_page' => intval($atts['count']),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if ( empty($editions) ) return '<p>Aucune édition publiée.</p>';

    $cols = max(1, min(4, intval($atts['columns'])));

    ob_start();
    wp_enqueue_style( 'rdrnl-front', RDRNL_URL.'assets/front.css', [],
        RDRNL_VERSION.'.'.@filemtime(RDRNL_DIR.'assets/front.css') );
    ?>
    <div class="rdrnl-edition-grid rdrnl-sc-grid rdrnl-sc-cols-<?php echo $cols; ?>">
    <?php foreach ($editions as $e) :
        $count = get_post_meta($e->ID, '_rdrnl_item_count', true);
        $thumb = get_the_post_thumbnail_url($e->ID, 'medium');
    ?>
    <article class="rdrnl-edition-card">
      <a href="<?php echo esc_url(get_permalink($e->ID)); ?>" class="rdrnl-edition-card-link">
        <?php if ($thumb) : ?>
        <div class="rdrnl-edition-card-thumb" style="background-image:url('<?php echo esc_url($thumb); ?>')"></div>
        <?php endif; ?>
        <div class="rdrnl-edition-card-inner">
          <div class="rdrnl-edition-card-header">
            <span class="rdrnl-edition-badge">📧 Newsletter</span>
            <time datetime="<?php echo get_the_date('c',$e); ?>"><?php echo get_the_date('j F Y',$e); ?></time>
          </div>
          <h3 class="rdrnl-edition-card-title"><?php echo esc_html($e->post_title); ?></h3>
          <?php if ($atts['show_excerpt']==='yes' && $e->post_excerpt) : ?>
          <p class="rdrnl-edition-card-excerpt"><?php echo esc_html($e->post_excerpt); ?></p>
          <?php endif; ?>
          <?php if ($atts['show_count']==='yes' && $count) : ?>
          <span class="rdrnl-edition-card-count"><?php echo intval($count); ?> ressource<?php echo $count>1?'s':''; ?></span>
          <?php endif; ?>
        </div>
      </a>
    </article>
    <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   SHORTCODE [rdrnl_newsletter_page]
   Remplace la page d'archive /newsletter/ quand placé dans
   une vraie page WP (GP gère le layout + sidebar automatiquement).
   Usage : [rdrnl_newsletter_page per_page="12" columns="2"]
   ============================================================ */
add_shortcode( 'rdrnl_newsletter_page', 'rdrnl_newsletter_page_shortcode' );

function rdrnl_newsletter_page_shortcode( $atts ) {
    $atts = shortcode_atts([
        'per_page' => 12,
        'columns'  => 2,
    ], $atts, 'rdrnl_newsletter_page' );

    $per_page = max(1, intval($atts['per_page']));
    $cols     = max(1, min(4, intval($atts['columns'])));
    $paged    = max(1, get_query_var('paged') ?: intval($_GET['nl_page'] ?? 1));

    $query = new WP_Query([
        'post_type'      => 'rdrnl_edition',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $css_ver = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.css' );
    wp_enqueue_style( 'rdrnl-front', RDRNL_URL . 'assets/front.css', [], $css_ver );

    ob_start();
    ?>
    <div class="rdrnl-newsletter-page">

      <!-- En-tête + inscription + RSS -->
      <div class="rdrnl-nl-page-header">
        <p class="rdrnl-archive-desc">Récap hebdomadaire IT — chaque vendredi.</p>
        <div class="rdrnl-archive-actions">
          <?php echo do_shortcode('[rdrnl_subscribe title="" description="" style="inline"]'); ?>
          <a href="<?php echo esc_url(home_url('/feed/rdrnl-editions/')); ?>" class="rdrnl-rss-link" rel="nofollow">
            📡 Flux RSS
          </a>
        </div>
      </div>

      <!-- Grille des éditions -->
      <?php if ( $query->have_posts() ) : ?>
      <div class="rdrnl-edition-grid rdrnl-sc-grid rdrnl-sc-cols-<?php echo $cols; ?>">
        <?php while ( $query->have_posts() ) : $query->the_post();
          $count = get_post_meta(get_the_ID(), '_rdrnl_item_count', true);
          $thumb = get_the_post_thumbnail_url(get_the_ID(), 'medium');
        ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('rdrnl-edition-card'); ?>>
          <a href="<?php the_permalink(); ?>" class="rdrnl-edition-card-link">
            <?php if ($thumb) : ?>
            <div class="rdrnl-edition-card-thumb" style="background-image:url('<?php echo esc_url($thumb); ?>')"></div>
            <?php endif; ?>
            <div class="rdrnl-edition-card-inner">
              <div class="rdrnl-edition-card-header">
                <span class="rdrnl-edition-badge">📧 Newsletter</span>
                <time datetime="<?php echo get_the_date('c'); ?>"><?php echo get_the_date('j F Y'); ?></time>
              </div>
              <h2 class="rdrnl-edition-card-title"><?php the_title(); ?></h2>
              <?php if (get_the_excerpt()) : ?>
              <p class="rdrnl-edition-card-excerpt"><?php echo get_the_excerpt(); ?></p>
              <?php endif; ?>
              <?php if ($count) : ?>
              <span class="rdrnl-edition-card-count">
                <?php echo intval($count); ?> ressource<?php echo $count > 1 ? 's' : ''; ?>
              </span>
              <?php endif; ?>
            </div>
          </a>
        </article>
        <?php endwhile; wp_reset_postdata(); ?>
      </div>

      <!-- Pagination -->
      <?php if ($query->max_num_pages > 1) : ?>
      <div class="rdrnl-nl-pagination">
        <?php
        $current_url = get_permalink();
        for ($p = 1; $p <= $query->max_num_pages; $p++) :
            $url     = add_query_arg('nl_page', $p, $current_url);
            $is_cur  = ($p === $paged);
        ?>
        <a href="<?php echo esc_url($url); ?>"
           class="rdrnl-page-btn<?php echo $is_cur ? ' current' : ''; ?>">
          <?php echo $p; ?>
        </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

      <?php else : ?>
      <p>Aucune édition publiée pour le moment. Revenez vendredi !</p>
      <?php endif; ?>

    </div><!-- .rdrnl-newsletter-page -->
    <?php
    return ob_get_clean();
}
