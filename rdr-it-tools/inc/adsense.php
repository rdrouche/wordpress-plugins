<?php
/**
 * RDR-IT Tools — AdSense & AdBlock v4
 *
 * Gestion par rôle séparée :
 *   rdrit_ads_disabled_roles    JSON — rôles pour lesquels AdSense est désactivé
 *   rdrit_adblock_disabled_roles JSON — rôles pour lesquels AdBlock est désactivé
 *
 * Toggle ON dans l'admin = fonctionnalité désactivée pour ce rôle.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   POLYLANG — Enregistrement des chaînes traduisibles
   Traduit dans Polylang → Traductions de chaînes → groupe "RDR-IT AdBlock"
   ============================================================ */
add_action( 'init', 'rdrit_adblock_register_strings' );
function rdrit_adblock_register_strings(): void {
    if ( ! function_exists( 'pll_register_string' ) ) return;
    $group = 'RDR-IT AdBlock';
    pll_register_string( 'adblock_banner_msg',       get_option( 'rdrit_adblock_banner_msg', '' ),       $group, true );
    pll_register_string( 'adblock_notice_banner_msg', get_option( 'rdrit_adblock_notice_banner_msg', '' ), $group, true );
    pll_register_string( 'adblock_notice_modal_msg',  get_option( 'rdrit_adblock_notice_modal_msg', '' ),  $group, true );
}

/* ============================================================
   HELPERS — rôle courant désactivé ?
   ============================================================ */
function rdrit_get_option_array_safe( string $key ): array {
    $val = get_option( $key, array() );
    if ( is_array( $val ) ) return $val;
    if ( is_string( $val ) && $val !== '' ) {
        $decoded = json_decode( $val, true );
        return is_array( $decoded ) ? $decoded : array();
    }
    return array();
}

function rdrit_ads_role_disabled(): bool {
    if ( ! is_user_logged_in() ) return false;
    $caps = rdrit_get_option_array_safe( 'rdrit_ads_disabled_caps' );
    if ( ! is_array( $caps ) || empty( $caps ) ) return false;
    foreach ( $caps as $cap ) {
        if ( current_user_can( $cap ) ) return true;
    }
    return false;
}

function rdrit_adblock_role_disabled(): bool {
    if ( ! is_user_logged_in() ) return false;
    $caps = rdrit_get_option_array_safe( 'rdrit_adblock_disabled_caps' );
    if ( ! is_array( $caps ) || empty( $caps ) ) return false;
    foreach ( $caps as $cap ) {
        if ( current_user_can( $cap ) ) return true;
    }
    return false;
}

/* ============================================================
   ENQUEUE SCRIPTS
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrit_adsense_enqueue' );
function rdrit_adsense_enqueue() {
    $ads_enabled  = (bool) get_option( 'rdrit_ads_enabled', 0 );
    $client       = sanitize_text_field( get_option( 'rdrit_ads_client', '' ) );
    $ads_src      = $client
        ? 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client
        : 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

    /* ── Lazy load AdSense ── */
    if ( $ads_enabled ) {
        $ads_disabled = rdrit_ads_role_disabled();
        if ( ! $ads_disabled && (bool) get_option( 'rdrit_ads_lazy_enabled', 1 ) && $client ) {
            wp_enqueue_script(
                'rdrit-adsense-lazy',
                RDRIT_TOOLS_URL . 'assets/js/rdrit-adsense-lazy.js',
                array(),
                RDRIT_TOOLS_VERSION,
                true
            );
            wp_localize_script( 'rdrit-adsense-lazy', 'rdritAdsConfig', array(
                'adsSrc'       => $ads_src,
                'lazySelector' => sanitize_text_field( get_option( 'rdrit_ads_lazy_selector', '#main' ) ),
                'lazyMargin'   => (int) get_option( 'rdrit_ads_lazy_margin', 1000 ),
            ) );
        }
    }

    /* ── Détection AdBlock ──
       Indépendant de rdrit_ads_enabled — peut fonctionner sans AdSense actif.
       Requis : rdrit_adblock_enabled=1 ET is_single() (articles uniquement).
       En mode debug, log dans la console même sans AdBlock détecté.
    ── */
    $adblock_disabled = rdrit_adblock_role_disabled();
    $adblock_enabled  = (bool) get_option( 'rdrit_adblock_enabled', 1 );
    $adblock_debug    = (bool) get_option( 'rdrit_adblock_debug', 0 );

    if ( ! $adblock_disabled && $adblock_enabled && is_single() ) {
        // URL de test : utiliser l'URL AdSense si dispo, sinon une URL publique connue des bloqueurs
        $test_url = $ads_src ?: 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

        wp_enqueue_script(
            'rdrit-adblock',
            RDRIT_TOOLS_URL . 'assets/js/rdrit-adblock.js',
            array(),
            RDRIT_TOOLS_VERSION,
            true
        );
        wp_localize_script( 'rdrit-adblock', 'rdritAdblockConfig', array(
            'adsUrl'      => $test_url,
            'action'      => get_option( 'rdrit_adblock_action', 'both' ),
            'bannerMsg'   => get_option( 'rdrit_adblock_banner_msg',
                                'Ce site vit grace a la publicite. Merci de desactiver votre bloqueur !' ),
            'bannerColor' => sanitize_hex_color( get_option( 'rdrit_adblock_banner_color', '#1e40af' ) ) ?: '#1e40af',
            'imgUrl'      => esc_url( get_option( 'rdrit_adblock_img_url',
                                'https://static.rdr-it.com/images/rdr-img-message.png' ) ),
            'imgSelector' => get_option( 'rdrit_adblock_img_selector', '.wp-block-image > .fancybox' ),
            'debug'       => $adblock_debug,
        ) );
    }
}

