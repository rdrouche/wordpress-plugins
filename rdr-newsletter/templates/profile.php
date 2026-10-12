<?php
/**
 * Template: rdrnl_profile — subscriber personalisation page
 * Layout GP avec sidebar droite
 * Auth: token in URL (?rdrnl_tok=xxx), validated against DB on every request.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$rdrnl_tok = sanitize_text_field( $_GET['rdrnl_tok'] ?? '' );
$sub_id    = rdrnl_profile_validate_token( $rdrnl_tok );
$authed    = ( $sub_id > 0 );
$feeds     = $authed ? rdrnl_profile_get_feeds( $sub_id ) : [];
while ( count($feeds) < 3 ) $feeds[] = ['url'=>'','label'=>'','limit'=>5];

$css_ver = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.css' );
$js_ver  = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/front.js' );
wp_enqueue_style(  'rdrnl-front', RDRNL_URL . 'assets/front.css', [], $css_ver );
wp_enqueue_script( 'rdrnl-front', RDRNL_URL . 'assets/front.js', [], $js_ver, true );
wp_add_inline_script( 'rdrnl-front',
    'window.rdrnlFront=window.rdrnlFront||{};window.rdrnlFront.ajaxurl=' . wp_json_encode(admin_url('admin-ajax.php')) . ';window.rdrnlFront.nonce=' . wp_json_encode(wp_create_nonce('rdrnl_profile_nonce')) . ';window.rdrnlFront.rdrnl_tok=' . wp_json_encode($rdrnl_tok) . ';',
    'before'
);
?>

<div id="content" class="site-content">

  <?php do_action( 'generate_before_main_content' ); ?>

  <article id="post-<?php the_ID(); ?>" <?php post_class('page'); ?>>
  <div class="inside-article">

    <header class="entry-header">
      <?php do_action( 'generate_before_entry_title' ); ?>
      <h1 class="entry-title">
        <?php echo $authed ? 'Mes flux personnalisés' : 'Personnaliser ma newsletter'; ?>
      </h1>
    </header>

    <div class="entry-content">
    <div class="rdrnl-profile-wrap">

    <?php if ( ! $authed ) : ?>

      <div class="rdrnl-profile-card rdrnl-profile-noauth">
        <div class="rdrnl-profile-icon">🔐</div>
        <p>Pour accéder à vos préférences, entrez votre adresse email.<br>Vous recevrez un lien d'accès valable 24 heures.</p>
        <?php if ($rdrnl_tok) : ?>
        <div class="rdrnl-notice rdrnl-notice-error" style="margin:12px 0">
          Ce lien a expiré ou est invalide. Demandez un nouveau lien ci-dessous.
        </div>
        <?php endif; ?>
        <div id="rdrnl-req-msg" class="rdrnl-ajax-msg" style="display:none"></div>
        <div class="rdrnl-profile-form-row">
          <input type="email" id="rdrnl-req-email" placeholder="votre@email.com" autocomplete="email">
          <button type="button" id="rdrnl-req-btn" class="rdrnl-btn-primary">Recevoir mon lien</button>
        </div>
      </div>

    <?php else : ?>

      <div class="rdrnl-profile-card">
        <div class="rdrnl-profile-header">
          <p>Ajoutez jusqu'à <strong>3 flux RSS</strong> personnels intégrés à votre récap hebdomadaire.
          Maximum <strong>10 articles</strong> par flux.</p>
        </div>
        <div id="rdrnl-prof-msg" class="rdrnl-ajax-msg" style="display:none"></div>
        <div class="rdrnl-feeds-form" id="rdrnl-feeds-form">
          <?php foreach ($feeds as $i => $feed) :
            $has = !empty($feed['url']); ?>
          <div class="rdrnl-feed-slot <?php echo $has ? 'has-feed' : 'empty-slot'; ?>" data-slot="<?php echo $i; ?>">
            <div class="rdrnl-slot-header">
              <span class="rdrnl-slot-num"><?php echo $i+1; ?></span>
              <h3>Flux <?php echo $i+1; ?></h3>
              <?php if ($has) : ?><span class="rdrnl-slot-active">Actif</span><?php endif; ?>
            </div>
            <div class="rdrnl-slot-fields">
              <div class="rdrnl-slot-url-row">
                <input type="url" class="rdrnl-feed-url" data-slot="<?php echo $i; ?>"
                       placeholder="https://example.com/feed" value="<?php echo esc_attr($feed['url']); ?>">
                <button type="button" class="rdrnl-btn-check-feed" data-slot="<?php echo $i; ?>" title="Vérifier">
                  <span class="rdrnl-check-icon">🔍</span>
                </button>
              </div>
              <div class="rdrnl-feed-check-result" id="rdrnl-check-<?php echo $i; ?>"></div>
              <div class="rdrnl-slot-meta">
                <div class="rdrnl-slot-label-wrap">
                  <label>Nom affiché dans l'email</label>
                  <input type="text" class="rdrnl-feed-label" placeholder="Nom du flux (optionnel)"
                         value="<?php echo esc_attr($feed['label']); ?>">
                </div>
                <div class="rdrnl-slot-limit-wrap">
                  <label>Nombre d'articles (max 10)</label>
                  <input type="number" class="rdrnl-feed-limit" min="1" max="10"
                         value="<?php echo intval($feed['limit'] ?? 5); ?>">
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="rdrnl-profile-actions">
          <button type="button" id="rdrnl-save-profile" class="rdrnl-btn-primary">
            Enregistrer mes préférences
          </button>
          <span class="rdrnl-save-hint">Vos flux apparaîtront dans votre prochain récap.</span>
        </div>
      </div>

    <?php endif; ?>

    </div><!-- .rdrnl-profile-wrap -->
    </div><!-- .entry-content -->

  </div><!-- .inside-article -->
  </article>

  <?php do_action( 'generate_after_main_content' ); ?>

</div><!-- #content -->

<?php get_footer(); ?>