/* ============================================================
   CACHE LITESPEED — exclure les pages pour les rôles désactivés
   ============================================================ */
add_action( 'wp_head', 'rdrit_ads_litespeed_no_cache', 1 );
function rdrit_ads_litespeed_no_cache() {
    // No-cache si l'utilisateur est concerné par une désactivation AdSense OU AdBlock
    $skip_ads     = rdrit_ads_role_disabled();
    $skip_adblock = rdrit_adblock_role_disabled();

    if ( ! $skip_ads && ! $skip_adblock ) return;

    do_action( 'litespeed_control_set_nocache', 'rdrit_ads_role' );
    if ( ! headers_sent() ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
    }
}

/* ============================================================
   AMP ADSENSE
   ============================================================ */
add_action( 'amp_post_template_head', 'rdrit_amp_adsense_head' );
function rdrit_amp_adsense_head() {
    if ( ! (bool) get_option( 'rdrit_ads_enabled',     0 ) ) return;
    if ( ! (bool) get_option( 'rdrit_ads_amp_enabled', 1 ) ) return;
    if ( rdrit_ads_role_disabled() ) return;
    ?>
<script async custom-element="amp-auto-ads"
    src="https://cdn.ampproject.org/v0/amp-auto-ads-0.1.js"></script>
    <?php
}

add_action( 'amp_post_template_footer', 'rdrit_amp_adsense_footer' );
function rdrit_amp_adsense_footer() {
    if ( ! (bool) get_option( 'rdrit_ads_enabled',     0 ) ) return;
    if ( ! (bool) get_option( 'rdrit_ads_amp_enabled', 1 ) ) return;
    if ( rdrit_ads_role_disabled() ) return;
    $client = sanitize_text_field( get_option( 'rdrit_ads_client', '' ) );
    if ( ! $client ) return;
    ?>
<amp-auto-ads type="adsense" data-ad-client="<?php echo esc_attr( $client ); ?>"></amp-auto-ads>
    <?php
}

/* ============================================================
   NOTICE ADMIN — LiteSpeed
   ============================================================ */
add_action( 'admin_notices', 'rdrit_adsense_litespeed_notice' );
function rdrit_adsense_litespeed_notice() {
    if ( ! (bool) get_option( 'rdrit_ads_enabled', 0 ) ) return;
    if ( ! class_exists( 'LiteSpeed_Cache' ) && ! defined( 'LSCWP_V' ) ) return;
    if ( get_option( 'rdrit_litespeed_notice_dismissed', 0 ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $dismiss_url = wp_nonce_url( add_query_arg( 'rdrit_dismiss_ls_notice', '1' ), 'rdrit_dismiss_ls' );
    ?>
    <div class="notice notice-info is-dismissible" style="border-left-color:#2271b1">
        <p><strong>RDR-IT Tools</strong> — LiteSpeed détecté. Ajoutez ces exclusions :</p>
        <p><strong>JS Exclude</strong> : <code>rdrit-adsense-lazy</code> | <code>rdrit-adblock</code></p>
        <p><strong>URI Exclude</strong> : <code>pagead2.googlesyndication.com</code></p>
        <p><strong>Important :</strong> activez "Ne pas mettre en cache les utilisateurs connectés" dans LiteSpeed Cache → Gestion du cache.</p>
        <p><a href="<?php echo esc_url( $dismiss_url ); ?>">Ne plus afficher</a></p>
    </div>
    <?php
}

add_action( 'admin_init', function () {
    if ( isset( $_GET['rdrit_dismiss_ls_notice'] ) && check_admin_referer( 'rdrit_dismiss_ls' ) ) {
        update_option( 'rdrit_litespeed_notice_dismissed', 1 );
    }
} );

/* ============================================================
   ADBLOCK NOTICE — Bannière et/ou Modale
   Chargé async, invisible aux bots, désactivable par capacité.
   Mémorisé via localStorage pour éviter la répétition.
   Support Polylang : messages dans des CPT ou options par langue.
   ============================================================ */
add_action( 'wp_footer', 'rdrit_adblock_notice_enqueue', 20 );
function rdrit_adblock_notice_enqueue(): void {
    // Vérifications préalables
    if ( ! (bool) get_option( 'rdrit_adblock_notice_enabled', 0 ) ) return;
    if ( ! (bool) get_option( 'rdrit_adblock_enabled', 1 ) ) return;

    // Désactivation par capacité (même logique que AdBlock)
    if ( rdrit_adblock_role_disabled() ) return;

    // Ne pas charger pour les bots connus
    $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    $bots = array('googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot','sogou','exabot','facebot','ia_archiver','msnbot','teoma','ask jeeves','crawler','spider','robot','scraper');
    foreach ( $bots as $bot ) {
        if ( strpos( $ua, $bot ) !== false ) return;
    }

    // Langue courante Polylang
    $lang = function_exists('pll_current_language') ? pll_current_language() : 'fr';

    // Messages par langue — option JSON : {"fr":"...","en":"..."}
    $banner_enabled = (bool) get_option('rdrit_adblock_notice_banner', 1);
    $modal_enabled  = (bool) get_option('rdrit_adblock_notice_modal', 0);

    if ( ! $banner_enabled && ! $modal_enabled ) return;

    // Récupérer le message dans la bonne langue
    $banner_msg = rdrit_adblock_notice_get_msg('rdrit_adblock_notice_banner_msg', $lang,
        '<p>Ce site vit grâce à la publicité. Merci de désactiver votre bloqueur !</p><a href="https://ko-fi.com/" target="_blank">Soutenir sur Ko-fi</a>');
    $modal_msg  = rdrit_adblock_notice_get_msg('rdrit_adblock_notice_modal_msg', $lang,
        '<h2>Vous utilisez un bloqueur de publicités</h2><p>Pour soutenir ce site, pensez à vous abonner !</p><a href="https://ko-fi.com/" target="_blank" class="rdrit-abn-btn">Soutenir sur Ko-fi</a>');

    $config = array(
        'bannerEnabled'  => $banner_enabled,
        'modalEnabled'   => $modal_enabled,
        'bannerMsg'      => $banner_msg,
        'modalMsg'       => $modal_msg,
        'bannerDelay'    => (int) get_option('rdrit_adblock_notice_banner_delay', 2),
        'modalDelay'     => (int) get_option('rdrit_adblock_notice_modal_delay', 5),
        'modalRandom'    => (bool) get_option('rdrit_adblock_notice_modal_random', 0),
        'modalChance'    => (int) get_option('rdrit_adblock_notice_modal_chance', 5), // 1 sur N
        'storageKey'      => 'rdrit_abn_' . $lang,
        'storageTTL'      => (int) get_option('rdrit_adblock_notice_ttl', 24),
        'bannerBg'        => sanitize_hex_color(get_option('rdrit_adblock_notice_banner_bg', '#1e3a5f')) ?: '#1e3a5f',
        'bannerColor'     => sanitize_hex_color(get_option('rdrit_adblock_notice_banner_color', '#ffffff')) ?: '#ffffff',
        'modalNextPageOnly' => (bool) get_option('rdrit_adblock_notice_modal_next_page', 0),
    );

    // Injection inline async — pas de fichier externe, pas de defer
    echo "\n<script id=\"rdrit-abn-init\">window.rdritAbnConfig=" . wp_json_encode($config) . ";</script>\n";
    echo "<script id=\"rdrit-abn\" async src=\"" . esc_url(RDRIT_TOOLS_URL . 'assets/js/rdrit-adblock-notice.js?v=' . RDRIT_TOOLS_VERSION) . "\"></script>\n";
}

function rdrit_adblock_notice_get_msg(string $option, string $lang, string $default): string {
    $raw = get_option($option, '');
    if (empty($raw)) return $default;

    // Priorité 1 : Polylang pll__() — si Polylang actif et chaîne enregistrée
    // pll__() retourne la traduction de la chaîne dans la langue courante
    if ( function_exists('pll__') ) {
        $translated = pll__($raw);
        // pll__() retourne la chaîne d'origine si pas de traduction — on vérifie que c'est utile
        if ( $translated && $translated !== $raw ) return $translated;
    }

    // Priorité 2 : JSON multi-langue {"fr":"...","en":"..."}
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded[$lang] ?? $decoded['fr'] ?? $default;
    }

    // Priorité 3 : texte/HTML simple (toutes langues)
    return $raw;
}
