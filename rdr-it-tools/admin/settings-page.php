<?php
/**
 * RDR-IT Tools — Admin v5
 * Onglets : Badges | Taxonomies | Ordre & Separateurs | Ko-fi & Partage | Articles similaires
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'rdrit_tools_admin_menu' );
function rdrit_tools_admin_menu() {
    add_menu_page( 'RDR-IT Tools', 'RDR-IT Tools', 'manage_options', 'rdr-it-tools', 'rdrit_tools_page', 'dashicons-admin-tools', 80 );
}

/* ── AJAX save generique ── */
add_action( 'wp_ajax_rdrit_save_option', 'rdrit_ajax_save_option' );
function rdrit_ajax_save_option() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    $key   = sanitize_key( $_POST['option_key'] ?? '' );
    $value = $_POST['value'] ?? '';
    if ( strpos( $key, 'rdrit_' ) !== 0 ) { wp_send_json_error( 'Cle non autorisee' ); return; }

    // Options qui stockent des tableaux JSON
    $array_keys = array(
        'rdrit_ads_disabled_caps',
        'rdrit_adblock_disabled_caps',
        'rdrit_matomo_disabled_caps',
        'rdrit_quotes_colors',
        'rdrit_matomo_excluded_roles', // legacy
        'rdrit_ads_excluded_roles',    // legacy
    );
    if ( in_array( $key, $array_keys, true ) ) {
        // La valeur peut arriver comme string JSON ou déjà comme array
        if ( is_array( $value ) ) {
            $decoded = $value;
        } else {
            $decoded = json_decode( stripslashes( $value ), true );
        }
        $value = is_array( $decoded )
            ? array_map( 'sanitize_key', $decoded )
            : array();
        update_option( $key, $value );
        wp_send_json_success();
        return;
    }

    // Options qui stockent du HTML (bannière/modale AdBlock, messages riches)
    // wp_kses_post autorise les balises HTML standards : p, a, h1-h6, img, strong, em, ul, ol, li…
    $html_keys = array(
        'rdrit_adblock_notice_banner_msg',
        'rdrit_adblock_notice_modal_msg',
        'rdrit_adblock_banner_msg', // message bannière ancienne version
    );
    if ( in_array( $key, $html_keys, true ) ) {
        // La valeur contient potentiellement du JSON multi-langue {"fr":"...","en":"..."}
        $stripped = stripslashes( $value );
        $decoded  = json_decode( $stripped, true );
        if ( is_array( $decoded ) ) {
            // JSON multi-langue : kses_post sur chaque valeur
            $cleaned = array();
            foreach ( $decoded as $lang => $msg ) {
                $cleaned[ sanitize_text_field($lang) ] = wp_kses_post( $msg );
            }
            update_option( $key, wp_json_encode( $cleaned ) );
        } else {
            // HTML simple
            update_option( $key, wp_kses_post( $stripped ) );
        }
        wp_send_json_success();
        return;
    }

    $value = sanitize_text_field( $value );
    if ( strpos( $key, '_color' ) !== false && strpos( $value, '#' ) === 0 ) {
        $value = sanitize_hex_color( $value ) ?: $value;
    }
    update_option( $key, $value );
    wp_send_json_success();
}

/* ── AJAX flush cache ── */
add_action( 'wp_ajax_rdrit_flush_related_cache', 'rdrit_ajax_flush_cache' );
function rdrit_ajax_flush_cache() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rdrit_rel_%' OR option_name LIKE '_transient_timeout_rdrit_rel_%'" );
    wp_send_json_success( array( 'msg' => 'Cache vide avec succes.' ) );
}

/* ══════════════════════════════════════════════════════════════
   PAGE PRINCIPALE
   ══════════════════════════════════════════════════════════════ */
function rdrit_tools_page() {
    $nonce = wp_create_nonce( 'rdrit_nonce' );
    $g     = function( $k, $d = 1 ) { return get_option( $k, $d ); };

    $all_taxes          = rdrit_tax_get_all();
    $all_cpts           = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
    $excluded_pt        = array( 'attachment','wp_block','wp_template','wp_template_part','wp_navigation','wp_font_face','wp_font_family' );
    foreach ( $excluded_pt as $ep ) unset( $all_cpts[ $ep ] );
    $icon_choices       = rdrit_tax_icon_choices();
    $disabled_cpts_arr  = array_filter( explode( ',', $g( 'rdrit_rel_disabled_cpts', '' ) ) );

    // Construire la liste de tous les slugs disponibles pour le drag & drop
    $all_zone_items = array_keys( $all_taxes );
    $all_zone_items[] = '_share';
    $all_zone_items[] = '_related';

    // Labels d'affichage pour les items de zone
    $zone_labels = array( '_share' => '❤️ Ko-fi & Partage', '_related' => '📚 Articles similaires' );
    foreach ( $all_taxes as $slug => $tax ) {
        $zone_labels[ $slug ] = $tax->label . ' <code>' . $slug . '</code>';
    }

    // Ordre sauvegarde par zone
    $order_top    = rdrit_zone_order( 'top' );
    $order_bottom = rdrit_zone_order( 'bottom' );

    ?>
    <?php rdrit_admin_css(); ?>
    <div class="wrap rdrit-admin">
    <h1><span class="rdrit-logo">RDR<span>-IT</span></span> Tools <span class="rdrit-version">v<?php echo esc_html(RDRIT_TOOLS_VERSION); ?></span></h1>
    <p class="rdrit-subtitle">Configuration — rdr-it.com</p>

    <div class="rdrit-tabs">
        <button class="rdrit-tab active"  data-tab="badges">🏷️ Badges</button>
        <button class="rdrit-tab"         data-tab="taxonomies">🗂️ Taxonomies</button>
        <button class="rdrit-tab rdrit-tab--highlight" data-tab="order">↕️ Ordre &amp; Espaces</button>
        <button class="rdrit-tab"         data-tab="share">❤️ Ko-fi &amp; Partage</button>
        <button class="rdrit-tab"         data-tab="related">📚 Articles similaires</button>
        <button class="rdrit-tab"         data-tab="feeds">📡 Flux RSS</button>
        <button class="rdrit-tab"         data-tab="adsense">💰 AdSense &amp; AdBlock</button>
        <button class="rdrit-tab"         data-tab="shortcodes">🧩 Shortcodes</button>
        <button class="rdrit-tab"         data-tab="mailer">✉️ Mail</button>
        <button class="rdrit-tab"         data-tab="matomo">📊 Matomo</button>
        <button class="rdrit-tab"         data-tab="tweaks">🔧 Tweaks</button>
        <button class="rdrit-tab"         data-tab="lightbox">🖼️ Lightbox</button>
        <button class="rdrit-tab"         data-tab="quotes">💬 Citations</button>
        <button class="rdrit-tab"         data-tab="menusync">🌐 Menus</button>
        <button class="rdrit-tab"         data-tab="caps-doc">📖 Capacités</button>
        <button class="rdrit-tab"         data-tab="htmlblocks">🧱 Blocs HTML</button>
        <button class="rdrit-tab"         data-tab="loginsec">🔒 Sécurité login</button>
    </div>

    <!-- ══ BADGES ══ -->
    <div class="rdrit-tab-content active" id="tab-badges">
        <div class="rdrit-section">
            <h2>🏷️ Badges &amp; Remplacements</h2>
            <?php
            foreach ( array(
                'rdrit_badges_enabled'          => array( 'Badges <code>&lt;&lt;&lt;</code>/<code>&lt;&lt;</code>/<code>&gt;&gt;</code>', 'Vert · Rouge · Fermeture' ),
                'rdrit_badges_p_blocks_enabled' => array( 'Blocs <code>%start_p_*%</code>', 'primary · warning · danger · end_p' ),
                'rdrit_badges_git_redirect'     => array( 'Redirect <code>git.rdr-it.io</code> → <code>git.rdr-it.com</code>', 'Corrige URLs dans le contenu' ),
            ) as $key => $opt ) rdrit_tr( $key, $opt[0], $opt[1], $nonce );
            ?>
        </div>
        <div class="rdrit-section">
            <h2>Aperçu</h2>
            <div class="rdrit-preview">
                <span class="rdrit-badge rdrit-badge--success">Actif</span>&nbsp;
                <span class="rdrit-badge rdrit-badge--danger">Erreur</span>
                <p class="rdrit-alert rdrit-alert--primary">ℹ️ <code>%start_p_primary%</code></p>
                <p class="rdrit-alert rdrit-alert--warning">⚠️ <code>%start_p_warning%</code></p>
                <p class="rdrit-alert rdrit-alert--danger">🚨 <code>%start_p_danger%</code></p>
            </div>
        </div>
    </div>

    <!-- ══ TAXONOMIES ══ -->
    <div class="rdrit-tab-content" id="tab-taxonomies">
        <div class="rdrit-section rdrit-section--info">
            <p>💡 <strong>Détection automatique</strong> — toutes les taxonomies publiques apparaissent ici (CPT UI, PHP, core). L'ordre d'affichage se configure dans l'onglet <strong>↕️ Ordre &amp; Espaces</strong>.</p>
        </div>

        <?php
        $by_cpt = array();
        foreach ( $all_taxes as $slug => $tax ) {
            foreach ( (array) $tax->object_type as $pt ) {
                if ( in_array( $pt, $excluded_pt, true ) ) continue;
                $by_cpt[ $pt ][ $slug ] = $tax;
            }
        }
        ksort( $by_cpt );

        foreach ( $by_cpt as $pt_slug => $taxes ) :
            $pt_obj = get_post_type_object( $pt_slug );
            if ( ! $pt_obj ) continue;
        ?>
        <div class="rdrit-section">
            <h2><?php echo esc_html( $pt_obj->label ); ?> <code style="font-size:.7em;font-weight:400"><?php echo esc_html( $pt_slug ); ?></code></h2>
            <div class="rdrit-tax-cards">
            <?php foreach ( $taxes as $tax_slug => $tax ) :
                $cfg     = rdrit_tax_config( $tax_slug );
                $opt_key = function( $k ) use ( $tax_slug ) { return 'rdrit_tax_' . $tax_slug . '_' . $k; };
            ?>
            <div class="rdrit-tax-card <?php echo $cfg['enabled'] ? '' : 'rdrit-tax-card--disabled'; ?>"
                 id="rdrit-tax-card-<?php echo esc_attr( $tax_slug ); ?>">

                <div class="rdrit-tax-card-head">
                    <div class="rdrit-tax-card-title">
                        <span class="rdrit-tax-card-slug"><?php echo esc_html( $tax->label ); ?></span>
                        <code class="rdrit-tax-slug"><?php echo esc_html( $tax_slug ); ?></code>
                    </div>
                    <label class="rdrit-switch">
                        <input type="checkbox" class="rdrit-saveable-check"
                               data-key="<?php echo esc_attr( $opt_key('enabled') ); ?>"
                               data-nonce="<?php echo esc_attr($nonce); ?>"
                               data-card="rdrit-tax-card-<?php echo esc_attr($tax_slug); ?>"
                               <?php checked( $cfg['enabled'], true ); ?>>
                        <span class="rdrit-slider"></span>
                    </label>
                </div>

                <div class="rdrit-tax-card-body">
                    <!-- Position -->
                    <div class="rdrit-tax-field">
                        <label>📍 Position</label>
                        <div class="rdrit-pos-btns">
                            <?php foreach ( array('top'=>'↑ Sous le titre','bottom'=>'↓ Après contenu') as $pv=>$pl ) : ?>
                            <label class="rdrit-pos-btn <?php echo $cfg['position']===$pv?'active':''; ?>">
                                <input type="radio" name="pos_<?php echo esc_attr($tax_slug); ?>" value="<?php echo esc_attr($pv); ?>"
                                       class="rdrit-pos-radio"
                                       data-key="<?php echo esc_attr( $opt_key('position') ); ?>"
                                       data-nonce="<?php echo esc_attr($nonce); ?>"
                                       <?php checked($cfg['position'],$pv); ?>>
                                <?php echo esc_html($pl); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Icone -->
                    <div class="rdrit-tax-field">
                        <label>🎨 Icône</label>
                        <select class="rdrit-saveable-select" data-key="<?php echo esc_attr( $opt_key('icon') ); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                            <?php foreach ( $icon_choices as $ik => $il ) : ?>
                            <option value="<?php echo esc_attr($ik); ?>" <?php selected($cfg['icon'],$ik); ?>><?php echo esc_html($il); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Style -->
                    <div class="rdrit-tax-field">
                        <label>✏️ Style</label>
                        <select class="rdrit-saveable-select"
                                data-key="<?php echo esc_attr( $opt_key('style') ); ?>"
                                data-nonce="<?php echo esc_attr($nonce); ?>"
                                data-preview="rdrit-tax-preview-<?php echo esc_attr($tax_slug); ?>">
                            <?php foreach ( array('solid'=>'Plein','pill'=>'Pill','outline'=>'Contour','flat'=>'Flat','mono'=>'Mono') as $sk=>$sl ) : ?>
                            <option value="<?php echo esc_attr($sk); ?>" <?php selected($cfg['style'],$sk); ?>><?php echo esc_html($sl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Couleur -->
                    <div class="rdrit-tax-field">
                        <label>🎨 Couleur</label>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <select class="rdrit-saveable-select rdrit-color-mode-select"
                                    data-key="<?php echo esc_attr( $opt_key('color_mode') ); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>"
                                    data-custom-target="rdrit-color-custom-<?php echo esc_attr($tax_slug); ?>">
                                <?php foreach( array('auto'=>'Arc-en-ciel','accent'=>'Accent','gray'=>'Gris','custom'=>'Personnalisee') as $ck=>$cl ) : ?>
                                <option value="<?php echo esc_attr($ck); ?>" <?php selected($cfg['color_mode'],$ck); ?>><?php echo esc_html($cl); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="color"
                                   id="rdrit-color-custom-<?php echo esc_attr($tax_slug); ?>"
                                   class="rdrit-saveable-color <?php echo $cfg['color_mode']==='custom'?'':'rdrit-hidden'; ?>"
                                   data-key="<?php echo esc_attr( $opt_key('color') ); ?>"
                                   data-nonce="<?php echo esc_attr($nonce); ?>"
                                   value="<?php echo esc_attr($cfg['color']); ?>">
                        </div>
                    </div>
                    <!-- Apercu -->
                    <div class="rdrit-tax-field">
                        <label>🔗 Lien archive</label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer">
                            <input type="checkbox" class="rdrit-saveable-check"
                                   data-key="<?php echo esc_attr( $opt_key('disable_link') ); ?>"
                                   data-nonce="<?php echo esc_attr($nonce); ?>"
                                   <?php checked( !empty($cfg['disable_link']), true ); ?>>
                            Désactiver le lien vers l'archive
                        </label>
                    </div>
                    <!-- Apercu -->
                    <div class="rdrit-tax-field">
                        <label>👁️ Aperçu</label>
                        <?php $preview_style = $cfg['style']==='mono' ? '' : 'style="--rdrit-badge-color:' . esc_attr($cfg['color_mode']==='custom'?$cfg['color']:RDRIT_TAX_PALETTE[0]) . '"'; ?>
                        <div id="rdrit-tax-preview-<?php echo esc_attr($tax_slug); ?>"
                             class="rdrit-tax-preview-wrap"
                             data-style="<?php echo esc_attr($cfg['style']); ?>"
                             data-color-mode="<?php echo esc_attr($cfg['color_mode']); ?>"
                             data-color="<?php echo esc_attr($cfg['color']); ?>">
                            <span class="rdrit-term-badge rdrit-badge-style--<?php echo esc_attr($cfg['style']); ?>" <?php echo $preview_style; ?>><?php echo esc_html($tax->labels->singular_name ?? $tax->label); ?></span>
                            <span class="rdrit-term-badge rdrit-badge-style--<?php echo esc_attr($cfg['style']); ?>" <?php echo $preview_style; ?>>Exemple</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ( empty($by_cpt) ) : ?>
        <div class="rdrit-section"><p style="color:#6b7280">Aucune taxonomie publique detectee.</p></div>
        <?php endif; ?>
    </div>

    <!-- ══ ORDRE & SEPARATEURS ══ -->
    <div class="rdrit-tab-content" id="tab-order">

        <div class="rdrit-section rdrit-section--info">
            <p>↕️ <strong>Glissez-déposez</strong> les éléments pour définir leur ordre dans chaque zone. Les éléments positionnés dans la mauvaise zone sont ignorés au rendu (ex: une taxonomie configurée en "bas" n'apparaîtra pas dans la liste "haut").</p>
        </div>

        <div class="rdrit-order-columns">

            <!-- ZONE TOP -->
            <div class="rdrit-order-panel">
                <div class="rdrit-order-panel-head">
                    <span class="rdrit-zone-badge rdrit-zone-badge--top">↑ Sous le titre</span>
                    <button class="rdrit-order-reset button-link" data-zone="top" data-nonce="<?php echo esc_attr($nonce); ?>">Réinitialiser</button>
                </div>
                <ul class="rdrit-sortable" id="rdrit-sortable-top" data-zone="top" data-nonce="<?php echo esc_attr($nonce); ?>">
                    <?php foreach ( $order_top as $slug ) : ?>
                    <li class="rdrit-sort-item" data-slug="<?php echo esc_attr($slug); ?>">
                        <span class="rdrit-sort-handle">⠿</span>
                        <span class="rdrit-sort-label"><?php echo wp_kses( $zone_labels[$slug] ?? $slug, array('code'=>array()) ); ?></span>
                        <?php echo rdrit_sep_controls( $slug, $nonce ); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- ZONE BOTTOM -->
            <div class="rdrit-order-panel">
                <div class="rdrit-order-panel-head">
                    <span class="rdrit-zone-badge rdrit-zone-badge--bottom">↓ Après le contenu</span>
                    <button class="rdrit-order-reset button-link" data-zone="bottom" data-nonce="<?php echo esc_attr($nonce); ?>">Réinitialiser</button>
                </div>
                <ul class="rdrit-sortable" id="rdrit-sortable-bottom" data-zone="bottom" data-nonce="<?php echo esc_attr($nonce); ?>">
                    <?php foreach ( $order_bottom as $slug ) : ?>
                    <li class="rdrit-sort-item" data-slug="<?php echo esc_attr($slug); ?>">
                        <span class="rdrit-sort-handle">⠿</span>
                        <span class="rdrit-sort-label"><?php echo wp_kses( $zone_labels[$slug] ?? $slug, array('code'=>array()) ); ?></span>
                        <?php echo rdrit_sep_controls( $slug, $nonce ); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </div><!-- /.rdrit-order-columns -->

    </div>

    <!-- ══ SHARE & KO-FI ══ -->
    <div class="rdrit-tab-content" id="tab-share">
        <div class="rdrit-section">
            <h2>❤️ Ko-fi &amp; Partage social</h2>
            <div class="rdrit-field-row">
                <label><strong>📍 Position</strong></label>
                <div class="rdrit-pos-btns">
                    <?php $sp = $g('rdrit_pf_pos_share','bottom'); ?>
                    <?php foreach(array('top'=>'↑ Sous le titre','bottom'=>'↓ Après contenu') as $pv=>$pl) : ?>
                    <label class="rdrit-pos-btn <?php echo $sp===$pv?'active':''; ?>">
                        <input type="radio" name="pos_share" value="<?php echo esc_attr($pv); ?>" class="rdrit-pos-radio" data-key="rdrit_pf_pos_share" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($sp,$pv); ?>><?php echo esc_html($pl); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php rdrit_tr('rdrit_pf_kofi_enabled','☕ Bouton Ko-fi','Affiche le bouton de soutien',$nonce); ?>
            <div class="rdrit-sub-option">
                <div class="rdrit-two-col">
                    <div><label>URL Ko-fi</label>
                        <input type="url" class="rdrit-text-input rdrit-saveable-text" data-key="rdrit_pf_kofi_url" data-nonce="<?php echo esc_attr($nonce); ?>" value="<?php echo esc_attr($g('rdrit_pf_kofi_url','https://ko-fi.com/rdrit0980')); ?>"></div>
                    <div><label>Couleur</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="color" class="rdrit-saveable-color" data-key="rdrit_pf_kofi_color" data-nonce="<?php echo esc_attr($nonce); ?>" value="<?php echo esc_attr($g('rdrit_pf_kofi_color','#c0392b')); ?>">
                            <span class="rdrit-kofi-preview" style="background:<?php echo esc_attr($g('rdrit_pf_kofi_color','#c0392b')); ?>">☕ Soutenir</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php rdrit_tr('rdrit_pf_gps_enabled','🔍 Google — Sources préférées','Bouton invitant les lecteurs à ajouter le site à leurs sources préférées dans Google Actualités / Discover',$nonce); ?>
            <div class="rdrit-sub-option">
                <div class="rdrit-two-col">
                    <div><label>Domaine (vide = domaine du site)</label>
                        <input type="text" class="rdrit-text-input rdrit-saveable-text" data-key="rdrit_pf_gps_domain" data-nonce="<?php echo esc_attr($nonce); ?>" placeholder="<?php echo esc_attr( preg_replace('/^www\./','', (string) wp_parse_url(home_url(), PHP_URL_HOST)) ); ?>" value="<?php echo esc_attr($g('rdrit_pf_gps_domain','')); ?>"></div>
                    <div><label>Couleur</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="color" class="rdrit-saveable-color" data-key="rdrit_pf_gps_color" data-nonce="<?php echo esc_attr($nonce); ?>" value="<?php echo esc_attr($g('rdrit_pf_gps_color','#4285f4')); ?>">
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:.35em .8em;border-radius:50px;font-size:.8rem;font-weight:600;border:1.5px solid <?php echo esc_attr($g('rdrit_pf_gps_color','#4285f4')); ?>;color:<?php echo esc_attr($g('rdrit_pf_gps_color','#4285f4')); ?>">🔍 Sources préférées</span>
                        </div>
                    </div>
                </div>
                <div style="margin-top:10px">
                    <label>Libellé du bouton</label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text" data-key="rdrit_pf_gps_label" data-nonce="<?php echo esc_attr($nonce); ?>" value="<?php echo esc_attr($g('rdrit_pf_gps_label','Ajouter à vos sources préférées')); ?>">
                    <?php if(function_exists('pll_register_string')): ?>
                    <p style="font-size:11px;color:#1d4ed8;margin:4px 0 0;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:4px 8px">
                        Polylang actif — traduire dans <strong>Polylang → Traductions de chaînes → groupe « RDR-IT Post Footer »</strong>.
                    </p>
                    <?php endif; ?>
                </div>
                <p style="font-size:11px;color:#6b7280;margin:8px 0 0">
                    URL générée : <code>https://www.google.com/preferences/source?q=<?php echo esc_html( $g('rdrit_pf_gps_domain','') ?: preg_replace('/^www\./','', (string) wp_parse_url(home_url(), PHP_URL_HOST)) ); ?></code>
                </p>
            </div>
            <?php rdrit_tr('rdrit_pf_share_enabled','📢 Partage social','Boutons de partage',$nonce); ?>
            <div class="rdrit-sub-option">
                <div class="rdrit-networks-grid">
                    <?php foreach(array('rdrit_pf_share_facebook'=>'📘 Facebook','rdrit_pf_share_twitter'=>'𝕏 Twitter/X','rdrit_pf_share_linkedin'=>'💼 LinkedIn','rdrit_pf_share_reddit'=>'🟠 Reddit') as $k=>$l): ?>
                    <label class="rdrit-network-toggle">
                        <input type="checkbox" class="rdrit-saveable-check" data-key="<?php echo esc_attr($k); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($g($k),1); ?>>
                        <span><?php echo $l; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>🚫 Désactiver par CPT</h2>
            <p class="rdrit-section-desc">Ko-fi et Partage seront masqués sur tous les posts de ces types de contenu.<br>
            Pour désactiver sur un post précis, utilisez la case à cocher dans la <strong>meta box RDR-IT Tools</strong> dans l'éditeur.</p>
            <div class="rdrit-cpt-grid">
            <?php
            $share_disabled = array_filter( explode( ',', get_option('rdrit_pf_share_disabled_cpts','') ) );
            $excluded_pt    = array('attachment','wp_block','wp_template','wp_template_part',
                                    'wp_navigation','wp_font_face','wp_font_family','revision',
                                    'nav_menu_item','custom_css','customize_changeset');
            foreach ( get_post_types(array('public'=>true,'show_ui'=>true),'objects') as $cs=>$co ) :
                if( in_array($cs,$excluded_pt,true) ) continue;
            ?>
            <div class="rdrit-cpt-row">
                <div>
                    <strong><?php echo esc_html($co->label); ?></strong>
                    <code><?php echo esc_html($cs); ?></code>
                </div>
                <label class="rdrit-switch">
                    <input type="checkbox" class="rdrit-share-cpt-toggle"
                           data-cpt="<?php echo esc_attr($cs); ?>"
                           data-nonce="<?php echo esc_attr($nonce); ?>"
                           <?php checked( in_array($cs,$share_disabled,true), true ); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
            </div>
            <p style="font-size:.78em;color:#9ca3af;margin-top:8px">
                💡 Toggle <strong>activé</strong> = Ko-fi &amp; Partage <strong>désactivés</strong> sur ce CPT.
            </p>
        </div>

    </div>

    <!-- ══ RELATED ══ -->
    <div class="rdrit-tab-content" id="tab-related">
        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div><h2>📚 Articles similaires</h2>
                <p class="rdrit-section-desc">Moteur maison — catégories ×3 + tags ×2 + mots titre ×1. Cache transient WP.</p></div>
                <label class="rdrit-switch rdrit-switch--lg">
                    <input type="checkbox" class="rdrit-saveable-check" data-key="rdrit_pf_related_enabled" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($g('rdrit_pf_related_enabled'),1); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
            <div class="rdrit-field-row" style="margin-top:12px">
                <label><strong>📍 Position</strong></label>
                <div class="rdrit-pos-btns">
                    <?php $rp=$g('rdrit_pf_pos_related','bottom'); ?>
                    <?php foreach(array('top'=>'↑ Sous le titre','bottom'=>'↓ Après contenu') as $pv=>$pl) : ?>
                    <label class="rdrit-pos-btn <?php echo $rp===$pv?'active':''; ?>">
                        <input type="radio" name="pos_related" value="<?php echo esc_attr($pv); ?>" class="rdrit-pos-radio" data-key="rdrit_pf_pos_related" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($rp,$pv); ?>><?php echo esc_html($pl); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="rdrit-section">
            <h2>Affichage</h2>
            <div class="rdrit-opt-grid">
                <div class="rdrit-opt-group">
                    <label>Titre</label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text" data-key="rdrit_pf_related_title" data-nonce="<?php echo esc_attr($nonce); ?>" value="<?php echo esc_attr($g('rdrit_pf_related_title','📚 Articles similaires')); ?>">
                </div>
                <div class="rdrit-opt-group">
                    <label>Nombre</label>
                    <?php rdrit_sel('rdrit_pf_related_count',array('2'=>'2','3'=>'3','4'=>'4','6'=>'6','8'=>'8'),(string)$g('rdrit_pf_related_count',4),$nonce); ?>
                </div>
                <div class="rdrit-opt-group">
                    <label>Chargement</label>
                    <?php rdrit_sel('rdrit_pf_related_mode',array('sync'=>'Synchrone','ajax'=>'AJAX + Lazy load'),$g('rdrit_pf_related_mode','sync'),$nonce); ?>
                </div>
                <div class="rdrit-opt-group rdrit-opt-full">
                    <label>Layout</label>
                    <div class="rdrit-layout-grid" id="rdrit-layout-picker" data-key="rdrit_pf_related_layout" data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php foreach(array('text'=>array('☰','Texte'),'cards-2'=>array('⊞','2 col'),'cards-3'=>array('⊟','3 col'),'cards-4'=>array('⊠','4 col')) as $lk=>$lv): ?>
                        <div class="rdrit-layout-card <?php echo $g('rdrit_pf_related_layout','cards-3')===$lk?'active':''; ?>" data-layout="<?php echo esc_attr($lk); ?>">
                            <span class="rdrit-layout-icon"><?php echo $lv[0]; ?></span>
                            <strong><?php echo esc_html($lv[1]); ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="rdrit-section">
            <h2>Cache</h2>
            <div class="rdrit-opt-grid">
                <div class="rdrit-opt-group">
                    <label>Durée</label>
                    <?php rdrit_sel('rdrit_rel_cache_hours',array('1'=>'1h','6'=>'6h','12'=>'12h','24'=>'24h','72'=>'3j','168'=>'7j'),(string)$g('rdrit_rel_cache_hours',24),$nonce); ?>
                </div>
                <div class="rdrit-opt-group" style="display:flex;flex-direction:column;justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button class="button button-secondary" id="rdrit-flush-btn" data-nonce="<?php echo esc_attr($nonce); ?>">Vider le cache</button>
                    <span id="rdrit-flush-msg" style="font-size:.82em;color:#1a6b35;margin-top:4px;display:none;"></span>
                </div>
            </div>
        </div>
        <div class="rdrit-section">
            <h2>Activation par CPT</h2>
            <div class="rdrit-cpt-grid">
                <?php foreach($all_cpts as $cs=>$co): ?>
                <div class="rdrit-cpt-row">
                    <div><strong><?php echo esc_html($co->label); ?></strong><code><?php echo esc_html($cs); ?></code></div>
                    <label class="rdrit-switch">
                        <input type="checkbox" class="rdrit-cpt-toggle" data-cpt="<?php echo esc_attr($cs); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked(!in_array($cs,$disabled_cpts_arr,true),true); ?>>
                        <span class="rdrit-slider"></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>


    <!-- ══ FEEDS ══ -->
    <div class="rdrit-tab-content" id="tab-feeds">
        <div class="rdrit-section rdrit-section--info">
            <p>📡 Définissez quels types de contenus apparaissent dans votre flux RSS principal (<code>/feed/</code>). Par défaut WordPress n'y inclut que les <em>Posts</em> standard.</p>
        </div>
        <div class="rdrit-section">
            <h2>📡 CPT inclus dans le flux RSS</h2>
            <p class="rdrit-section-desc">Détection automatique — tout nouveau CPT public apparaît ici.</p>
            <div class="rdrit-cpt-grid">
            <?php
            $feed_saved = array_filter( explode( ',', get_option('rdrit_feed_cpts','') ) );
            foreach ( rdrit_feed_get_eligible_cpts() as $cs => $co ) :
                $checked = empty($feed_saved) ? ($cs==='post') : in_array($cs,$feed_saved,true);
            ?>
            <div class="rdrit-cpt-row">
                <div>
                    <strong><?php echo esc_html($co->label); ?></strong>
                    <code><?php echo esc_html($cs); ?></code>
                    <?php if($cs==='post') echo '<span style="font-size:.75em;color:#6b7280;margin-left:4px;">— WordPress standard</span>'; ?>
                </div>
                <label class="rdrit-switch">
                    <input type="checkbox" class="rdrit-feed-cpt-toggle" data-cpt="<?php echo esc_attr($cs); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($checked,true); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
            </div>
            <p style="font-size:.82em;color:#9ca3af;margin-top:10px;">
                💡 Prévisualisez votre flux : <a href="<?php echo esc_url(get_feed_link()); ?>" target="_blank"><?php echo esc_url(get_feed_link()); ?></a>
            </p>
        </div>
    </div>

    <!-- ══ ADSENSE & ADBLOCK ══ -->
    <div class="rdrit-tab-content" id="tab-adsense">
        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div><h2>💰 AdSense &amp; AdBlock</h2>
                <p class="rdrit-section-desc">Lazy loading AdSense, détection AdBlock configurable, et support AMP.</p></div>
                <label class="rdrit-switch rdrit-switch--lg">
                    <input type="checkbox" class="rdrit-saveable-check" data-key="rdrit_ads_enabled" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($g('rdrit_ads_enabled',0),1); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
            <div class="rdrit-opt-group" style="margin-top:12px;max-width:400px">
                <label>ID client AdSense <code>ca-pub-…</code></label>
                <input type="text" class="rdrit-text-input rdrit-saveable-text"
                       data-key="rdrit_ads_client" data-nonce="<?php echo esc_attr($nonce); ?>"
                       placeholder="ca-pub-XXXXXXXXXXXXXXXXX"
                       value="<?php echo esc_attr($g('rdrit_ads_client','')); ?>">
            </div>
        </div>



        <div class="rdrit-section">
            <h2>⚡ Lazy Load AdSense</h2>
            <?php rdrit_tr('rdrit_ads_lazy_enabled','Lazy load AdSense','Charge le script AdSense uniquement quand le visiteur approche du conteneur',$nonce); ?>
            <div class="rdrit-sub-option">
                <div class="rdrit-two-col-auto">
                    <div>
                        <label>Sélecteur CSS du conteneur</label>
                        <input type="text" class="rdrit-text-input rdrit-saveable-text"
                               data-key="rdrit_ads_lazy_selector" data-nonce="<?php echo esc_attr($nonce); ?>"
                               placeholder="#main"
                               value="<?php echo esc_attr($g('rdrit_ads_lazy_selector','#main')); ?>">
                    </div>
                    <div>
                        <label>Anticipation <code>rootMargin</code></label>
                        <div style="display:flex;align-items:center;gap:6px">
                            <input type="number" min="0" max="5000" step="100"
                                   class="rdrit-text-input rdrit-saveable-number"
                                   data-key="rdrit_ads_lazy_margin" data-nonce="<?php echo esc_attr($nonce); ?>"
                                   value="<?php echo esc_attr($g('rdrit_ads_lazy_margin',1000)); ?>"
                                   style="width:90px">
                            <span style="color:#6b7280;font-size:.85em">px</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>📢 Désactiver AdSense par capacité</h2>
            <p class="rdrit-section-desc">
                Les utilisateurs connectés possédant l'une de ces capacités ne verront <strong>pas</strong> le script AdSense.<br>
                Les visiteurs non connectés voient toujours les annonces.
            </p>
            <?php rdrit_caps_selector( 'rdrit_ads_disabled_caps', $nonce ); ?>
        </div>

        <div class="rdrit-section">
            <h2>🛡️ Désactiver détection AdBlock par capacité</h2>
            <p class="rdrit-section-desc">
                Les utilisateurs connectés possédant l'une de ces capacités ne seront <strong>pas</strong> détectés comme adblockeurs.
            </p>
            <?php rdrit_caps_selector( 'rdrit_adblock_disabled_caps', $nonce ); ?>
        </div>

        <div class="rdrit-section">
            <h2>🛡️ Détection AdBlock</h2>
            <?php rdrit_tr('rdrit_adblock_enabled','Activer la détection AdBlock','Uniquement sur les articles (is_single)',$nonce); ?>
            <?php rdrit_tr('rdrit_adblock_debug','Mode debug AdBlock (console)','Active les logs [RDRIT AdBlock] dans la console navigateur. Désactiver en production.',$nonce); ?>
            <div class="rdrit-sub-option" style="margin-top:10px">
                <div class="rdrit-opt-group" style="margin-bottom:14px;max-width:300px">
                    <label>Action quand AdBlock détecté</label>
                    <?php rdrit_sel('rdrit_adblock_action',
                        array(
                            'none'   => 'Rien (détection silencieuse)',
                            'banner' => 'Afficher un bandeau',
                            'images' => 'Remplacer les images',
                            'both'   => 'Bandeau + remplacement images',
                        ),
                        $g('rdrit_adblock_action','both'), $nonce
                    ); ?>
                </div>

                <div class="rdrit-adblock-sub rdrit-adblock-sub--banner" style="border-top:1px dashed #e5e7eb;padding-top:12px;margin-top:4px">
                    <p style="font-size:.82em;font-weight:700;margin:0 0 8px;color:#374151">📣 Bandeau</p>
                    <div class="rdrit-two-col-auto">
                        <div>
                            <label>Message</label>
                            <input type="text" class="rdrit-text-input rdrit-saveable-text"
                                   data-key="rdrit_adblock_banner_msg" data-nonce="<?php echo esc_attr($nonce); ?>"
                                   value="<?php echo esc_attr($g('rdrit_adblock_banner_msg','Bonjour 👋 Ce site vit grâce à la publicité. Merci de désactiver votre bloqueur de pubs !')); ?>">
                        </div>
                        <div>
                            <label>Couleur du bandeau</label>
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="color" class="rdrit-saveable-color"
                                       data-key="rdrit_adblock_banner_color" data-nonce="<?php echo esc_attr($nonce); ?>"
                                       value="<?php echo esc_attr($g('rdrit_adblock_banner_color','#1e40af')); ?>">
                                <span class="rdrit-adblock-banner-preview"
                                      style="background:<?php echo esc_attr($g('rdrit_adblock_banner_color','#1e40af')); ?>;color:#fff;padding:4px 12px;border-radius:4px;font-size:.8em;font-weight:700">
                                    Aperçu bandeau
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rdrit-adblock-sub rdrit-adblock-sub--images" style="border-top:1px dashed #e5e7eb;padding-top:12px;margin-top:10px">
                    <p style="font-size:.82em;font-weight:700;margin:0 0 8px;color:#374151">🖼️ Remplacement images</p>
                    <div class="rdrit-two-col-auto">
                        <div>
                            <label>URL image de remplacement</label>
                            <input type="url" class="rdrit-text-input rdrit-saveable-text"
                                   data-key="rdrit_adblock_img_url" data-nonce="<?php echo esc_attr($nonce); ?>"
                                   placeholder="https://…/image.png"
                                   value="<?php echo esc_attr($g('rdrit_adblock_img_url','https://static.rdr-it.com/images/rdr-img-message.png')); ?>">
                        </div>
                        <div>
                            <label>Sélecteur CSS des éléments</label>
                            <input type="text" class="rdrit-text-input rdrit-saveable-text"
                                   data-key="rdrit_adblock_img_selector" data-nonce="<?php echo esc_attr($nonce); ?>"
                                   placeholder=".wp-block-image > .fancybox"
                                   value="<?php echo esc_attr($g('rdrit_adblock_img_selector','.wp-block-image > .fancybox')); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>💬 Notice AdBlock (bannière / modale)</h2>
            <p style="font-size:.82em;color:#6b7280;margin:0 0 10px">
                Affiché uniquement quand AdBlock est détecté. Chargé en async, invisible aux bots. Mémorisé <?= esc_html(get_option('rdrit_adblock_notice_ttl',24)) ?>h via localStorage.
                Messages en JSON multi-langue : <code>{"fr":"...","en":"..."}</code> (HTML autorisé).
            </p>

            <?php rdrit_tr('rdrit_adblock_notice_enabled','Activer la notice AdBlock','Active l\'affichage de la bannière et/ou modale quand AdBlock est détecté.',$nonce); ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px">
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
                    <h3 style="margin:0 0 10px;font-size:14px">📢 Bannière</h3>
                    <?php rdrit_tr('rdrit_adblock_notice_banner','Activer la bannière','Bandeau en bas de page.',$nonce); ?>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Délai d'affichage (secondes)</label>
                        <input type="number" min="0" max="60" style="width:70px;display:block;margin-top:4px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px"
                               value="<?= esc_attr(get_option('rdrit_adblock_notice_banner_delay',2)) ?>"
                               class="rdrit-saveable-text" data-key="rdrit_adblock_notice_banner_delay" data-nonce="<?= esc_attr($nonce) ?>">
                    </div>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Couleur fond</label>
                        <input type="color" value="<?= esc_attr(get_option('rdrit_adblock_notice_banner_bg','#1e3a5f')) ?>"
                               class="rdrit-saveable-color" data-key="rdrit_adblock_notice_banner_bg" data-nonce="<?= esc_attr($nonce) ?>" style="margin-top:4px;display:block">
                    </div>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Message (HTML)</label>
                        <?php if(function_exists('pll_register_string')): ?>
                        <p style="font-size:11px;color:#6b7280;margin:2px 0 4px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:4px 8px">
                            Polylang actif — traduire dans <strong>Polylang → Traductions de chaînes → groupe "RDR-IT AdBlock"</strong>.
                        </p>
                        <?php else: ?>
                        <p style="font-size:11px;color:#6b7280;margin:2px 0 4px">Sans Polylang : JSON multi-langue <code>{"fr":"...","en":"..."}</code> ou HTML simple.</p>
                        <?php endif; ?>
                        <textarea rows="5" style="width:100%;margin-top:4px;font-family:monospace;font-size:11px;border:1px solid #d1d5db;border-radius:6px;padding:6px;box-sizing:border-box"
                                  class="rdrit-saveable-text" data-key="rdrit_adblock_notice_banner_msg" data-nonce="<?= esc_attr($nonce) ?>"
                                  ><?= esc_textarea(get_option('rdrit_adblock_notice_banner_msg','')) ?></textarea>
                    </div>
                </div>

                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px">
                    <h3 style="margin:0 0 10px;font-size:14px">🪟 Modale</h3>
                    <?php rdrit_tr('rdrit_adblock_notice_modal','Activer la modale','Fenêtre centrée sur la page.',$nonce); ?>
                    <?php rdrit_tr('rdrit_adblock_notice_modal_random','Mode aléatoire (1/N pages)','Si actif, le TTL est ignoré : la modale peut réapparaître même si déjà fermée. La bannière n\'est pas bloquée par la fermeture de la modale.',$nonce); ?>
                    <?php rdrit_tr('rdrit_adblock_notice_modal_next_page','Modale sur pages suivantes uniquement','Si bannière et modale sont toutes les deux actives : affiche la bannière sur la page courante, la modale uniquement sur les pages suivantes. Évite tout affichage simultané.',$nonce); ?>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Délai d'affichage (secondes)</label>
                        <input type="number" min="0" max="120" style="width:70px;display:block;margin-top:4px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px"
                               value="<?= esc_attr(get_option('rdrit_adblock_notice_modal_delay',5)) ?>"
                               class="rdrit-saveable-text" data-key="rdrit_adblock_notice_modal_delay" data-nonce="<?= esc_attr($nonce) ?>">
                    </div>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Chance aléatoire (1 sur N, mode random)</label>
                        <input type="number" min="2" max="20" style="width:70px;display:block;margin-top:4px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px"
                               value="<?= esc_attr(get_option('rdrit_adblock_notice_modal_chance',5)) ?>"
                               class="rdrit-saveable-text" data-key="rdrit_adblock_notice_modal_chance" data-nonce="<?= esc_attr($nonce) ?>">
                    </div>
                    <div style="margin-top:8px">
                        <label style="font-size:12px;color:#6b7280">Message (HTML)</label>
                        <?php if(function_exists('pll_register_string')): ?>
                        <p style="font-size:11px;color:#6b7280;margin:2px 0 4px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:4px 8px">
                            Polylang actif — traduire dans <strong>Polylang → Traductions de chaînes → groupe "RDR-IT AdBlock"</strong>.
                        </p>
                        <?php endif; ?>
                        <textarea rows="5" style="width:100%;margin-top:4px;font-family:monospace;font-size:11px;border:1px solid #d1d5db;border-radius:6px;padding:6px;box-sizing:border-box"
                                  class="rdrit-saveable-text" data-key="rdrit_adblock_notice_modal_msg" data-nonce="<?= esc_attr($nonce) ?>"
                                  ><?= esc_textarea(get_option('rdrit_adblock_notice_modal_msg','')) ?></textarea>
                    </div>
                </div>
            </div>

            <div style="margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <div>
                    <label style="font-size:12px;color:#6b7280">Mémorisation fermeture (heures)</label>
                    <input type="number" min="1" max="720" style="width:80px;display:block;margin-top:4px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px"
                           value="<?= esc_attr(get_option('rdrit_adblock_notice_ttl',24)) ?>"
                           class="rdrit-saveable-text" data-key="rdrit_adblock_notice_ttl" data-nonce="<?= esc_attr($nonce) ?>">
                </div>
                <div style="font-size:.8em;color:#6b7280;max-width:350px">
                    <strong>Format JSON multi-langue :</strong><br>
                    <code>{"fr":"&lt;p&gt;Bonjour !&lt;/p&gt;","en":"&lt;p&gt;Hello!&lt;/p&gt;"}</code><br>
                    Ou texte/HTML simple (toutes langues).
                </div>
            </div>

            <div style="margin-top:10px">
                <label style="font-size:12px;color:#6b7280">Désactiver la notice pour les capacités</label>
                <?php rdrit_caps_selector('rdrit_adblock_disabled_caps', $nonce); ?>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>📱 AMP AdSense</h2>
            <?php rdrit_tr('rdrit_ads_amp_enabled','AMP Auto Ads','Injecte amp-auto-ads dans les pages AMP (requiert le plugin AMP)',$nonce); ?>
            <p style="font-size:.82em;color:#6b7280;margin:6px 0 0">Utilise l'ID client AdSense saisi ci-dessus.</p>
        </div>
    </div>


    <!-- ══ SHORTCODES ══ -->
    <div class="rdrit-tab-content" id="tab-shortcodes">

        <div class="rdrit-section rdrit-section--info">
            <p>🧩 Ces shortcodes s'utilisent dans les <strong>widgets texte / HTML</strong> de votre sidebar (ou n'importe où dans le contenu). La visibilité des widgets se gère via <strong>Jetpack</strong> comme d'habitude.</p>
        </div>

        <div class="rdrit-section">
            <h2>📋 Shortcodes disponibles</h2>

            <div class="rdrit-sc-doc-grid">

                <div class="rdrit-sc-doc-card">
                    <div class="rdrit-sc-doc-head">
                        <code>[rdrit_latest]</code>
                        <span class="rdrit-sc-doc-badge">Derniers articles</span>
                    </div>
                    <p>Affiche les <em>N</em> derniers articles d'un CPT, par date décroissante.</p>
                    <pre class="rdrit-sc-pre">[rdrit_latest cpt="howto" count="3" category="linux" title="fr:Derniers tutos|en:Latest tutorials|de:Letzte Tutorials|es:Últimos tutoriales|it:Ultimi tutorial|pt:Últimos tutoriais"]</pre>
                </div>

                <div class="rdrit-sc-doc-card">
                    <div class="rdrit-sc-doc-head">
                        <code>[rdrit_random]</code>
                        <span class="rdrit-sc-doc-badge rdrit-sc-doc-badge--purple">Aléatoire</span>
                    </div>
                    <p>Affiche <em>N</em> articles au hasard. Idéal pour la découverte.</p>
                    <pre class="rdrit-sc-pre">[rdrit_random cpt="domotique" count="2" mode="async" title="fr:Tuto au hasard|en:Random tutorial|de:Zufälliges Tutorial"]</pre>
                </div>

            </div>
            <div class="rdrit-sc-doc-card" style="grid-column:1/-1">
                    <div class="rdrit-sc-doc-head">
                        <code>[rdrit_localstorage]</code>
                        <span class="rdrit-sc-doc-badge" style="background:#dcfce7;color:#166534">LocalStorage</span>
                    </div>
                    <p>Affiche les préférences localStorage du visiteur avec bouton de suppression. Le bloc est <strong>invisible si localStorage est vide</strong>.</p>
                    <pre class="rdrit-sc-pre">[rdrit_localstorage title="Mes préférences" btn_label="⚙️ Mes préférences" prefix="rdrit_" labels='{"rdrit_theme":"Thème","rdrit_glossary":"Glossaire"}']</pre>
                    <p style="font-size:.8em;margin-top:8px;color:#6b7280">
                        Affiche un <strong>bouton</strong> (masqué si localStorage vide). Au clic, ouvre une <strong>modal</strong> avec tableau des données + suppression par ligne ou tout effacer.<br>
                        <code>btn_label</code> — texte du bouton &nbsp;|&nbsp;
                        <code>prefix</code> — filtrer sur un préfixe de clé &nbsp;|&nbsp;
                        <code>labels</code> — JSON pour nommer les clés lisiblement
                    </p>
                </div>
        </div>

        <div class="rdrit-section">
            <h2>⚙️ Paramètres</h2>
            <table class="rdrit-params-table widefat">
                <thead>
                    <tr><th>Paramètre</th><th>Valeur par défaut</th><th>Description</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>cpt</code></td><td><code>post</code></td><td>Slug du post type (ex: <code>howto</code>, <code>domotique</code>, <code>post</code>)</td></tr>
                    <tr><td><code>count</code></td><td><code>3</code></td><td>Nombre de cartes à afficher (1–12)</td></tr>
                    <tr><td><code>category</code></td><td><em>vide</em></td><td>Slug de catégorie/taxonomie pour filtrer (ex: <code>linux</code>, <code>raspberry-pi</code>)</td></tr>
                    <tr><td><code>mode</code></td><td><code>sync</code></td><td><code>sync</code> — rendu direct | <code>async</code> — chargement lazy (IntersectionObserver + AJAX)</td></tr>
                    <tr><td><code>height</code></td><td><code>280</code></td><td>Hauteur minimale du skeleton en mode <code>async</code> (évite le CLS / LiteSpeed)</td></tr>
                    <tr><td><code>title</code></td><td><em>vide</em></td><td>
                        Titre du bloc. Supporte le format multilingue Polylang :<br>
                        <code>fr:Titre|en:Title|de:Titel|es:Título|it:Titolo|pt:Título</code><br>
                        <span style="color:#6b7280;font-size:.9em">→ Langue courante affichée · Absent si non traduit · Simple texte aussi accepté</span>
                    </td></tr>
                    <tr><td><code>cache</code></td><td><code>60</code></td><td>Durée du cache en minutes. <code>0</code> = pas de cache. Invalidé automatiquement à la publication d'un article.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="rdrit-section">
            <h2>💡 Exemples pratiques</h2>
            <div class="rdrit-examples-grid">

                <div class="rdrit-example">
                    <p class="rdrit-example-label">Section Domotique — 3 derniers tutos domotique</p>
                    <pre class="rdrit-sc-pre">[rdrit_latest cpt="domotique" count="3" title="fr:Derniers tutos domotique|en:Latest home automation tutorials"]</pre>
                </div>

                <div class="rdrit-example">
                    <p class="rdrit-example-label">Sidebar globale — tuto aléatoire en lazy load</p>
                    <pre class="rdrit-sc-pre">[rdrit_random cpt="howto" count="1" mode="async" height="160" cache="120" title="fr:Tuto au hasard|en:Random tutorial|de:Zufälliges Tutorial|es:Tutorial aleatorio|it:Tutorial casuale|pt:Tutorial aleatório"]</pre>
                </div>

                <div class="rdrit-example">
                    <p class="rdrit-example-label">Filtrer par catégorie spécifique</p>
                    <pre class="rdrit-sc-pre">[rdrit_latest cpt="post" count="4" category="linux" title="Articles Linux"]</pre>
                </div>

                <div class="rdrit-example">
                    <p class="rdrit-example-label">Widget sans cache (toujours frais)</p>
                    <pre class="rdrit-sc-pre">[rdrit_random cpt="howto" count="2" cache="0"]</pre>
                </div>

            </div>
        </div>

        <div class="rdrit-section">
            <h2>🚀 LiteSpeed — Exclusions recommandées</h2>
            <div class="rdrit-section rdrit-section--info" style="margin:0">
                <p>Le JS async utilise le handle <code>rdrit-sc-async</code>. Ajoutez-le dans <strong>LiteSpeed &gt; Page Optim &gt; JS Exclude</strong> pour éviter la minification/combinaison qui casserait le lazy load.</p>
                <p style="margin-top:6px">Handle à exclure : <code>rdrit-sc-async</code></p>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>🌍 Polylang — Comportement multilingue</h2>
            <div class="rdrit-section rdrit-section--info" style="margin:0">
                <p><strong>Articles :</strong> Polylang filtre automatiquement les résultats dans la langue courante du visiteur. Les articles non encore traduits n'apparaissent pas.</p>
                <p style="margin-top:6px"><strong>Titre :</strong> si la langue courante n'a pas de traduction dans le paramètre <code>title</code>, le titre est simplement masqué — les articles s'affichent quand même.</p>
                <p style="margin-top:6px"><strong>Cache :</strong> chaque langue a son propre cache (clé distincte par langue). Un seul shortcode gère les 6 langues.</p>
            </div>
        </div>

    </div>


    <!-- ══ MAILER ══ -->
    <div class="rdrit-tab-content" id="tab-mailer">
        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div><h2>✉️ Configuration Mail</h2>
                <p class="rdrit-section-desc">Gestion de l'expéditeur et du mode d'envoi des mails WordPress (<code>wp_mail()</code>).</p></div>
            </div>

            <div class="rdrit-opt-group" style="max-width:220px;margin-bottom:16px">
                <label>Mode d'envoi</label>
                <?php rdrit_sel('rdrit_mail_mode', array('php'=>'PHP natif (mail())','smtp'=>'SMTP','mailjet'=>'Mailjet API'), $g('rdrit_mail_mode','php'), $nonce); ?>
            </div>

            <div class="rdrit-two-col" style="max-width:560px;margin-bottom:16px">
                <div class="rdrit-opt-group">
                    <label>Email expéditeur</label>
                    <input type="email" class="rdrit-text-input rdrit-saveable-text"
                           data-key="rdrit_mail_from_email" data-nonce="<?php echo esc_attr($nonce); ?>"
                           placeholder="noreply@rdr-it.com"
                           value="<?php echo esc_attr($g('rdrit_mail_from_email','')); ?>">
                </div>
                <div class="rdrit-opt-group">
                    <label>Nom expéditeur</label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text"
                           data-key="rdrit_mail_from_name" data-nonce="<?php echo esc_attr($nonce); ?>"
                           placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"
                           value="<?php echo esc_attr($g('rdrit_mail_from_name','')); ?>">
                </div>
            </div>
        </div>

        <div class="rdrit-section rdrit-mailer-smtp-section" style="<?php echo $g('rdrit_mail_mode','php')==='smtp' ? '' : 'display:none'; ?>">
            <h2>🔌 Paramètres SMTP</h2>
            <div class="rdrit-two-col" style="max-width:560px;margin-bottom:12px">
                <div class="rdrit-opt-group">
                    <label>Serveur SMTP</label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text"
                           data-key="rdrit_smtp_host" data-nonce="<?php echo esc_attr($nonce); ?>"
                           placeholder="smtp.example.com"
                           value="<?php echo esc_attr($g('rdrit_smtp_host','')); ?>">
                </div>
                <div class="rdrit-opt-group">
                    <label>Port</label>
                    <input type="number" min="1" max="65535"
                           class="rdrit-text-input rdrit-saveable-number"
                           data-key="rdrit_smtp_port" data-nonce="<?php echo esc_attr($nonce); ?>"
                           value="<?php echo esc_attr($g('rdrit_smtp_port',587)); ?>"
                           style="width:100px">
                </div>
            </div>
            <div class="rdrit-two-col" style="max-width:560px;margin-bottom:12px">
                <div class="rdrit-opt-group">
                    <label>Chiffrement</label>
                    <?php rdrit_sel('rdrit_smtp_encryption', array(
                        'tls'  => 'TLS / STARTTLS (port 587)',
                        'ssl'  => 'SSL (port 465)',
                        'none' => 'Aucun (plaintext)',
                    ), $g('rdrit_smtp_encryption','tls'), $nonce); ?>
                </div>
                <div class="rdrit-opt-group">
                    <label>Authentification</label>
                    <label class="rdrit-switch" style="margin-top:6px">
                        <input type="checkbox" class="rdrit-saveable-check"
                               data-key="rdrit_smtp_auth" data-nonce="<?php echo esc_attr($nonce); ?>"
                               <?php checked($g('rdrit_smtp_auth',1),1); ?>>
                        <span class="rdrit-slider"></span>
                    </label>
                </div>
            </div>
            <div class="rdrit-two-col rdrit-smtp-auth-fields" style="max-width:560px;<?php echo $g('rdrit_smtp_auth',1) ? '' : 'display:none'; ?>">
                <div class="rdrit-opt-group">
                    <label>Utilisateur SMTP</label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text"
                           data-key="rdrit_smtp_user" data-nonce="<?php echo esc_attr($nonce); ?>"
                           autocomplete="off"
                           value="<?php echo esc_attr($g('rdrit_smtp_user','')); ?>">
                </div>
                <div class="rdrit-opt-group">
                    <label>Mot de passe SMTP</label>
                    <input type="password" class="rdrit-text-input rdrit-smtp-pass-input"
                           data-key="rdrit_smtp_pass" data-nonce="<?php echo esc_attr($nonce); ?>"
                           autocomplete="new-password"
                           placeholder="<?php echo $g('rdrit_smtp_pass','') ? '••••••••' : ''; ?>">
                    <p style="font-size:.75em;color:#9ca3af;margin:3px 0 0">Laisser vide pour conserver le mot de passe actuel</p>
                </div>
            </div>
        </div>

        <div class="rdrit-mailer-mailjet-section" style="<?php echo $g('rdrit_mail_mode','php')==='mailjet' ? '' : 'display:none'; ?>">
        <div class="rdrit-section">
            <h2>✈️ Paramètres Mailjet API</h2>
            <p class="rdrit-section-desc">
                Trouvez vos clés dans
                <a href="https://app.mailjet.com/account/apikeys" target="_blank">Mailjet → Mon compte → Clés API</a>.
                L'envoi se fait via <code>https://api.mailjet.com/v3.1/send</code> (HTTPS, sans SMTP).
            </p>
            <div class="rdrit-two-col" style="max-width:560px">
                <div class="rdrit-opt-group">
                    <label>Clé API <span style="color:#9ca3af;font-weight:400">(API Key)</span></label>
                    <input type="text" class="rdrit-text-input rdrit-saveable-text"
                           data-key="rdrit_mailjet_api_key" data-nonce="<?php echo esc_attr($nonce); ?>"
                           autocomplete="off"
                           placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           value="<?php echo esc_attr($g('rdrit_mailjet_api_key','')); ?>">
                </div>
                <div class="rdrit-opt-group">
                    <label>Clé secrète <span style="color:#9ca3af;font-weight:400">(Secret Key)</span></label>
                    <input type="password" class="rdrit-text-input rdrit-mailjet-secret-input"
                           data-key="rdrit_mailjet_api_secret" data-nonce="<?php echo esc_attr($nonce); ?>"
                           autocomplete="new-password"
                           placeholder="<?php echo $g('rdrit_mailjet_api_secret','') ? '••••••••' : ''; ?>">
                    <p style="font-size:.75em;color:#9ca3af;margin:3px 0 0">Laisser vide pour conserver la clé actuelle</p>
                </div>
            </div>
            <div class="rdrit-section--info" style="margin-top:12px;padding:10px 14px;border-radius:8px">
                <p style="margin:0;font-size:.82em">
                    💡 <strong>Expéditeur :</strong> l'adresse <em>Email expéditeur</em> ci-dessus doit être
                    <a href="https://app.mailjet.com/account/sender" target="_blank">vérifiée dans Mailjet</a>
                    pour que l'envoi fonctionne.
                </p>
            </div>
        </div>
        </div>

        <div class="rdrit-section">
            <h2>🧪 Test d'envoi</h2>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <input type="email" id="rdrit-mail-test-to" class="rdrit-text-input"
                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                       value="<?php echo esc_attr(get_option('admin_email')); ?>"
                       style="max-width:260px">
                <button id="rdrit-mail-test-btn" class="button button-primary"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                    Envoyer un mail de test
                </button>
                <span id="rdrit-mail-test-result" style="font-size:.88em"></span>
            </div>
            <?php
            $last = get_option('rdrit_mail_test_last','');
            if ($last) :
                $lr = json_decode($last, true);
            ?>
            <p style="font-size:.78em;color:#6b7280;margin-top:8px">
                Dernier test : <?php echo esc_html($lr['time'] ?? '—'); ?> →
                <?php echo ($lr['success'] ?? false) ? '<span style="color:#16a34a">✅ Succès</span>' : '<span style="color:#dc2626">❌ Échec</span>'; ?>
                <?php if (!empty($lr['errors'])) echo ' — ' . esc_html(implode(', ', $lr['errors'])); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ MATOMO ══ -->
    <div class="rdrit-tab-content" id="tab-matomo">
        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div><h2>📊 Matomo Analytics</h2>
                <p class="rdrit-section-desc">Injection du code de tracking Matomo avec exclusion par rôle WP.</p></div>
                <label class="rdrit-switch rdrit-switch--lg">
                    <input type="checkbox" class="rdrit-saveable-check"
                           data-key="rdrit_matomo_enabled" data-nonce="<?php echo esc_attr($nonce); ?>"
                           <?php checked($g('rdrit_matomo_enabled',0),1); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>🚫 Exclusion par rôle</h2>
            <p class="rdrit-section-desc">
                Toggle <strong>activé</strong> = ce rôle n'est <strong>pas tracké</strong> par Matomo.<br>
                Les visiteurs non connectés sont toujours trackés.
            </p>
            <?php rdrit_caps_selector( 'rdrit_matomo_disabled_caps', $nonce ); ?>
        </div>

        <div class="rdrit-section">
            <h2>📋 Code de tracking</h2>
            <div class="rdrit-opt-group">
                <label>
                    Coller le code JS Matomo
                    <span style="color:#9ca3af;font-weight:400">(avec ou sans balises &lt;script&gt;)</span>
                </label>
                <textarea class="rdrit-text-input rdrit-saveable-textarea"
                          data-key="rdrit_matomo_code" data-nonce="<?php echo esc_attr($nonce); ?>"
                          rows="10"
                          placeholder="var _paq = window._paq = window._paq || [];&#10;_paq.push(['trackPageView']);&#10;..."
                          style="font-family:monospace;font-size:.82em;width:100%;max-width:700px"><?php echo esc_textarea($g('rdrit_matomo_code','')); ?></textarea>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>👁️ Prévisualisation &amp; Clés de base de données</h2>
            <p style="font-size:.82em;color:#6b7280;margin-bottom:10px">
                Ces clés <code>wp_options</code> peuvent être utilisées par un plugin de gestion de cookies (ex: Complianz) pour activer/désactiver le tracking selon le consentement.
            </p>
            <table class="rdrit-params-table widefat" style="max-width:700px">
                <thead><tr><th>Option wp_options</th><th>Type</th><th>Valeur actuelle</th><th>Usage</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>rdrit_matomo_enabled</code></td>
                        <td><code>0</code> / <code>1</code></td>
                        <td><code><?php echo esc_html($g('rdrit_matomo_enabled',0)); ?></code></td>
                        <td>Activer/désactiver l'injection globale</td>
                    </tr>
                    <tr>
                        <td><code>rdrit_matomo_code</code></td>
                        <td>string</td>
                        <td><em style="color:#9ca3af"><?php echo $g('rdrit_matomo_code','') ? 'Code défini ('.strlen($g('rdrit_matomo_code','')).' chars)' : 'Vide'; ?></em></td>
                        <td>Code JS injecté dans le footer</td>
                    </tr>
                    <tr>
                        <td><code>rdrit_matomo_excluded_roles</code></td>
                        <td>array</td>
                        <td><em style="color:#9ca3af"><?php $r=get_option('rdrit_matomo_excluded_roles',array()); echo is_array($r)&&!empty($r)?implode(', ',$r):'(vide)'; ?></em></td>
                        <td>Rôles exclus du tracking (toggle ON = pas tracké)</td>
                    </tr>
                </tbody>
            </table>
            <div class="rdrit-section--info" style="margin-top:14px;padding:10px 14px;border-radius:8px">
                <p style="margin:0;font-size:.82em">
                    💡 <strong>Intégration plugin cookie :</strong> pour désactiver le tracking sur refus de consentement,
                    utilisez <code>update_option('rdrit_matomo_enabled', 0)</code> ou le filtre
                    <code>add_filter('rdrit_matomo_inject', '__return_false')</code> dans votre plugin cookie.
                </p>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>👁️ Aperçu du code injecté</h2>
            <?php $code = $g('rdrit_matomo_code',''); ?>
            <?php if ($code) : ?>
            <pre style="background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;font-size:.78em;overflow-x:auto;white-space:pre-wrap;max-height:200px;overflow-y:auto">&lt;!-- Matomo — RDR-IT Tools --&gt;
&lt;script&gt;
<?php echo esc_html(preg_replace('#</?script[^>]*>#i','',trim($code))); ?>

&lt;/script&gt;
&lt;!-- End Matomo --&gt;</pre>
            <?php else : ?>
            <p style="color:#9ca3af;font-style:italic;font-size:.85em">Aucun code configuré.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TWEAKS ══ -->
    <div class="rdrit-tab-content" id="tab-tweaks">
        <div class="rdrit-section rdrit-section--info">
            <p>🔧 Personnalisations WordPress issues de l'ancien plugin custom. Chaque option est activable indépendamment.</p>
        </div>

        <div class="rdrit-section">
            <h2>🔒 Sécurité &amp; Déploiement</h2>
            <?php rdrit_tr('rdrit_tweak_cloudflare_ssl',
                'Détection SSL Proxy Cloudflare',
                'Laisser actif si le site est derriere Cloudflare — desactiver provoque une boucle infinie dans l\'admin.',
                $nonce, true); // force actif par defaut ?>
            <?php rdrit_tr('rdrit_tweak_disable_autoupdate',
                'Desactiver les mises a jour automatiques',
                'Desactive <code>auto_update_plugin</code> et <code>auto_update_theme</code>.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>🖼️ Images</h2>
            <?php rdrit_tr('rdrit_tweak_image_sizes',
                'Tailles d\'image personnalisees rdrit-*',
                'Ajoute rdrit-800, rdrit-600, rdrit-400, rdrit-250, rdrit-200, rdrit-60 dans la mediatheque.',
                $nonce); ?>
            <div style="font-size:.8em;color:#6b7280;margin:4px 0 0 56px;line-height:1.8">
                <code>rdrit-800</code> 800×500 &nbsp;|&nbsp;
                <code>rdrit-600</code> 600×400 &nbsp;|&nbsp;
                <code>rdrit-400</code> 400×300 &nbsp;|&nbsp;
                <code>rdrit-250</code> 250×140 &nbsp;|&nbsp;
                <code>rdrit-200</code> 200×200 crop &nbsp;|&nbsp;
                <code>rdrit-60</code> 60×60 crop
            </div>
        </div>

        <div class="rdrit-section">
            <h2>⚡ Performances</h2>
            <?php rdrit_tr('rdrit_tweak_jetpack_css',
                'Separer les CSS Jetpack',
                'Desactive <code>jetpack_implode_frontend_css</code> — charge chaque CSS Jetpack separement.',
                $nonce); ?>
            <?php rdrit_tr('rdrit_tweak_block_css',
                'CSS Blocs Gutenberg separes',
                'Active <code>should_load_separate_core_block_assets</code> — charge uniquement les CSS des blocs utilises.',
                $nonce); ?>
            <?php rdrit_tr('rdrit_tweak_jquery_migrate',
                'Supprimer jQuery Migrate (frontend)',
                'Retire jQuery Migrate en frontend uniquement. N\'affecte pas l\'admin.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>✏️ Éditeur</h2>
            <?php rdrit_tr('rdrit_tweak_page_parents',
                'Pages parentes : afficher draft/private/future',
                'Dans le dropdown de page parente, inclut les pages en brouillon, privees, a venir et en attente.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>👁️ Widget Visibility</h2>
            <?php rdrit_tr('rdrit_wv_enabled',
                'Activer Widget Visibility',
                'Active le filtrage des block widgets selon les conditions configurées. Désactiver en cas de problème avec l\'éditeur de widgets.',
                $nonce); ?>
            <?php rdrit_tr('rdrit_wv_debug',
                'Mode debug (admins uniquement)',
                'Ajoute des commentaires HTML dans le source de page pour diagnostiquer les conditions. Visible uniquement pour les administrateurs — désactiver en production.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>🐞 Debug front (console)</h2>
            <?php rdrit_tr('rdrit_front_debug',
                'Activer les logs front dans la console',
                'Active les messages [RDRIT Related] et [RDRIT Shortcode] dans la console navigateur pour diagnostiquer les chargements AJAX qui reviennent vides. Désactiver en production.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>📅 Articles planifiés manqués</h2>
            <?php rdrit_tr('rdrit_tweak_missed_posts',
                'Publier les articles planifiés manqués',
                'Cron WP toutes les 5 minutes. Indispensable avec un cache agressif (LiteSpeed + Cloudflare APO) car wp_head ne se déclenche pas sur les pages cachées.',
                $nonce); ?>
            <?php
            $next = wp_next_scheduled('rdrit_publish_missed_posts');
            if ($next && (bool) get_option('rdrit_tweak_missed_posts', 0)): ?>
            <p style="font-size:.78em;color:#16a34a;margin:4px 0 0">
                Cron actif — prochain déclenchement : <?= esc_html(get_date_from_gmt(date('Y-m-d H:i:s', $next), 'd/m/Y H:i:s')) ?>
            </p>
            <?php elseif((bool) get_option('rdrit_tweak_missed_posts', 0)): ?>
            <p style="font-size:.78em;color:#d97706;margin:4px 0 0">
                Option active mais cron pas encore planifié — rechargez cette page.
            </p>
            <?php endif; ?>
            <p style="font-size:.78em;color:#6b7280;margin:4px 0 0">
                Recommandé : désactiver le cron interne WP (<code>DISABLE_WP_CRON=true</code> dans wp-config.php)
                et ajouter un vrai cron serveur :<br>
                <code>*/5 * * * * curl -s <?= esc_url(site_url('/wp-cron.php?doing_wp_cron')) ?></code>
            </p>
        </div>

        <div class="rdrit-section">
            <h2>⚡ jQuery front-end</h2>
            <p style="font-size:.82em;color:#6b7280;margin:0 0 8px">
                Le plugin RDR-IT Tools est jQuery-free sur le front. Désactiver jQuery seulement si aucun autre plugin/thème ne l'utilise.
            </p>
            <label style="display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px">Désactiver jQuery pour :</label>
            <select class="rdrit-saveable-select" data-key="rdrit_tweak_jquery_front" data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php
                $jq_mode = get_option('rdrit_tweak_jquery_front','off');
                $jq_opts = array('off'=>'Ne pas désactiver','admin'=>'Administrateurs seulement','logged_in'=>'Tous les utilisateurs connectés','all_except_admin'=>'Tout le monde sauf les administrateurs','all'=>'Tout le monde (attention !)');                foreach($jq_opts as $v=>$l): ?>
                <option value="<?= esc_attr($v) ?>" <?php selected($jq_mode,$v); ?>><?= esc_html($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="rdrit-section">
            <h2>⚡ LiteSpeed Cache — Widget Visibility</h2>
            <?php rdrit_tr('rdrit_wv_litespeed',
                'Activer la prise en charge LiteSpeed',
                'Si activé, les pages avec des conditions utilisateur (connecté/rôle/capacité) sur les block widgets seront exclues du cache LiteSpeed pour les utilisateurs connectés. Désactiver si vous n\'utilisez pas LiteSpeed Cache.',
                $nonce); ?>
        </div>
    </div>


    <!-- ══ LIGHTBOX ══ -->
    <div class="rdrit-tab-content" id="tab-lightbox">

        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div>
                    <h2>🖼️ GLightbox</h2>
                    <p class="rdrit-section-desc">
                        Lightbox moderne — vanilla JS, zéro jQuery, ~14 Ko.
                        Fichiers servis par votre serveur (pas de CDN externe).
                    </p>
                </div>
                <label class="rdrit-switch rdrit-switch--lg">
                    <input type="checkbox" class="rdrit-saveable-check"
                           data-key="rdrit_lightbox_enabled" data-nonce="<?php echo esc_attr($nonce); ?>"
                           <?php checked($g('rdrit_lightbox_enabled',0),1); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>⚙️ Configuration</h2>
            <div class="rdrit-opt-group" style="max-width:520px;margin-bottom:14px">
                <label>Sélecteur CSS de la zone article</label>
                <input type="text" class="rdrit-text-input rdrit-saveable-text"
                       data-key="rdrit_lightbox_scope" data-nonce="<?php echo esc_attr($nonce); ?>"
                       placeholder=".inside-article, .entry-content, .post-content"
                       value="<?php echo esc_attr($g('rdrit_lightbox_scope','.inside-article, .entry-content, .post-content')); ?>">
                <p style="font-size:.75em;color:#9ca3af;margin:3px 0 0">
                    Zones dans lesquelles les liens images seront transformés en lightbox.
                    Plusieurs sélecteurs séparés par des virgules.
                </p>
            </div>
            <div class="rdrit-opt-group" style="max-width:220px;margin-bottom:14px">
                <label>Nom de la galerie</label>
                <input type="text" class="rdrit-text-input rdrit-saveable-text"
                       data-key="rdrit_lightbox_gallery" data-nonce="<?php echo esc_attr($nonce); ?>"
                       placeholder="article"
                       value="<?php echo esc_attr($g('rdrit_lightbox_gallery','article')); ?>">
                <p style="font-size:.75em;color:#9ca3af;margin:3px 0 0">
                    Permet la navigation entre les images d'un même article.
                </p>
            </div>
            <?php rdrit_tr('rdrit_lightbox_loop',    'Navigation en boucle', 'Revient à la première image après la dernière.', $nonce); ?>
            <?php rdrit_tr('rdrit_lightbox_zoomable', 'Zoom au clic', 'Permet de zoomer sur l\'image dans la lightbox.', $nonce); ?>
        </div>

        <div class="rdrit-section">
            <h2>🔗 Intégration AdBlock</h2>
            <div class="rdrit-section--info">
                <p>
                    Quand GLightbox est <strong>activé</strong>, le sélecteur CSS du module AdBlock
                    est automatiquement mis à jour vers <code>.wp-block-image > .glightbox</code>.<br>
                    Pensez à désactiver votre ancien plugin Fancybox pour éviter les conflits.
                </p>
            </div>
            <p style="font-size:.82em;color:#6b7280;margin-top:10px">
                Sélecteur actif pour l'AdBlock :
                <code><?php echo esc_html( rdrit_lightbox_img_selector() ); ?></code>
            </p>
        </div>

        <div class="rdrit-section">
            <h2>📦 Fichiers embarqués</h2>
            <table class="rdrit-params-table widefat" style="max-width:500px">
                <thead><tr><th>Fichier</th><th>Version</th><th>Taille</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>glightbox.min.js</code></td>
                        <td>3.3.1</td>
                        <td><?php
                            $f = RDRIT_TOOLS_DIR . 'assets/lib/glightbox/glightbox.min.js';
                            echo file_exists($f) ? round(filesize($f)/1024,1).' Ko' : '<em style="color:#dc2626">manquant</em>';
                        ?></td>
                    </tr>
                    <tr>
                        <td><code>glightbox.min.css</code></td>
                        <td>3.3.1</td>
                        <td><?php
                            $f = RDRIT_TOOLS_DIR . 'assets/lib/glightbox/glightbox.min.css';
                            echo file_exists($f) ? round(filesize($f)/1024,1).' Ko' : '<em style="color:#dc2626">manquant</em>';
                        ?></td>
                    </tr>
                    <tr>
                        <td><code>rdrit-glightbox-init.js</code></td>
                        <td><?php echo esc_html(RDRIT_TOOLS_VERSION); ?></td>
                        <td><?php
                            $f = RDRIT_TOOLS_DIR . 'assets/js/rdrit-glightbox-init.js';
                            echo file_exists($f) ? round(filesize($f)/1024,1).' Ko' : '<em style="color:#dc2626">manquant</em>';
                        ?></td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size:.82em;color:#6b7280;margin-top:8px">
                Handle JS à exclure dans LiteSpeed : <code>rdrit-glightbox</code>, <code>rdrit-glightbox-init</code>
            </p>
        </div>

    </div>


    <!-- ══ QUOTES ══ -->
    <div class="rdrit-tab-content" id="tab-quotes">

        <div class="rdrit-section">
            <div class="rdrit-section-head">
                <div>
                    <h2>💬 Citations colorées</h2>
                    <p class="rdrit-section-desc">
                        Styles de couleur pour les blocs citation Gutenberg.<br>
                        Compatible classes CSS existantes (<code>.quote-blue</code>…) et nouveau système (<code>data-quote="blue"</code>).<br>
                        Le sélecteur apparaît dans la <strong>sidebar Gutenberg</strong> lors de l'édition d'un bloc citation.
                    </p>
                </div>
                <label class="rdrit-switch rdrit-switch--lg">
                    <input type="checkbox" class="rdrit-saveable-check"
                           data-key="rdrit_quotes_enabled" data-nonce="<?php echo esc_attr($nonce); ?>"
                           <?php checked($g('rdrit_quotes_enabled',1),1); ?>>
                    <span class="rdrit-slider"></span>
                </label>
            </div>
        </div>

        <div class="rdrit-section">
            <?php rdrit_tr('rdrit_quotes_force_important',
                'Forcer avec !important',
                'Activer si le thème utilise des <code>!important</code> sur les blockquotes. A desactiver apres nettoyage du theme.',
                $nonce); ?>
        </div>

        <div class="rdrit-section">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0">🎨 Couleurs configurées</h2>
                <button id="rdrit-quotes-reset" class="button" style="font-size:.8em">
                    ↩️ Réinitialiser aux défauts Bootstrap
                </button>
            </div>
            <p class="rdrit-section-desc">Basées sur les 12 couleurs Bootstrap 5.3. Modifiez les valeurs hex selon votre charte.</p>

            <div id="rdrit-quotes-grid" class="rdrit-quotes-grid">
                <?php
                $colors = rdrit_quotes_get_colors();
                foreach ( $colors as $idx => $c ) :
                    $slug  = esc_attr($c['slug']);
                    $label = esc_attr($c['label']);
                    $color = esc_attr($c['color']);
                    $light = esc_attr($c['light']);
                    $dark  = esc_attr($c['dark']);
                ?>
                <div class="rdrit-quote-card" data-idx="<?php echo $idx; ?>">
                    <div class="rdrit-quote-preview" style="border-left:5px solid <?php echo $color; ?>;background:<?php echo $light; ?>">
                        <span class="rdrit-quote-preview-label"><?php echo esc_html($c['label']); ?></span>
                        <code style="font-size:.7em;color:<?php echo $color; ?>"><?php echo $color; ?></code>
                    </div>
                    <div class="rdrit-quote-fields">
                        <div class="rdrit-quote-field">
                            <label>Slug <code>(data-quote="<?php echo $slug; ?>")</code></label>
                            <input type="text" class="rdrit-text-input rdrit-quote-slug"
                                   value="<?php echo $slug; ?>" readonly
                                   style="background:#f3f4f6;color:#6b7280;font-family:monospace;font-size:.82em">
                        </div>
                        <div class="rdrit-quote-field">
                            <label>Nom</label>
                            <input type="text" class="rdrit-text-input rdrit-quote-label-input"
                                   value="<?php echo $label; ?>">
                        </div>
                        <div class="rdrit-quote-field rdrit-quote-colors-row">
                            <div>
                                <label>Bordure / Texte</label>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <input type="color" class="rdrit-quote-color-main" value="<?php echo $color; ?>">
                                    <span class="rdrit-quote-hex-main" style="font-size:.78em;font-family:monospace;color:#6b7280"><?php echo $color; ?></span>
                                </div>
                            </div>
                            <div>
                                <label>Fond clair</label>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <input type="color" class="rdrit-quote-color-light" value="<?php echo $light; ?>">
                                    <span class="rdrit-quote-hex-light" style="font-size:.78em;font-family:monospace;color:#6b7280"><?php echo $light; ?></span>
                                </div>
                            </div>
                            <div>
                                <label>Fond sombre</label>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <input type="color" class="rdrit-quote-color-dark" value="<?php echo $dark; ?>">
                                    <span class="rdrit-quote-hex-dark" style="font-size:.78em;font-family:monospace;color:#6b7280"><?php echo $dark; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
                <button id="rdrit-quotes-save" class="button button-primary">
                    💾 Sauvegarder toutes les couleurs
                </button>
                <span id="rdrit-quotes-save-msg" style="font-size:.85em;display:none"></span>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>📋 Utilisation</h2>
            <div class="rdrit-section--info">
                <p><strong>Nouveau :</strong> dans l'éditeur Gutenberg, sélectionnez un bloc Citation → panneau latéral → <em>🎨 Couleur RDR-IT</em> → choisissez une couleur. Le <code>data-quote="slug"</code> est injecté automatiquement.</p>
                <p style="margin-top:8px"><strong>Rétrocompat :</strong> les classes CSS existantes <code>.quote-blue</code>, <code>.quote-memo</code>, etc. continuent de fonctionner sans modification.</p>
            </div>
            <table class="rdrit-params-table widefat" style="max-width:500px;margin-top:12px">
                <thead><tr><th>Slug</th><th>Classe CSS (ancien)</th><th>data-quote (nouveau)</th><th>Aperçu</th></tr></thead>
                <tbody>
                <?php foreach ( rdrit_quotes_get_colors() as $c ) : ?>
                <tr>
                    <td><code><?php echo esc_html($c['slug']); ?></code></td>
                    <td><code>.quote-<?php echo esc_html($c['slug']); ?></code></td>
                    <td><code>data-quote="<?php echo esc_html($c['slug']); ?>"</code></td>
                    <td><span style="display:inline-block;width:18px;height:18px;border-radius:3px;background:<?php echo esc_attr($c['color']); ?>;vertical-align:middle"></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>


    <!-- ══ MENU SYNC ══ -->
    <div class="rdrit-tab-content" id="tab-menusync">

        <?php if ( ! function_exists('pll_languages_list') ) : ?>
        <div class="rdrit-section rdrit-section--info" style="border-color:#fca5a5;background:#fef2f2">
            <p>⚠️ <strong>Polylang non détecté.</strong> Ce module nécessite le plugin Polylang (gratuit ou Pro).</p>
        </div>
        <?php else : ?>

        <div class="rdrit-section">
            <h2>🌐 Synchronisation des menus</h2>
            <p class="rdrit-section-desc">
                Synchronise la structure d'un menu source vers une langue cible.<br>
                Un <strong>diff interactif</strong> est affiché avant d'appliquer les changements.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:820px;margin-top:14px">
                <div class="rdrit-opt-group">
                    <label>🇫🇷 Menu source</label>
                    <select id="rdrit-ms-source" class="rdrit-text-input">
                        <option value="">— Choisir un menu —</option>
                        <?php foreach ( wp_get_nav_menus() as $menu ) : ?>
                        <option value="<?php echo esc_attr($menu->term_id); ?>">
                            <?php echo esc_html($menu->name); ?>
                            (<?php echo esc_html($menu->count); ?> items)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rdrit-opt-group">
                    <label>🎯 Menu cible <span style="color:#dc2626;font-weight:700">*</span></label>
                    <select id="rdrit-ms-target" class="rdrit-text-input">
                        <option value="">— Choisir le menu cible —</option>
                        <?php foreach ( wp_get_nav_menus() as $menu ) : ?>
                        <option value="<?php echo esc_attr($menu->term_id); ?>">
                            <?php echo esc_html($menu->name); ?>
                            (<?php echo esc_html($menu->count); ?> items)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:.73em;color:#dc2626;margin:3px 0 0">Obligatoire — choisissez explicitement le menu à modifier</p>
                </div>
                <div class="rdrit-opt-group">
                    <label>🌍 Langue cible</label>
                    <select id="rdrit-ms-lang" class="rdrit-text-input">
                        <option value="">— Choisir une langue —</option>
                        <?php foreach ( pll_languages_list(array('fields'=>'')) as $lang ) : ?>
                        <option value="<?php echo esc_attr($lang->slug); ?>">
                            <?php echo esc_html($lang->name); ?> (<?php echo esc_html($lang->slug); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:.73em;color:#6b7280;margin:3px 0 0">Pour résoudre les traductions des articles/pages</p>
                </div>
            </div>

            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <button id="rdrit-ms-analyze" class="button button-primary"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                    🔍 Analyser les différences
                </button>
                <span id="rdrit-ms-analyze-status" style="font-size:.85em;color:#6b7280"></span>
            </div>
        </div>

        <!-- Zone de résultat du diff -->
        <div id="rdrit-ms-diff-wrap" style="display:none">
            <div class="rdrit-section">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                    <h2 style="margin:0">📋 Différences détectées</h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button id="rdrit-ms-check-all"   class="button" style="font-size:.8em">✅ Tout cocher</button>
                        <button id="rdrit-ms-uncheck-all" class="button" style="font-size:.8em">☐ Tout décocher</button>
                        <label style="font-size:.8em;display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" id="rdrit-ms-show-identical"> Afficher les identiques
                        </label>
                    </div>
                </div>

                <div id="rdrit-ms-legend" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;font-size:.8em">
                    <span><span class="rdrit-ms-badge rdrit-ms-new">✅ Nouveau</span></span>
                    <span><span class="rdrit-ms-badge rdrit-ms-modified">⚠️ Modifié</span></span>
                    <span><span class="rdrit-ms-badge rdrit-ms-deleted">❌ Supprimé</span></span>
                    <span><span class="rdrit-ms-badge rdrit-ms-external">🔗 Externe</span></span>
                    <span><span class="rdrit-ms-badge rdrit-ms-identical">✓ Identique</span></span>
                    <span><span class="rdrit-ms-badge rdrit-ms-notranslation">— Sans traduction</span></span>
                </div>

                <table id="rdrit-ms-diff-table" class="widefat rdrit-ms-table">
                    <thead>
                        <tr>
                            <th style="width:32px"><input type="checkbox" id="rdrit-ms-check-header"></th>
                            <th>Statut</th>
                            <th>Titre source (FR)</th>
                            <th>Titre cible actuel</th>
                            <th>Titre / URL après synchro</th>
                            <th>Niveau</th>
                        </tr>
                    </thead>
                    <tbody id="rdrit-ms-diff-body">
                        <!-- Rempli par JS -->
                    </tbody>
                </table>

                <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <button id="rdrit-ms-sync" class="button button-primary"
                            data-nonce="<?php echo esc_attr($nonce); ?>">
                        ⚡ Synchroniser
                    </button>
                    <button id="rdrit-ms-sync-full" class="button"
                            data-nonce="<?php echo esc_attr($nonce); ?>"
                            style="border-color:#f97316;color:#f97316">
                        ♻️ Sync complète (vider + recréer)
                    </button>
                    <span id="rdrit-ms-sync-status" style="font-size:.85em"></span>
                </div>
            </div>
        </div>

        <div class="rdrit-section">
            <h2>🔗 Ancres de menu (CPT)</h2>
            <p class="rdrit-section-desc">
                Alternative propre aux liens <code>#</code> manuels.<br>
                Créez des <strong>ancres de menu</strong> ici ou dans <strong>Apparence → Ancres menu</strong>.
                Polylang les gère nativement → synchronisation fiable.
            </p>

            <div style="margin-bottom:14px">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=rdrit_menu_anchor')); ?>"
                   class="button button-primary">
                    + Ajouter une ancre
                </a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=rdrit_menu_anchor')); ?>"
                   class="button" style="margin-left:6px">
                    Gérer toutes les ancres
                </a>
                <p style="font-size:.78em;color:#6b7280;margin:6px 0 0">
                    Créez l'ancre, puis traduisez-la dans chaque langue via les drapeaux Polylang dans l'éditeur.
                </p>
            </div>

            <div id="rdrit-anchor-list">
                <?php
                $pll_langs    = function_exists('pll_languages_list') ? pll_languages_list(array('fields'=>'')) : array();
                $default_lang = function_exists('pll_default_language') ? pll_default_language() : 'fr';

                // Récupérer uniquement les ancres de la langue principale pour éviter les doublons
                $query_args = array(
                    'post_type'      => 'rdrit_menu_anchor',
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                );
                if ( function_exists('pll_default_language') ) {
                    $query_args['lang'] = pll_default_language();
                }
                $anchors = get_posts( $query_args );

                if ( $anchors ) :
                ?>
                <table class="widefat" style="font-size:.85rem">
                    <thead>
                        <tr>
                            <?php foreach($pll_langs as $l): ?>
                            <th style="min-width:120px">
                                <?php if(!empty($l->flag_url)): ?>
                                <img src="<?php echo esc_url($l->flag_url); ?>" width="18" height="13"
                                     alt="<?php echo esc_attr($l->name); ?>"
                                     style="border-radius:2px;vertical-align:middle;margin-right:4px">
                                <?php endif; ?>
                                <?php echo esc_html($l->name); ?>
                            </th>
                            <?php endforeach; ?>
                            <th>URL menu</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($anchors as $anchor): ?>
                    <tr>
                        <?php foreach($pll_langs as $l):
                            if($l->slug === $default_lang) {
                                $trans_post = $anchor;
                                $trans_id   = $anchor->ID;
                            } else {
                                $trans_id   = function_exists('pll_get_post') ? (int)pll_get_post($anchor->ID, $l->slug) : 0;
                                $trans_post = $trans_id ? get_post($trans_id) : null;
                            }
                        ?>
                        <td>
                            <?php if($trans_post): ?>
                                <strong><?php echo esc_html($trans_post->post_title); ?></strong>
                                <a href="<?php echo esc_url(get_edit_post_link($trans_post->ID)); ?>"
                                   style="margin-left:4px;font-size:.75em;color:#2271b1">✏️</a>
                            <?php else: ?>
                                <span style="color:#ef4444;font-size:.8em">— non traduit</span>
                                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=rdrit_menu_anchor')); ?>"
                                   style="margin-left:4px;font-size:.75em;color:#2271b1">+ Ajouter</a>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td><code>#<?php echo esc_html($anchor->post_name); ?></code></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($anchor->ID)); ?>"
                               style="font-size:.78em">✏️ Modifier</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="color:#9ca3af;font-style:italic;font-size:.85em">
                    Aucune ancre créée. Cliquez sur <strong>+ Ajouter une ancre</strong> ci-dessus.
                </p>
                <?php endif; ?>
            </div>

            <div class="rdrit-section--info" style="margin-top:12px">
                <p style="margin:0;font-size:.82em">
                    💡 Une fois créées, ajoutez les ancres dans vos menus via
                    <strong>Apparence → Menus → Ancres menu</strong>.
                    La synchronisation avec Menu Sync fonctionnera parfaitement car
                    Polylang lie les traductions via <code>pll_get_post()</code>.
                </p>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- ══ CAPS DOC ══ -->
    <div class="rdrit-tab-content" id="tab-caps-doc">
        <div class="rdrit-section">
            <h2>📖 Capacités WordPress disponibles</h2>
            <p class="rdrit-section-desc">
                Toutes les capacités enregistrées sur ce site avec leur source (Core WP ou plugin).
                Les capacités <strong>orphelines</strong> appartiennent à des plugins désactivés/supprimés.
            </p>

            <?php
            // Capacités natives WordPress Core
            $core_caps = array(
                'activate_plugins','create_users','delete_others_pages','delete_others_posts',
                'delete_pages','delete_plugins','delete_posts','delete_private_pages',
                'delete_private_posts','delete_published_pages','delete_published_posts',
                'delete_themes','delete_users','edit_dashboard','edit_files','edit_others_pages',
                'edit_others_posts','edit_pages','edit_plugins','edit_posts','edit_private_pages',
                'edit_private_posts','edit_published_pages','edit_published_posts','edit_theme_options',
                'edit_themes','edit_users','export','import','install_plugins','install_themes',
                'list_users','manage_categories','manage_links','manage_options','moderate_comments',
                'promote_users','publish_pages','publish_posts','read','read_private_pages',
                'read_private_posts','remove_users','switch_themes','unfiltered_html','unfiltered_upload',
                'update_core','update_plugins','update_themes','upload_files','customize',
                'delete_site','manage_network','manage_network_options','manage_network_plugins',
                'manage_network_themes','manage_network_users','manage_sites',
                // Gutenberg / blocks
                'edit_block_editor_meta_boxes','create_block_patterns',
            );

            // Récupérer toutes les capacités avec leurs rôles
            $all_caps_doc = array();
            foreach ( wp_roles()->roles as $role_slug => $role_data ) {
                $role_label = translate_user_role( $role_data['name'] );
                foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
                    if ( ! isset( $all_caps_doc[ $cap ] ) ) {
                        $all_caps_doc[ $cap ] = array( 'roles' => array(), 'source' => '' );
                    }
                    $all_caps_doc[ $cap ]['roles'][] = $role_label;
                }
            }
            ksort( $all_caps_doc );

            // Caps persistées en base (supprimables)
            $option_key_db = wp_roles()->role_key;
            $db_roles      = get_option( $option_key_db, array() );
            $db_caps       = array();
            foreach ( $db_roles as $rd ) {
                foreach ( array_keys( $rd['capabilities'] ) as $c ) {
                    $db_caps[ $c ] = true;
                }
            }

            // Détection simple : Core vs Non-Core
            $count_core   = 0;
            $count_plugin = 0;

            foreach ( $all_caps_doc as $cap => &$data ) {
                if ( in_array( $cap, $core_caps, true ) ) {
                    $data['source']      = 'core';
                    $data['source_label'] = 'WordPress Core';
                    $data['in_db']       = true;
                    $count_core++;
                } else {
                    $data['source']      = 'plugin';
                    $data['source_label'] = 'Non-Core';
                    $data['in_db']       = isset( $db_caps[ $cap ] );
                    $count_plugin++;
                }
            }
            unset( $data );
            ?>

            <!-- Filtres + stats -->
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
                <input type="text" id="rdrit-caps-doc-filter" class="rdrit-text-input"
                       placeholder="Filtrer les capacités…" style="max-width:260px">
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="button rdrit-caps-source-filter active" data-source="all"
                            style="font-size:.78em">
                        Toutes (<?php echo count($all_caps_doc); ?>)
                    </button>
                    <button class="button rdrit-caps-source-filter" data-source="core"
                            style="font-size:.78em;background:#f0fdf4;border-color:#86efac;color:#166534">
                        ✅ Core (<?php echo $count_core; ?>)
                    </button>
                    <button class="button rdrit-caps-source-filter" data-source="plugin"
                            style="font-size:.78em;background:#fef9c3;border-color:#fde68a;color:#854d0e">
                        🔌 Non-Core (<?php echo $count_plugin; ?>) — supprimables
                    </button>
                </div>
            </div>



            <table class="widefat" id="rdrit-caps-doc-table" style="font-size:.82rem">
                <thead>
                    <tr>
                        <th style="width:35%">Capacité</th>
                        <th style="width:15%">Source</th>
                        <th>Rôles</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_caps_doc as $cap => $data ) :
                        $source = $data['source'];
                        $badge_style = $source === 'core'
                            ? 'background:#f0fdf4;color:#166534;border:1px solid #86efac'
                            : 'background:#fef9c3;color:#854d0e;border:1px solid #fde68a';
                        $badge_text = $source === 'core' ? '✅ Core' : '🔌 Non-Core';
                    ?>
                    <tr class="rdrit-caps-doc-row" data-cap="<?php echo esc_attr($cap); ?>"
                        data-source="<?php echo esc_attr($source); ?>">
                        <td><code style="font-size:.85em"><?php echo esc_html($cap); ?></code></td>
                        <td>
                            <span style="display:inline-flex;padding:2px 8px;border-radius:20px;font-size:.73em;font-weight:700;white-space:nowrap;<?php echo $badge_style; ?>">
                                <?php echo $badge_text; ?>
                            </span>
                        </td>
                        <td style="color:#6b7280;font-size:.78em">
                            <?php echo esc_html(implode(', ', $data['roles'])); ?>
                        </td>
                        <td style="display:flex;gap:4px;flex-wrap:wrap">
                            <button class="button rdrit-caps-doc-copy"
                                    data-cap="<?php echo esc_attr($cap); ?>"
                                    style="font-size:.72em;padding:2px 6px">📋</button>
                            <?php if ( $source !== 'core' && $data['in_db'] ) : ?>
                            <button class="button rdrit-caps-doc-delete"
                                    data-cap="<?php echo esc_attr($cap); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>"
                                    style="font-size:.72em;padding:2px 6px;background:#fee2e2;border-color:#fca5a5;color:#dc2626"
                                    title="Supprimer cette capacité">🗑️</button>
                            <?php elseif ( $source !== 'core' && ! $data['in_db'] ) : ?>
                            <span style="font-size:.72em;color:#9ca3af" title="Ajoutée au runtime uniquement — non supprimable">🔒 runtime</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="font-size:.78em;color:#9ca3af;margin-top:8px">
                <?php echo count($all_caps_doc); ?> capacités —
                <?php echo $count_core; ?> Core,
                <?php echo $count_plugin; ?> Non-Core (supprimables).
            </p>
        </div>

        <!-- ══ DIAGNOSTIC ══ -->
        <div class="rdrit-section">
            <h2>🔍 Diagnostic utilisateur / capacité</h2>
            <p class="rdrit-section-desc">
                Sélectionnez un utilisateur ou une capacité pour voir les associations.
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:4px">

                <!-- Colonne gauche : par utilisateur -->
                <div>
                    <h3 style="font-size:.9em;margin:0 0 8px;color:#374151">👤 Par utilisateur</h3>
                    <select id="rdrit-diag-user" class="rdrit-text-input" style="width:100%;margin-bottom:10px">
                        <option value="">— Choisir un utilisateur —</option>
                        <?php
                        $users = get_users( array( 'number' => 200, 'orderby' => 'display_name' ) );
                        foreach ( $users as $u ) :
                            $u_roles = implode( ', ', array_map( 'translate_user_role',
                                array_map( function($r){ $ro=get_role($r); return $ro?$ro->name:$r; }, $u->roles )
                            ) );
                        ?>
                        <option value="<?php echo esc_attr( $u->ID ); ?>">
                            <?php echo esc_html( $u->display_name ); ?>
                            (<?php echo esc_html( $u_roles ); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="rdrit-diag-user-result" style="display:none">
                        <div style="margin-bottom:8px;font-size:.82em">
                            <strong id="rdrit-diag-user-name"></strong>
                            — Rôles : <span id="rdrit-diag-user-roles" style="color:#2271b1"></span>
                        </div>
                        <div style="margin-bottom:6px">
                            <input type="text" id="rdrit-diag-user-cap-filter"
                                   class="rdrit-text-input" placeholder="Filtrer les capacités…"
                                   style="width:100%;font-size:.8em">
                        </div>
                        <div id="rdrit-diag-user-caps"
                             style="max-height:280px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:6px;font-size:.78em;font-family:monospace;line-height:1.8;background:#f9fafb">
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : par capacité -->
                <div>
                    <h3 style="font-size:.9em;margin:0 0 8px;color:#374151">🔑 Par capacité</h3>
                    <select id="rdrit-diag-cap" class="rdrit-text-input" style="width:100%;margin-bottom:10px">
                        <option value="">— Choisir une capacité —</option>
                        <?php foreach ( array_keys( $all_caps_doc ) as $cap ) : ?>
                        <option value="<?php echo esc_attr($cap); ?>"><?php echo esc_html($cap); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="rdrit-diag-cap-result" style="display:none">
                        <div style="margin-bottom:8px;font-size:.82em">
                            Capacité : <code id="rdrit-diag-cap-name" style="font-size:.9em"></code>
                        </div>
                        <div style="font-size:.82em;font-weight:700;margin-bottom:4px;color:#374151">
                            Rôles ayant cette capacité :
                        </div>
                        <div id="rdrit-diag-cap-roles"
                             style="margin-bottom:10px;font-size:.8em;color:#6b7280"></div>
                        <div style="font-size:.82em;font-weight:700;margin-bottom:4px;color:#374151">
                            Utilisateurs ayant cette capacité :
                        </div>
                        <div id="rdrit-diag-cap-users"
                             style="max-height:200px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:6px;font-size:.78em;line-height:1.8;background:#f9fafb">
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Données JS pour le diagnostic -->
        <?php
        // Préparer les données utilisateurs
        $diag_users = array();
        foreach ( $users as $u ) {
            $all_user_caps = array_keys( array_filter( $u->allcaps ) );
            sort( $all_user_caps );
            $diag_users[ $u->ID ] = array(
                'name'  => $u->display_name,
                'roles' => array_map( function($r){ $ro=get_role($r); return $ro?translate_user_role($ro->name):$r; }, $u->roles ),
                'caps'  => $all_user_caps,
            );
        }
        ?>
        <script>
        var rdritDiagUsers = <?php echo wp_json_encode( $diag_users ); ?>;
        var rdritDiagCaps  = <?php echo wp_json_encode( $all_caps_doc ); ?>;
        </script>


    </div>

    <!-- ══ LOGIN SECURITY ══ -->
    <div class="rdrit-tab-content" id="tab-loginsec">
        <?php
        if ( function_exists('rdrit_login_admin_tab') ) {
            rdrit_login_admin_tab( $nonce );
        } else {
            echo '<p>Module de sécurité login non chargé.</p>';
        }
        ?>
    </div>

    <!-- ══ HTML BLOCKS ══ -->
    <div class="rdrit-tab-content" id="tab-htmlblocks">
        <div class="rdrit-section">
            <h2>🧱 Blocs HTML personnalisés</h2>
            <p class="rdrit-section-desc">
                Créez des blocs HTML réutilisables (widgets AdSense, iframes, code tiers…)
                et appelez-les avec <code>[rdrit_block id="slug"]</code>.
                Les balises <code>&lt;script&gt;</code> sont gérées automatiquement.
            </p>
            <div style="display:flex;gap:8px;margin-bottom:14px">
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=rdrit_html_block')); ?>"
                   class="button button-primary">+ Nouveau bloc HTML</a>
                <button id="rdrit-blocks-refresh" class="button"
                        data-nonce="<?php echo esc_attr($nonce); ?>">↺ Actualiser</button>
            </div>
        </div>

        <div class="rdrit-section" id="rdrit-blocks-list-wrap">
            <div id="rdrit-blocks-loading" style="color:#9ca3af;font-style:italic;font-size:.85em">
                ⏳ Chargement…
            </div>
            <table class="widefat" id="rdrit-blocks-table" style="font-size:.85rem;display:none">
                <thead>
                    <tr>
                        <th>Titre</th>
                        <th>Shortcode</th>
                        <th style="width:80px">Type</th>
                        <?php if(function_exists('pll_languages_list')): ?>
                        <th>Langues</th>
                        <?php endif; ?>
                        <th style="width:80px">Statut</th>
                        <th style="width:80px">Actions</th>
                    </tr>
                </thead>
                <tbody id="rdrit-blocks-tbody"></tbody>
            </table>
            <p id="rdrit-blocks-empty"
               style="display:none;color:#9ca3af;font-style:italic;font-size:.85em">
                Aucun bloc créé. Cliquez sur <strong>+ Nouveau bloc HTML</strong>.
            </p>
        </div>
    </div>

    <?php
    rdrit_admin_js( $nonce, $disabled_cpts_arr, $all_zone_items, $zone_labels );
}

/* ── Controles separateur pour un item de la liste ── */
function rdrit_sep_controls( string $slug, string $nonce ): string {
    $is_tax    = ( strpos( $slug, '_' ) !== 0 );
    $sep_key   = $is_tax ? 'rdrit_tax_' . $slug . '_sep_type' : 'rdrit_sep_' . ltrim($slug,'_') . '_type';
    $px_key    = $is_tax ? 'rdrit_tax_' . $slug . '_sep_px'   : 'rdrit_sep_' . ltrim($slug,'_') . '_px';
    $sep_val   = get_option( $sep_key, 'space' );
    $px_val    = (int) get_option( $px_key, 8 );

    ob_start();
    ?>
    <span class="rdrit-sep-controls">
        <select class="rdrit-saveable-select rdrit-sep-type-select"
                data-key="<?php echo esc_attr($sep_key); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>"
                title="Séparateur avant cet élément">
            <?php foreach( RDRIT_SEP_TYPES as $sv => $sl ) : ?>
            <option value="<?php echo esc_attr($sv); ?>" <?php selected($sep_val,$sv); ?>><?php echo esc_html($sl); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" min="0" max="120"
               class="rdrit-saveable-number rdrit-sep-px-input <?php echo $sep_val==='none'?'rdrit-hidden':''; ?>"
               data-key="<?php echo esc_attr($px_key); ?>"
               data-nonce="<?php echo esc_attr($nonce); ?>"
               value="<?php echo esc_attr($px_val); ?>"
               title="Espace en px">
        <span class="rdrit-sep-px-unit <?php echo $sep_val==='none'?'rdrit-hidden':''; ?>">px</span>
    </span>
    <?php
    return ob_get_clean();
}

/* ── Helpers ── */
/* ── Helper : grille de rôles avec toggles ── */
/* ── Sélecteur de capacités avec autocomplete + tags ── */
function rdrit_caps_selector( string $option_key, string $nonce ): void {
    // Récupérer toutes les capacités disponibles sur le site
    $all_caps = array();
    foreach ( wp_roles()->roles as $role_data ) {
        foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
            $all_caps[ $cap ] = true;
        }
    }
    ksort( $all_caps );
    $all_caps = array_keys( $all_caps );

    $raw      = get_option( $option_key, array() );
    // get_option peut retourner un array (déjà décodé) ou une string JSON
    if ( is_array( $raw ) ) {
        $selected = $raw;
    } else {
        $selected = json_decode( $raw, true );
    }
    if ( ! is_array( $selected ) ) $selected = array();
    ?>
    <div class="rdrit-caps-selector"
         data-opt-key="<?php echo esc_attr( $option_key ); ?>"
         data-nonce="<?php echo esc_attr( $nonce ); ?>"
         data-caps="<?php echo esc_attr( wp_json_encode( $all_caps ) ); ?>"
         data-selected="<?php echo esc_attr( wp_json_encode( $selected ) ); ?>">
        <div class="rdrit-caps-input-wrap">
            <div class="rdrit-caps-tags"></div>
            <input type="text" class="rdrit-caps-input"
                   placeholder="Taper une capacité (ex: manage_options)…"
                   autocomplete="off">
        </div>
        <div class="rdrit-caps-dropdown"></div>
        <p style="font-size:.75em;color:#9ca3af;margin:4px 0 0">
            Tapez pour filtrer · Entrée ou clic pour ajouter · × pour retirer ·
            <a href="#tab-caps-doc" class="rdrit-tab-link" data-tab="caps-doc"
               style="color:#2271b1">Voir toutes les capacités disponibles</a>
        </p>
    </div>
    <?php
}

function rdrit_roles_toggles( string $option_key, string $nonce ) {
    $excluded = get_option( $option_key, array() );
    if ( ! is_array( $excluded ) ) $excluded = array();

    $all_roles = wp_roles()->roles;
    ?>
    <div class="rdrit-roles-grid">
    <?php foreach ( $all_roles as $slug => $role ) :
        $is_excluded = in_array( $slug, $excluded, true );
        $label       = translate_user_role( $role['name'] );
    ?>
        <div class="rdrit-role-row">
            <div>
                <strong><?php echo esc_html( $label ); ?></strong>
                <code><?php echo esc_html( $slug ); ?></code>
            </div>
            <label class="rdrit-switch">
                <input type="checkbox"
                       class="rdrit-role-toggle"
                       data-option="<?php echo esc_attr( $option_key ); ?>"
                       data-role="<?php echo esc_attr( $slug ); ?>"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       <?php checked( $is_excluded, true ); ?>>
                <span class="rdrit-slider"></span>
            </label>
        </div>
    <?php endforeach; ?>
    </div>
    <p style="font-size:.76em;color:#9ca3af;margin-top:6px">
        Toggle <strong>activé</strong> (bleu) = fonctionnalité désactivée pour ce rôle.
    </p>
    <?php
}

function rdrit_tr( $key, $label, $desc, $nonce ) {
    $v = (int) get_option( $key, 1 ); ?>
    <div class="rdrit-toggle-row">
        <div class="rdrit-toggle-info">
            <strong><?php echo wp_kses_post($label); ?></strong>
            <?php if($desc): ?><span><?php echo wp_kses_post($desc); ?></span><?php endif; ?>
        </div>
        <label class="rdrit-switch">
            <input type="checkbox" class="rdrit-saveable-check" data-key="<?php echo esc_attr($key); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" <?php checked($v,1); ?>>
            <span class="rdrit-slider"></span>
        </label>
    </div><?php
}
function rdrit_sel( $key, $options, $current, $nonce ) { ?>
    <select class="rdrit-saveable-select" data-key="<?php echo esc_attr($key); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
        <?php foreach($options as $v=>$l): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($current,$v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
    </select><?php
}

/* ══════════════════════════════════════════════════════════════
   CSS ADMIN
   ══════════════════════════════════════════════════════════════ */
function rdrit_admin_css() { ?>
<style>
.rdrit-admin{max-width:1020px}
.rdrit-logo{font-weight:800;font-size:1.25em;letter-spacing:-.5px}
.rdrit-logo span{color:#2271b1}
.rdrit-version{font-size:.6em;font-weight:400;color:#9ca3af;vertical-align:middle}
.rdrit-subtitle{color:#6b7280;margin:-6px 0 20px}
/* Tabs */
.rdrit-tabs{display:flex;gap:3px;border-bottom:2px solid #e0e0e0;flex-wrap:wrap}
.rdrit-tab{padding:7px 16px;border:none;background:none;cursor:pointer;font-size:.86rem;font-weight:600;color:#6b7280;border-bottom:2px solid transparent;margin-bottom:-2px;border-radius:4px 4px 0 0;transition:color .15s}
.rdrit-tab:hover,.rdrit-tab.active{color:#2271b1}
.rdrit-tab.active{border-bottom-color:#2271b1;background:#fff}
.rdrit-tab--highlight{color:#d97706}
.rdrit-tab--highlight.active{color:#d97706;border-bottom-color:#d97706}
.rdrit-tab-content{display:none}
.rdrit-tab-content.active{display:block}
/* Sections */
.rdrit-section{background:#fff;border:1px solid #e0e0e0;border-radius:0 10px 10px 10px;padding:18px 22px;margin-bottom:14px;margin-top:14px}
.rdrit-section h2{margin-top:0;font-size:.95em;font-weight:700}
.rdrit-section-desc{color:#6b7280;font-size:.84em;margin-bottom:12px}
.rdrit-section--info{background:#fefce8;border-color:#fde68a;padding:11px 16px;margin-top:14px}
.rdrit-section--info p{margin:0;font-size:.87em;color:#78350f}
.rdrit-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:8px}
/* Toggle rows */
.rdrit-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f0f0f0;gap:14px}
.rdrit-toggle-row:last-child{border-bottom:none}
.rdrit-toggle-info{flex:1}
.rdrit-toggle-info strong{display:block;font-size:.88em}
.rdrit-toggle-info span{font-size:.78em;color:#6b7280}
/* Switch */
.rdrit-switch{position:relative;display:inline-block;width:42px;height:22px;flex-shrink:0}
.rdrit-switch--lg{width:48px;height:26px}
.rdrit-switch input{opacity:0;width:0;height:0}
.rdrit-slider{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:22px;transition:.2s}
.rdrit-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.rdrit-switch--lg .rdrit-slider::before{width:20px;height:20px}
input:checked+.rdrit-slider{background:#2271b1}
input:checked+.rdrit-slider::before{transform:translateX(20px)}
.rdrit-switch--lg input:checked+.rdrit-slider::before{transform:translateX(22px)}
/* Tax cards */
.rdrit-tax-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;margin-top:10px}
.rdrit-tax-card{border:1.5px solid #e5e7eb;border-radius:10px;overflow:hidden;transition:box-shadow .15s}
.rdrit-tax-card--disabled{opacity:.55}
.rdrit-tax-card-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;gap:10px}
.rdrit-tax-card-title{flex:1;min-width:0}
.rdrit-tax-card-slug{display:block;font-weight:700;font-size:.88em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rdrit-tax-slug{font-size:.72em;background:#e5e7eb;padding:1px 5px;border-radius:3px;color:#374151;display:inline-block;margin-top:2px}
.rdrit-tax-card-body{padding:12px 14px;display:flex;flex-direction:column;gap:9px}
.rdrit-tax-field{display:flex;flex-direction:column;gap:3px}
.rdrit-tax-field>label{font-size:.75em;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
/* Position btns */
.rdrit-pos-btns{display:flex;gap:4px;flex-wrap:wrap}
.rdrit-pos-btn{display:inline-flex;align-items:center;padding:4px 10px;border:1.5px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:.78em;font-weight:600;color:#6b7280;background:#fff;transition:all .15s}
.rdrit-pos-btn.active{background:#eff6ff;border-color:#2271b1;color:#2271b1}
.rdrit-pos-btn input{display:none}
.rdrit-field-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.rdrit-field-row>label{font-size:.86em;min-width:100px}
/* Badge preview */
.rdrit-tax-preview-wrap{display:flex;flex-wrap:wrap;gap:5px}
.rdrit-term-badge{display:inline-flex;align-items:center;text-decoration:none;font-size:.75rem;font-weight:600;line-height:1}
.rdrit-badge-style--solid  {background:var(--rdrit-badge-color,#3b82f6);color:#fff;padding:.28em .7em;border-radius:4px}
.rdrit-badge-style--pill   {background:var(--rdrit-badge-color,#3b82f6);color:#fff;padding:.28em .85em;border-radius:50px}
.rdrit-badge-style--outline{background:transparent;color:var(--rdrit-badge-color,#3b82f6);border:1.5px solid var(--rdrit-badge-color,#3b82f6);padding:.24em .65em;border-radius:4px}
.rdrit-badge-style--flat   {background:color-mix(in srgb,var(--rdrit-badge-color,#3b82f6) 14%,transparent);color:var(--rdrit-badge-color,#3b82f6);padding:.28em .7em;border-radius:4px}
.rdrit-badge-style--mono   {background:#f3f4f6;color:#374151;border:1px solid #d1d5db;padding:.28em .75em;border-radius:6px;font-family:monospace;font-size:.8rem}
/* Inputs */
.rdrit-saveable-select,.rdrit-text-input{padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:.84em;background:#fafafa}
.rdrit-saveable-select{width:100%}
.rdrit-text-input{width:100%}
.rdrit-saveable-select:focus,.rdrit-text-input:focus{border-color:#2271b1;outline:none}
.rdrit-saveable-color{width:36px;height:26px;padding:2px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer}

/* ══ DRAG & DROP ORDRE ══ */
.rdrit-order-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px}
.rdrit-order-panel{border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
.rdrit-order-panel-head{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.rdrit-zone-badge{font-size:.82em;font-weight:700;padding:3px 10px;border-radius:20px}
.rdrit-zone-badge--top{background:#dbeafe;color:#1d4ed8}
.rdrit-zone-badge--bottom{background:#dcfce7;color:#15803d}
.rdrit-order-reset{font-size:.78em;color:#9ca3af;text-decoration:none;background:none;border:none;cursor:pointer;padding:0}
.rdrit-order-reset:hover{color:#ef4444}
.rdrit-sortable{list-style:none;margin:0;padding:6px 8px;min-height:60px}
.rdrit-sort-item{display:flex;align-items:center;gap:8px;padding:7px 10px;margin:4px 0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;cursor:grab;user-select:none;transition:box-shadow .15s,background .15s}
.rdrit-sort-item:hover{background:#eff6ff;border-color:#bfdbfe}
.rdrit-sort-item.rdrit-dragging{opacity:.5;box-shadow:0 4px 16px rgba(0,0,0,.15);cursor:grabbing;background:#eff6ff;border-color:#2271b1}
.rdrit-sort-item.rdrit-drag-over{border-color:#2271b1;border-style:dashed;background:#f0f9ff}
.rdrit-sort-handle{font-size:1.1em;color:#9ca3af;cursor:grab;flex-shrink:0;line-height:1}
.rdrit-sort-label{flex:1;font-size:.85em;font-weight:600;min-width:0}
.rdrit-sort-label code{font-size:.78em;background:#e5e7eb;padding:1px 4px;border-radius:3px;margin-left:4px}
/* Controles separateur inline */
.rdrit-sep-controls{display:flex;align-items:center;gap:4px;flex-shrink:0}
.rdrit-sep-controls .rdrit-saveable-select{width:auto;font-size:.76em;padding:3px 5px;min-width:90px}
.rdrit-sep-controls .rdrit-saveable-number{width:46px;font-size:.76em;padding:3px 5px;border:1px solid #d1d5db;border-radius:4px;text-align:center;background:#fafafa}
.rdrit-sep-px-unit{font-size:.72em;color:#9ca3af}

/* Misc */
.rdrit-sub-option{margin-top:8px;padding-left:14px;border-left:3px solid #e5e7eb}
.rdrit-two-col{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}
.rdrit-kofi-preview{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:50px;color:#fff;font-size:.8em;font-weight:700}
.rdrit-networks-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.rdrit-network-toggle{display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:.86em;font-weight:600}
.rdrit-network-toggle input{width:15px;height:15px;accent-color:#2271b1}
.rdrit-opt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-top:10px}
.rdrit-opt-full{grid-column:1/-1}
.rdrit-opt-group label{display:block;font-size:.82em;font-weight:600;margin-bottom:4px;color:#4b5563}
.rdrit-layout-grid{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.rdrit-layout-card{display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 18px;border:2px solid #e5e7eb;border-radius:8px;cursor:pointer;text-align:center;transition:all .15s;background:#fafafa;min-width:80px}
.rdrit-layout-card:hover{border-color:#93c5fd;background:#eff6ff}
.rdrit-layout-card.active{border-color:#2271b1;background:#eff6ff;color:#2271b1}
.rdrit-layout-icon{font-size:1.3em}
.rdrit-layout-card strong{font-size:.78em}
.rdrit-cpt-grid{display:flex;flex-direction:column;gap:2px}
.rdrit-cpt-row{display:flex;align-items:center;justify-content:space-between;padding:7px 12px;background:#f9fafb;border-radius:6px;gap:10px}
.rdrit-cpt-row:nth-child(odd){background:#f3f4f6}
.rdrit-cpt-row div{display:flex;align-items:center;gap:8px}
.rdrit-cpt-row strong{font-size:.85em}
.rdrit-cpt-row code{font-size:.74em;background:#e5e7eb;padding:1px 5px;border-radius:3px;color:#374151}
/* Preview */
.rdrit-preview{background:#f9f9f9;border:1px dashed #ddd;border-radius:8px;padding:12px 16px}
.rdrit-badge{display:inline-block;padding:.25em .55em;font-size:.78em;font-weight:700;border-radius:50px}
.rdrit-badge--success{background:#28a745;color:#fff}
.rdrit-badge--danger{background:#dc3545;color:#fff}
.rdrit-alert{padding:.6em .9em;border-left:4px solid;border-radius:5px;margin:.4em 0;font-size:.84em}
.rdrit-alert--primary{background:#e8f4fd;border-color:#2196f3;color:#1a4a6e}
.rdrit-alert--warning{background:#fff8e1;border-color:#ff9800;color:#6d4c00}
.rdrit-alert--danger{background:#fdecea;border-color:#f44336;color:#7d1c14}
/* Notice */
.rdrit-notice{padding:7px 14px;border-radius:6px;font-weight:600;margin-top:8px;background:#edfaf1;color:#1a6b35;border:1px solid #a8e6be;display:inline-block;position:fixed;bottom:24px;right:24px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.12)}
.rdrit-two-col-3{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}
@media(max-width:640px){.rdrit-two-col-3{grid-template-columns:1fr}}
.rdrit-hidden{display:none!important}
/* Shortcodes doc */
.rdrit-sc-doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px}
.rdrit-sc-doc-card{border:1.5px solid #e5e7eb;border-radius:10px;padding:14px 16px;background:#fafafa}
.rdrit-sc-doc-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap}
.rdrit-sc-doc-head code{font-size:.9em;background:#1e293b;color:#e2e8f0;padding:3px 8px;border-radius:5px}
.rdrit-sc-doc-badge{font-size:.72em;font-weight:700;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:20px}
.rdrit-sc-doc-badge--purple{background:#ede9fe;color:#6d28d9}
.rdrit-sc-doc-card p{font-size:.84em;color:#4b5563;margin:0 0 8px}
.rdrit-sc-pre{background:#1e293b;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:.78em;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0;line-height:1.5}
.rdrit-params-table th{font-size:.82em}
.rdrit-params-table td{font-size:.82em;vertical-align:top;padding:7px 10px}
.rdrit-params-table tr:nth-child(odd) td{background:#f9fafb}
.rdrit-params-table code{background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:.9em}
.rdrit-examples-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px}
.rdrit-example{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px}
.rdrit-example-label{font-size:.8em;font-weight:600;color:#374151;margin:0 0 6px}
@media(max-width:700px){.rdrit-sc-doc-grid,.rdrit-examples-grid{grid-template-columns:1fr}}
/* Rôles toggles */
.rdrit-roles-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-top:8px}
.rdrit-role-row{display:flex;align-items:center;justify-content:space-between;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px}
.rdrit-role-row strong{display:block;font-size:.85rem}
.rdrit-role-row code{font-size:.72rem;color:#6b7280}
/* Caps selector */
.rdrit-caps-selector{position:relative}
.rdrit-caps-input-wrap{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-height:38px;padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:text;transition:border-color .15s}
.rdrit-caps-input-wrap:focus-within{border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.15)}
.rdrit-cap-tag{display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-size:.78rem;font-weight:600}
.rdrit-cap-tag button{background:none;border:none;cursor:pointer;color:#1d4ed8;font-size:.9rem;line-height:1;padding:0 2px;opacity:.7}
.rdrit-cap-tag button:hover{opacity:1}
.rdrit-caps-input{border:none;outline:none;font-size:.85rem;min-width:140px;flex:1;padding:2px 0;background:transparent}
.rdrit-caps-dropdown{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:100;display:none;max-height:200px;overflow-y:auto}
.rdrit-caps-option{padding:7px 12px;cursor:pointer;font-size:.84rem;font-family:monospace}
.rdrit-caps-option:hover{background:#eff6ff;color:#2271b1}
/* Citations */
/* Menu Sync */
.rdrit-ms-table{border-collapse:collapse;width:100%;font-size:.85rem}
.rdrit-ms-table th{padding:.5rem .7rem;font-size:.75rem;font-weight:700;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb;background:#f9fafb}
.rdrit-ms-table td{padding:.5rem .7rem;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.rdrit-ms-table tr.rdrit-ms-identical{opacity:.45}
.rdrit-ms-table tr.rdrit-ms-identical.rdrit-ms-force-show{opacity:1}
.rdrit-ms-table tr:hover td{background:#fafafa}
.rdrit-ms-badge{display:inline-flex;align-items:center;padding:.15em .6em;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap}
.rdrit-ms-new{background:#dcfce7;color:#166534}
.rdrit-ms-modified{background:#fef9c3;color:#854d0e}
.rdrit-ms-deleted{background:#fee2e2;color:#991b1b}
.rdrit-ms-external{background:#e0f2fe;color:#0369a1}
.rdrit-ms-identical{background:#f3f4f6;color:#6b7280}
.rdrit-ms-notranslation{background:#f3f4f6;color:#9ca3af;font-style:italic}
.rdrit-ms-url{font-size:.75rem;color:#9ca3af;font-family:monospace;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
.rdrit-ms-indent{display:inline-block;width:16px;height:1px;border-left:2px solid #d1d5db;margin-right:4px;vertical-align:middle}
/* Menu Sync fin */
.rdrit-quotes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:4px}
.rdrit-quote-card{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff}
.rdrit-quote-preview{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;min-height:44px}
.rdrit-quote-preview-label{font-weight:700;font-size:.88rem}
.rdrit-quote-fields{padding:10px 12px;display:flex;flex-direction:column;gap:8px}
.rdrit-quote-field label{font-size:.75rem;font-weight:700;color:#6b7280;display:block;margin-bottom:3px}
.rdrit-quote-colors-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
/* Mailer / Matomo / Tweaks */
.rdrit-two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
@media(max-width:600px){.rdrit-two-col{grid-template-columns:1fr}}
.rdrit-saveable-textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;font-size:.85em;transition:border-color .15s}
.rdrit-saveable-textarea:focus{border-color:#2271b1;outline:none;box-shadow:0 0 0 2px rgba(34,113,177,.15)}
@media(max-width:800px){.rdrit-order-columns{grid-template-columns:1fr}}
@media(max-width:640px){.rdrit-tax-cards{grid-template-columns:1fr}.rdrit-two-col{grid-template-columns:1fr}}
</style>
<?php }

/* ══════════════════════════════════════════════════════════════
   JS ADMIN
   ══════════════════════════════════════════════════════════════ */
function rdrit_admin_js( $nonce, $disabled_cpts_arr, $all_zone_items, $zone_labels ) {
    $palette_js    = json_encode( RDRIT_TAX_PALETTE );
    $disabled_js   = json_encode( array_values($disabled_cpts_arr) );
?>
<script>
(function(){
    const PALETTE    = <?php echo $palette_js; ?>;
    const notice     = document.getElementById('rdrit-saved-notice') || {style:{}};
    let nTimer;
    function showNotice(){ notice.style.display='inline-block'; clearTimeout(nTimer); nTimer=setTimeout(()=>notice.style.display='none',2200); }

    function save(key, value, nonce){
        return fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'rdrit_save_option',nonce,option_key:key,value})
        }).then(r=>r.json()).then(d=>{ if(d.success) showNotice(); });
    }

    /* ── Checkboxes ── */
    document.querySelectorAll('.rdrit-saveable-check').forEach(el=>{
        el.addEventListener('change', function(){
            save(this.dataset.key, this.checked?1:0, this.dataset.nonce);
            if(this.dataset.card){
                document.getElementById(this.dataset.card)?.classList.toggle('rdrit-tax-card--disabled',!this.checked);
            }
        });
    });

    /* ── Selects ── */
    document.querySelectorAll('.rdrit-saveable-select').forEach(el=>{
        el.addEventListener('change', function(){
            save(this.dataset.key, this.value, this.dataset.nonce);

            // Color mode -> afficher/masquer picker
            if(this.classList.contains('rdrit-color-mode-select') && this.dataset.customTarget){
                document.getElementById(this.dataset.customTarget)?.classList.toggle('rdrit-hidden', this.value!=='custom');
            }

            // Style -> mise a jour preview
            if(this.dataset.preview) updateBadgePreview(this.dataset.preview, {style: this.value});

            // Sep type -> masquer/afficher input px
            if(this.classList.contains('rdrit-sep-type-select')){
                const row = this.closest('.rdrit-sort-item') || this.closest('.rdrit-sep-controls');
                if(row){
                    row.querySelectorAll('.rdrit-sep-px-input,.rdrit-sep-px-unit').forEach(el=>{
                        el.classList.toggle('rdrit-hidden', this.value==='none');
                    });
                }
            }
        });
    });

    /* ── Text inputs (debounce) ── */
    document.querySelectorAll('.rdrit-saveable-text').forEach(el=>{
        let t;
        el.addEventListener('input', function(){
            clearTimeout(t);
            t=setTimeout(()=>save(this.dataset.key, this.value, this.dataset.nonce), 500);
        });
    });

    /* ── Number inputs (debounce) ── */
    document.querySelectorAll('.rdrit-saveable-number').forEach(el=>{
        let t;
        el.addEventListener('input', function(){
            clearTimeout(t);
            t=setTimeout(()=>save(this.dataset.key, this.value, this.dataset.nonce), 400);
        });
    });

    /* ── Color pickers ── */
    document.querySelectorAll('.rdrit-saveable-color').forEach(el=>{
        let t;
        el.addEventListener('input', function(){
            if(this.dataset.key==='rdrit_pf_kofi_color'){
                document.querySelector('.rdrit-kofi-preview')?.style.setProperty('background', this.value);
            }
            const card = this.closest('.rdrit-tax-card');
            if(card){
                const taxSlug = this.dataset.key.replace('rdrit_tax_','').replace('_color','');
                updateBadgePreview('rdrit-tax-preview-'+taxSlug, {color: this.value, colorMode:'custom'});
            }
            clearTimeout(t);
            t=setTimeout(()=>save(this.dataset.key, this.value, this.dataset.nonce), 400);
        });
    });

    /* ── Position radios ── */
    document.querySelectorAll('.rdrit-pos-radio').forEach(el=>{
        el.addEventListener('change', function(){
            document.querySelectorAll(`input[name="${this.getAttribute('name')}"]`).forEach(r=>{
                r.closest('.rdrit-pos-btn').classList.toggle('active', r.checked);
            });
            save(this.dataset.key, this.value, this.dataset.nonce);
        });
    });

    /* ── Layout picker ── */
    const lp = document.getElementById('rdrit-layout-picker');
    if(lp) lp.querySelectorAll('.rdrit-layout-card').forEach(c=>{
        c.addEventListener('click', function(){
            lp.querySelectorAll('.rdrit-layout-card').forEach(x=>x.classList.remove('active'));
            this.classList.add('active');
            save(lp.dataset.key, this.dataset.layout, lp.dataset.nonce);
        });
    });

    /* ── CPT toggles ── */
    let disabledCpts = <?php echo $disabled_js; ?>;
    document.querySelectorAll('.rdrit-cpt-toggle').forEach(el=>{
        el.addEventListener('change', function(){
            const cpt = this.dataset.cpt;
            if(this.checked) disabledCpts = disabledCpts.filter(c=>c!==cpt);
            else if(!disabledCpts.includes(cpt)) disabledCpts.push(cpt);
            save('rdrit_rel_disabled_cpts', disabledCpts.join(','), this.dataset.nonce);
        });
    });

    /* ── Flush cache ── */
    const flushBtn = document.getElementById('rdrit-flush-btn');
    if(flushBtn){
        flushBtn.addEventListener('click', function(){
            this.disabled=true; this.textContent='En cours...';
            fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'rdrit_flush_related_cache',nonce:this.dataset.nonce})
            }).then(r=>r.json()).then(d=>{
                const msg = document.getElementById('rdrit-flush-msg');
                if(msg){ msg.style.display='block'; msg.textContent=d.success?'✅ '+d.data.msg:'Erreur.'; setTimeout(()=>msg.style.display='none',3000); }
                flushBtn.disabled=false; flushBtn.textContent='Vider le cache';
            });
        });
    }

    /* ── Onglets ── */
    document.querySelectorAll('.rdrit-tab').forEach(btn=>{
        btn.addEventListener('click', function(){
            document.querySelectorAll('.rdrit-tab,.rdrit-tab-content').forEach(el=>el.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('tab-'+this.dataset.tab)?.classList.add('active');
        });
    });

    /* ══════════════════════════════════════════════════════
       DRAG & DROP NATIF — ordre des zones
       Deux listes independantes (top / bottom)
       Sauvegarde automatique apres chaque deplacement
    ══════════════════════════════════════════════════════ */
    document.querySelectorAll('.rdrit-sortable').forEach(list=>{
        let dragged = null;

        list.querySelectorAll('.rdrit-sort-item').forEach(item=> addDragEvents(item, list));

        function addDragEvents(item, list){
            item.setAttribute('draggable','true');

            item.addEventListener('dragstart', e=>{
                dragged = item;
                item.classList.add('rdrit-dragging');
                e.dataTransfer.effectAllowed='move';
            });
            item.addEventListener('dragend', ()=>{
                item.classList.remove('rdrit-dragging');
                list.querySelectorAll('.rdrit-sort-item').forEach(i=>i.classList.remove('rdrit-drag-over'));
                dragged = null;
                saveOrder(list);
            });
            item.addEventListener('dragover', e=>{
                e.preventDefault();
                e.dataTransfer.dropEffect='move';
                if(dragged && dragged!==item){
                    list.querySelectorAll('.rdrit-sort-item').forEach(i=>i.classList.remove('rdrit-drag-over'));
                    item.classList.add('rdrit-drag-over');
                }
            });
            item.addEventListener('drop', e=>{
                e.preventDefault();
                if(dragged && dragged!==item){
                    const items = [...list.querySelectorAll('.rdrit-sort-item')];
                    const fromIdx = items.indexOf(dragged);
                    const toIdx   = items.indexOf(item);
                    if(fromIdx < toIdx) item.after(dragged);
                    else item.before(dragged);
                }
                item.classList.remove('rdrit-drag-over');
            });
        }
    });

    function saveOrder(list){
        const zone  = list.dataset.zone;
        const nonce = list.dataset.nonce;
        const slugs = [...list.querySelectorAll('.rdrit-sort-item')].map(i=>i.dataset.slug).join(',');
        save('rdrit_order_'+zone, slugs, nonce);
    }

    /* ── Reset ordre ── */
    document.querySelectorAll('.rdrit-order-reset').forEach(btn=>{
        btn.addEventListener('click', function(){
            const zone  = this.dataset.zone;
            const nonce = this.dataset.nonce;
            save('rdrit_order_'+zone, '', nonce).then(()=>location.reload());
        });
    });

    /* ── Mail mode : afficher/masquer sections SMTP et Mailjet ── */
    var mailModeSelect = document.querySelector('[data-key="rdrit_mail_mode"]');
    function toggleMailSections(val){
        var smtp    = document.querySelector('.rdrit-mailer-smtp-section');
        var mailjet = document.querySelector('.rdrit-mailer-mailjet-section');
        if(smtp)    smtp.style.display    = (val==='smtp')    ? '' : 'none';
        if(mailjet) mailjet.style.display = (val==='mailjet') ? '' : 'none';
    }
    if(mailModeSelect){
        toggleMailSections(mailModeSelect.value);
        mailModeSelect.addEventListener('change', function(){ toggleMailSections(this.value); });
    }

    /* ── SMTP : afficher/masquer champs auth ── */
    var smtpAuthCheck = document.querySelector('[data-key="rdrit_smtp_auth"]');
    function toggleSmtpAuth(checked){
        var f = document.querySelector('.rdrit-smtp-auth-fields');
        if(f) f.style.display = checked ? '' : 'none';
    }
    if(smtpAuthCheck){
        toggleSmtpAuth(smtpAuthCheck.checked);
        smtpAuthCheck.addEventListener('change', function(){ toggleSmtpAuth(this.checked); });
    }

    /* ── Mailjet : cle secrete (encode base64 avant envoi) ── */
    document.querySelectorAll('.rdrit-mailjet-secret-input').forEach(function(el){
        el.addEventListener('blur', function(){
            if(!this.value) return;
            var encoded = btoa(unescape(encodeURIComponent(this.value)));
            save(this.dataset.key, encoded, this.dataset.nonce);
            this.value = '';
            this.placeholder = '•••••••• (modifie)';
        });
    });

    /* ── SMTP : mot de passe (encode base64 avant envoi) ── */
    document.querySelectorAll('.rdrit-smtp-pass-input').forEach(function(el){
        el.addEventListener('blur', function(){
            if(!this.value) return; // Ne pas ecraser si vide
            var encoded = btoa(this.value);
            save(this.dataset.key, encoded, this.dataset.nonce);
            this.value = '';
            this.placeholder = '••••••••  (modifié)';
        });
    });

    /* ── Textarea saveable (Matomo code) ── */
    document.querySelectorAll('.rdrit-saveable-textarea').forEach(function(el){
        var timer;
        el.addEventListener('input', function(){
            clearTimeout(timer);
            var k = this.dataset.key, n = this.dataset.nonce, v = this.value;
            timer = setTimeout(function(){ save(k, v, n); }, 800);
        });
    });

    /* ── Test mail ── */
    var mailTestBtn = document.getElementById('rdrit-mail-test-btn');
    if(mailTestBtn){
        mailTestBtn.addEventListener('click', function(){
            var to  = document.getElementById('rdrit-mail-test-to').value;
            var res = document.getElementById('rdrit-mail-test-result');
            res.textContent = '⏳ Envoi en cours…';
            this.disabled = true;
            var btn = this;
            var fd = new FormData();
            fd.append('action','rdrit_mail_test');
            fd.append('nonce', this.dataset.nonce);
            fd.append('to', to);
            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                res.innerHTML = d.success ? d.data.msg : '❌ ' + d.data;
                btn.disabled = false;
            })
            .catch(function(){
                res.textContent = '❌ Erreur réseau';
                btn.disabled = false;
            });
        });
    }

    /* ── Citations : sauvegarde couleurs ── */
    var quotesDefaults = <?php echo wp_json_encode( rdrit_quotes_defaults() ); ?>;

    function collectQuoteColors(){
        var cards = document.querySelectorAll('.rdrit-quote-card');
        var result = [];
        cards.forEach(function(card){
            var idx = parseInt(card.dataset.idx);
            result.push({
                slug  : card.querySelector('.rdrit-quote-slug').value,
                label : card.querySelector('.rdrit-quote-label-input').value,
                color : card.querySelector('.rdrit-quote-color-main').value,
                light : card.querySelector('.rdrit-quote-color-light').value,
                dark  : card.querySelector('.rdrit-quote-color-dark').value,
            });
        });
        return result;
    }

    // Update hex labels + preview on color change
    document.querySelectorAll('.rdrit-quote-card').forEach(function(card){
        function updatePreview(){
            var main  = card.querySelector('.rdrit-quote-color-main').value;
            var light = card.querySelector('.rdrit-quote-color-light').value;
            var dark  = card.querySelector('.rdrit-quote-color-dark').value;
            var prev  = card.querySelector('.rdrit-quote-preview');
            if(prev){ prev.style.borderLeftColor=main; prev.style.background=light; }
            card.querySelector('.rdrit-quote-hex-main').textContent  = main;
            card.querySelector('.rdrit-quote-hex-light').textContent = light;
            card.querySelector('.rdrit-quote-hex-dark').textContent  = dark;
        }
        card.querySelectorAll('input[type="color"]').forEach(function(inp){
            inp.addEventListener('input', updatePreview);
        });
    });

    var quoteSaveBtn = document.getElementById('rdrit-quotes-save');
    var quoteSaveMsg = document.getElementById('rdrit-quotes-save-msg');
    if(quoteSaveBtn){
        quoteSaveBtn.addEventListener('click', function(){
            var data = collectQuoteColors();
            quoteSaveBtn.disabled = true;
            quoteSaveMsg.style.display='none';
            save('rdrit_quotes_colors', JSON.stringify(data), <?php echo wp_json_encode($nonce); ?>)
                .then(function(){ 
                    quoteSaveMsg.textContent='✅ Sauvegardé';
                    quoteSaveMsg.style.display='inline';
                    quoteSaveBtn.disabled=false;
                    setTimeout(function(){ quoteSaveMsg.style.display='none'; },2200);
                });
        });
    }

    var quoteResetBtn = document.getElementById('rdrit-quotes-reset');
    if(quoteResetBtn){
        quoteResetBtn.addEventListener('click', function(){
            if(!confirm('Réinitialiser toutes les couleurs aux valeurs Bootstrap par défaut ?')) return;
            save('rdrit_quotes_colors', JSON.stringify(quotesDefaults), <?php echo wp_json_encode($nonce); ?>)
                .then(function(){ location.reload(); });
        });
    }

    /* ── Menu Sync ── */
    var msSourceSel  = document.getElementById('rdrit-ms-source');
    var msTargetSel  = document.getElementById('rdrit-ms-target');
    var msLangSel    = document.getElementById('rdrit-ms-lang');
    var msAnalyzeBtn = document.getElementById('rdrit-ms-analyze');
    var msDiffWrap   = document.getElementById('rdrit-ms-diff-wrap');
    var msDiffBody   = document.getElementById('rdrit-ms-diff-body');
    var msSyncBtn    = document.getElementById('rdrit-ms-sync');
    var msAnalyzeSt  = document.getElementById('rdrit-ms-analyze-status');
    var msSyncSt     = document.getElementById('rdrit-ms-sync-status');
    var msShowIdent  = document.getElementById('rdrit-ms-show-identical');
    var msDiffData   = null; // Résultat du dernier diff

    var MS_STATUS_LABELS = {
        'new':           '<span class="rdrit-ms-badge rdrit-ms-new">✅ Nouveau</span>',
        'modified':      '<span class="rdrit-ms-badge rdrit-ms-modified">⚠️ Modifié</span>',
        'deleted':       '<span class="rdrit-ms-badge rdrit-ms-deleted">❌ Supprimé</span>',
        'external':      '<span class="rdrit-ms-badge rdrit-ms-external">🔗 Externe</span>',
        'identical':     '<span class="rdrit-ms-badge rdrit-ms-identical">✓ Identique</span>',
        'no_translation':'<span class="rdrit-ms-badge rdrit-ms-notranslation">— Sans traduction</span>',
    };

    function msBuildTable(data) {
        if(!msDiffBody) return;
        msDiffBody.innerHTML = '';
        var hasChanges = false;

        // Items source
        data.items.forEach(function(row) {
            var isIdentical = (row.status === 'identical');
            var isNoTrans   = (row.status === 'no_translation');
            var checkable   = !isIdentical && !isNoTrans;
            if(checkable) hasChanges = true;

            // Indentation pour les sous-menus
            var indent = '';
            if(row.parent) indent = '<span class="rdrit-ms-indent"></span>';

            var afterCol = '';
            if(row.status === 'new' || row.status === 'external') {
                afterCol = '<strong>'+msEsc(row.resolved_title)+'</strong>'
                         + '<span class="rdrit-ms-url">'+msEsc(row.resolved_url)+'</span>';
            } else if(row.status === 'modified') {
                var changes = [];
                if(row.changes && row.changes.title)    changes.push('titre');
                if(row.changes && row.changes.position) changes.push('position');
                afterCol = '<strong>'+msEsc(row.resolved_title)+'</strong>'
                         + '<span class="rdrit-ms-url" style="color:#854d0e">Modif: '+changes.join(', ')+'</span>';
            } else if(row.status === 'identical') {
                afterCol = '<span style="color:#9ca3af">—</span>';
            } else if(row.status === 'no_translation') {
                afterCol = '<span style="color:#9ca3af;font-style:italic">Pas de traduction disponible</span>';
            }

            var tr = document.createElement('tr');
            tr.dataset.key = 'item_' + row.source_id;
            tr.dataset.status = row.status;
            if(isIdentical) tr.classList.add('rdrit-ms-identical');

            tr.innerHTML = '<td>'+(checkable ? '<input type="checkbox" class="rdrit-ms-item-check" data-key="item_'+row.source_id+'" '+(row.status!=='identical'?'checked':'')+'>':'')+'</td>'
                + '<td>'+MS_STATUS_LABELS[row.status]+'</td>'
                + '<td>'+indent+msEsc(row.source_title)+'<span class="rdrit-ms-url">'+msEsc(row.source_url)+'</span></td>'
                + '<td>'+(row.target_title ? msEsc(row.target_title)+'<span class="rdrit-ms-url">'+msEsc(row.target_url)+'</span>' : '<span style="color:#9ca3af">—</span>')+'</td>'
                + '<td>'+afterCol+'</td>'
                + '<td>'+(row.parent ? '↳ Sous-item' : 'Racine')+'</td>';
            msDiffBody.appendChild(tr);
        });

        // Items supprimés
        data.deleted.forEach(function(del) {
            hasChanges = true;
            var tr = document.createElement('tr');
            tr.dataset.key = 'deleted_' + del.target_id;
            tr.dataset.status = 'deleted';
            tr.innerHTML = '<td><input type="checkbox" class="rdrit-ms-item-check" data-key="deleted_'+del.target_id+'" checked></td>'
                + '<td>'+MS_STATUS_LABELS['deleted']+'</td>'
                + '<td><span style="color:#9ca3af">—</span></td>'
                + '<td>'+msEsc(del.target_title)+'<span class="rdrit-ms-url">'+msEsc(del.target_url)+'</span></td>'
                + '<td><span style="color:#991b1b">Sera supprimé</span></td>'
                + '<td>—</td>';
            msDiffBody.appendChild(tr);
        });

        if(!hasChanges) {
            msDiffBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#6b7280;font-style:italic">✓ Aucune différence — les menus sont identiques.</td></tr>';
            if(msSyncBtn) msSyncBtn.disabled = true;
        } else {
            if(msSyncBtn) msSyncBtn.disabled = false;
        }

        msUpdateIdenticalVisibility();
    }

    function msUpdateIdenticalVisibility() {
        if(!msDiffBody) return;
        var show = msShowIdent && msShowIdent.checked;
        msDiffBody.querySelectorAll('tr.rdrit-ms-identical').forEach(function(tr) {
            tr.style.display = show ? '' : 'none';
        });
    }

    function msEsc(str) {
        if(!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function msGetSelected() {
        var sel = [];
        if(!msDiffBody) return sel;
        msDiffBody.querySelectorAll('.rdrit-ms-item-check:checked').forEach(function(cb) {
            sel.push(cb.dataset.key);
        });
        return sel;
    }

    if(msAnalyzeBtn) {
        msAnalyzeBtn.addEventListener('click', function() {
            var src    = msSourceSel ? msSourceSel.value : '';
            var target = msTargetSel  ? msTargetSel.value  : '';
            var lang   = msLangSel    ? msLangSel.value    : '';
            if(!src || !target || !lang) { msAnalyzeSt.textContent = '⚠️ Sélectionnez un menu source, un menu cible ET une langue.'; return; }
            if(src === target) { msAnalyzeSt.textContent = '⚠️ Le menu source et le menu cible ne peuvent pas être identiques.'; return; }

            msAnalyzeBtn.disabled = true;
            msAnalyzeSt.textContent = '⏳ Analyse en cours…';
            if(msDiffWrap) msDiffWrap.style.display = 'none';

            var fd = new FormData();
            fd.append('action',      'rdrit_menu_diff');
            fd.append('nonce',       msAnalyzeBtn.dataset.nonce);
            fd.append('source_menu', src);
            fd.append('target_menu', target);
            fd.append('target_lang', lang);

            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d) {
                msAnalyzeBtn.disabled = false;
                if(!d.success) { msAnalyzeSt.textContent = '❌ ' + (d.data || 'Erreur'); return; }
                msDiffData = d.data;
                msAnalyzeSt.textContent = '';
                msBuildTable(d.data);
                if(msDiffWrap) msDiffWrap.style.display = 'block';
            })
            .catch(function() { msAnalyzeBtn.disabled=false; msAnalyzeSt.textContent='❌ Erreur réseau'; });
        });
    }

    function msDoSync(mode, sel) {
        var src    = msSourceSel ? msSourceSel.value : '';
        var target = msTargetSel  ? msTargetSel.value  : '';
        var lang   = msLangSel   ? msLangSel.value   : '';
        if(!src || !target || !lang) { msSyncSt.textContent = '⚠️ Sélectionnez un menu source, un menu cible ET une langue.'; return; }
        if(src === target) { msSyncSt.textContent = '⚠️ Source et cible identiques !'; return; }

        var confirmMsg = mode === 'full'
            ? '⚠️ Mode COMPLET : le menu cible va être VIDÉ puis recréé depuis FR. Continuer ?'
            : 'Appliquer la synchronisation différentielle pour ' + sel.length + ' item(s) ?';
        if(!confirm(confirmMsg)) return;

        if(msSyncBtn)     msSyncBtn.disabled     = true;
        if(msSyncFullBtn) msSyncFullBtn.disabled  = true;
        msSyncSt.textContent = '⏳ Synchronisation en cours…';

        var fd = new FormData();
        fd.append('action',      'rdrit_menu_sync');
        fd.append('nonce',       (msSyncBtn||msSyncFullBtn).dataset.nonce);
        fd.append('source_menu', src);
        fd.append('target_menu', target);
        fd.append('target_lang', lang);
        fd.append('mode',        mode);
        fd.append('selected',    JSON.stringify(sel));

        fetch(ajaxurl, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if(msSyncBtn)     msSyncBtn.disabled    = false;
            if(msSyncFullBtn) msSyncFullBtn.disabled = false;
            if(!d.success) {
                msSyncSt.innerHTML = '❌ ' + (d.data || 'Erreur');
                return;
            }
            var r = d.data;
            msSyncSt.innerHTML = '✅ ' + r.msg;
            if(r.errors && r.errors.length) {
                msSyncSt.innerHTML += ' — ⚠️ ' + r.errors.join(', ');
            }
            setTimeout(function(){ if(msAnalyzeBtn) msAnalyzeBtn.click(); }, 800);
        })
        .catch(function() {
            if(msSyncBtn)     msSyncBtn.disabled    = false;
            if(msSyncFullBtn) msSyncFullBtn.disabled = false;
            msSyncSt.textContent = '❌ Erreur réseau';
        });
    }

    var msSyncFullBtn = document.getElementById('rdrit-ms-sync-full');

    if(msSyncBtn) {
        msSyncBtn.addEventListener('click', function() {
            var sel = msGetSelected();
            if(!sel.length) { msSyncSt.textContent = '⚠️ Aucun item sélectionné.'; return; }
            msDoSync('diff', sel);
        });
    }

    if(msSyncFullBtn) {
        msSyncFullBtn.addEventListener('click', function() {
            msDoSync('full', []);
        });
    }

    if(msShowIdent)  msShowIdent.addEventListener('change',  msUpdateIdenticalVisibility);

    var msCheckAll   = document.getElementById('rdrit-ms-check-all');
    var msUncheckAll = document.getElementById('rdrit-ms-uncheck-all');
    var msCheckHdr   = document.getElementById('rdrit-ms-check-header');

    if(msCheckAll)   msCheckAll.addEventListener('click',   function(){ if(msDiffBody) msDiffBody.querySelectorAll('.rdrit-ms-item-check').forEach(function(c){c.checked=true;}); });
    if(msUncheckAll) msUncheckAll.addEventListener('click', function(){ if(msDiffBody) msDiffBody.querySelectorAll('.rdrit-ms-item-check').forEach(function(c){c.checked=false;}); });
    if(msCheckHdr)   msCheckHdr.addEventListener('change',  function(){ if(msDiffBody) msDiffBody.querySelectorAll('.rdrit-ms-item-check').forEach(function(c){c.checked=msCheckHdr.checked;}); });

    /* ── Share CPT toggles ── */
    var shareCpts = [];
    (function(){
        var saved = <?php echo wp_json_encode( get_option('rdrit_pf_share_disabled_cpts','') ); ?>;
        shareCpts = saved ? saved.split(',').filter(Boolean) : [];
    })();
    document.querySelectorAll('.rdrit-share-cpt-toggle').forEach(function(el){
        el.addEventListener('change', function(){
            var cpt = this.dataset.cpt;
            if(this.checked){ if(!shareCpts.includes(cpt)) shareCpts.push(cpt); }
            else { shareCpts = shareCpts.filter(function(c){ return c!==cpt; }); }
            save('rdrit_pf_share_disabled_cpts', shareCpts.join(','), this.dataset.nonce);
        });
    });

    /* ── Capacités selector (ads + adblock + matomo) ── */
    function rdritCapsInit(wrap) {
        if(!wrap) return;
        var optKey   = wrap.dataset.optKey;
        var nonce    = wrap.dataset.nonce;
        var input    = wrap.querySelector('.rdrit-caps-input');
        var dropdown = wrap.querySelector('.rdrit-caps-dropdown');
        var tagsWrap = wrap.querySelector('.rdrit-caps-tags');
        var allCaps  = JSON.parse(wrap.dataset.caps || '[]');
        var selected = JSON.parse(wrap.dataset.selected || '[]');

        function saveState() {
            save(optKey, JSON.stringify(selected), nonce);
        }

        function renderTags() {
            tagsWrap.innerHTML = '';
            selected.forEach(function(cap) {
                var tag = document.createElement('span');
                tag.className = 'rdrit-cap-tag';
                tag.innerHTML = cap + '<button type="button" data-cap="'+cap+'" title="Retirer">×</button>';
                tag.querySelector('button').addEventListener('click', function(){
                    selected = selected.filter(function(c){ return c !== this.dataset.cap; }.bind(this));
                    renderTags();
                    saveState();
                });
                tagsWrap.appendChild(tag);
            });
        }

        function renderDropdown(filter) {
            dropdown.innerHTML = '';
            var matches = allCaps.filter(function(c){
                return c.indexOf(filter) !== -1 && selected.indexOf(c) === -1;
            }).slice(0, 15);
            if(!matches.length){ dropdown.style.display='none'; return; }
            matches.forEach(function(cap){
                var li = document.createElement('div');
                li.className = 'rdrit-caps-option';
                li.textContent = cap;
                li.addEventListener('mousedown', function(e){
                    e.preventDefault();
                    if(selected.indexOf(cap) === -1) selected.push(cap);
                    input.value = '';
                    dropdown.style.display = 'none';
                    renderTags();
                    saveState();
                });
                dropdown.appendChild(li);
            });
            dropdown.style.display = matches.length ? 'block' : 'none';
        }

        input.addEventListener('input', function(){ renderDropdown(this.value.toLowerCase()); });
        input.addEventListener('focus', function(){ if(this.value) renderDropdown(this.value.toLowerCase()); });
        input.addEventListener('blur',  function(){ setTimeout(function(){ dropdown.style.display='none'; }, 150); });
        input.addEventListener('keydown', function(e){
            if(e.key === 'Enter' && this.value.trim()) {
                var v = this.value.trim();
                if(selected.indexOf(v) === -1) selected.push(v);
                this.value = '';
                dropdown.style.display = 'none';
                renderTags();
                saveState();
                e.preventDefault();
            }
        });

        renderTags();
    }

    document.querySelectorAll('.rdrit-caps-selector').forEach(function(wrap){
        rdritCapsInit(wrap);
    });

    /* ── Page doc des capacités : filtre + copier ── */
    /* ── Caps doc : filtre texte + filtre source ── */
    var capsDocFilter  = document.getElementById('rdrit-caps-doc-filter');
    var capsActiveSource = 'all';

    function capsDocApplyFilters() {
        var q = capsDocFilter ? capsDocFilter.value.toLowerCase() : '';
        document.querySelectorAll('.rdrit-caps-doc-row').forEach(function(tr){
            var matchText   = !q || tr.dataset.cap.indexOf(q) !== -1;
            var matchSource = capsActiveSource === 'all' || tr.dataset.source === capsActiveSource;
            tr.style.display = (matchText && matchSource) ? '' : 'none';
        });
    }

    if(capsDocFilter) capsDocFilter.addEventListener('input', capsDocApplyFilters);

    document.querySelectorAll('.rdrit-caps-source-filter').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.rdrit-caps-source-filter').forEach(function(b){
                b.classList.remove('active');
                b.style.fontWeight = '';
            });
            this.classList.add('active');
            this.style.fontWeight = '700';
            capsActiveSource = this.dataset.source;
            capsDocApplyFilters();
        });
    });

    /* ── Caps doc : copier ── */
    document.querySelectorAll('.rdrit-caps-doc-copy').forEach(function(btn){
        btn.addEventListener('click', function(){
            var cap = this.dataset.cap;
            navigator.clipboard.writeText(cap).then(function(){
                btn.textContent = '✅';
                setTimeout(function(){ btn.textContent = '📋'; }, 1500);
            });
        });
    });

    /* ── Caps doc : supprimer une capacité orpheline ── */
    document.querySelectorAll('.rdrit-caps-doc-delete').forEach(function(btn){
        btn.addEventListener('click', function(){
            var cap   = this.dataset.cap;
            var nonce = this.dataset.nonce;
            var row   = this.closest('tr');
            if(!confirm('Supprimer la capacité "' + cap + '" de tous les rôles ?')) return;

            btn.disabled = true;
            btn.textContent = '⏳';

            var fd = new FormData();
            fd.append('action', 'rdrit_cap_delete');
            fd.append('nonce',  nonce);
            fd.append('cap',    cap);

            fetch(ajaxurl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if(d.success){
                    row.style.transition = 'opacity .3s';
                    row.style.opacity = '0';
                    setTimeout(function(){ row.remove(); }, 320);
                } else {
                    btn.disabled = false;
                    btn.textContent = '🗑️';
                    alert('Erreur : ' + (d.data || 'inconnue'));
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = '🗑️';
            });
        });
    });

    /* ── HTML Blocks ── */
    function rdritBlocksLoad() {
        var loading = document.getElementById('rdrit-blocks-loading');
        var table   = document.getElementById('rdrit-blocks-table');
        var empty   = document.getElementById('rdrit-blocks-empty');
        var tbody   = document.getElementById('rdrit-blocks-tbody');
        var btn     = document.getElementById('rdrit-blocks-refresh');
        if(!tbody) return;

        if(loading) loading.style.display = 'block';
        if(table)   table.style.display   = 'none';
        if(empty)   empty.style.display   = 'none';

        var fd = new FormData();
        fd.append('action', 'rdrit_blocks_list');
        fd.append('nonce',  btn ? btn.dataset.nonce : '');

        fetch(ajaxurl, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(loading) loading.style.display = 'none';
            if(!d.success || !d.data.length){
                if(empty) empty.style.display = 'block';
                return;
            }
            tbody.innerHTML = '';
            d.data.forEach(function(b){
                var hasPll = document.querySelector('#rdrit-blocks-table th:nth-child(4)');
                var langCol = '';
                if(hasPll && b.translations) {
                    var langs = Object.keys(b.translations).map(function(l){
                        return '<span style="font-size:.75em;padding:1px 5px;background:#eff6ff;color:#1d4ed8;border-radius:10px;margin-right:3px">'
                            + l.toUpperCase() + '</span>';
                    }).join('');
                    langCol = '<td>' + langs + '</td>';
                }
                var statusBadge = b.status === 'publish'
                    ? '<span style="color:#166534;font-size:.75em">✅ Publié</span>'
                    : '<span style="color:#854d0e;font-size:.75em">📝 Brouillon</span>';
                var scriptBadge = b.has_script
                    ? '<span style="color:#7c3aed;font-size:.75em">⚡ JS</span>'
                    : '<span style="color:#9ca3af;font-size:.75em">HTML</span>';

                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td><strong>' + b.title + '</strong>'
                    + (b.slug ? '<br><span style="font-size:.75em;color:#9ca3af">' + b.slug + '</span>' : '')
                    + '</td>'
                    + '<td><code style="font-size:.78em;background:#f3f4f6;padding:2px 6px;border-radius:4px;cursor:pointer" '
                    + 'onclick="var t=this;navigator.clipboard.writeText(t.textContent).then(function(){t.style.background=\'#dcfce7\';setTimeout(function(){t.style.background=\'#f3f4f6\'},1000)})">'
                    + b.shortcode + '</code></td>'
                    + '<td>' + scriptBadge + '</td>'
                    + (langCol || '')
                    + '<td>' + statusBadge + '</td>'
                    + '<td><a href="' + b.edit_url + '" class="button" style="font-size:.75em;padding:2px 8px">✏️ Éditer</a></td>';
                tbody.appendChild(tr);
            });
            if(table) table.style.display = 'table';
        })
        .catch(function(){
            if(loading) loading.style.display = 'none';
            if(empty){ empty.textContent = '❌ Erreur de chargement'; empty.style.display='block'; }
        });
    }

    // Charger au clic sur l'onglet
    document.querySelectorAll('.rdrit-tab[data-tab="htmlblocks"]').forEach(function(btn){
        btn.addEventListener('click', function(){
            setTimeout(rdritBlocksLoad, 50);
        });
    });
    var refreshBtn = document.getElementById('rdrit-blocks-refresh');
    if(refreshBtn) refreshBtn.addEventListener('click', rdritBlocksLoad);

    /* ── Diagnostic utilisateur / capacité ── */
    var diagUserSel    = document.getElementById('rdrit-diag-user');
    var diagCapSel     = document.getElementById('rdrit-diag-cap');
    var diagUserResult = document.getElementById('rdrit-diag-user-result');
    var diagCapResult  = document.getElementById('rdrit-diag-cap-result');

    if(diagUserSel) {
        diagUserSel.addEventListener('change', function(){
            var uid = this.value;
            if(!uid || !window.rdritDiagUsers || !rdritDiagUsers[uid]){
                if(diagUserResult) diagUserResult.style.display='none';
                return;
            }
            var u = rdritDiagUsers[uid];
            document.getElementById('rdrit-diag-user-name').textContent = u.name;
            document.getElementById('rdrit-diag-user-roles').textContent = u.roles.join(', ');

            var capsWrap = document.getElementById('rdrit-diag-user-caps');
            capsWrap.innerHTML = '';
            u.caps.forEach(function(cap){
                var span = document.createElement('span');
                span.dataset.cap = cap;
                // Badge source
                var isCore = window.rdritDiagCaps && rdritDiagCaps[cap] && rdritDiagCaps[cap].source === 'core';
                span.style.cssText = 'display:inline-block;margin:1px 3px;padding:1px 7px;border-radius:10px;cursor:default;' +
                    (isCore ? 'background:#f0fdf4;color:#166534' : 'background:#fef9c3;color:#854d0e');
                span.textContent = cap;
                capsWrap.appendChild(span);
            });

            diagUserResult.style.display = 'block';
        });
    }

    var diagUserCapFilter = document.getElementById('rdrit-diag-user-cap-filter');
    if(diagUserCapFilter) {
        diagUserCapFilter.addEventListener('input', function(){
            var q = this.value.toLowerCase();
            var capsWrap = document.getElementById('rdrit-diag-user-caps');
            if(!capsWrap) return;
            capsWrap.querySelectorAll('span[data-cap]').forEach(function(s){
                s.style.display = s.dataset.cap.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    if(diagCapSel) {
        diagCapSel.addEventListener('change', function(){
            var cap = this.value;
            if(!cap){
                if(diagCapResult) diagCapResult.style.display='none';
                return;
            }
            document.getElementById('rdrit-diag-cap-name').textContent = cap;

            // Rôles ayant cette cap
            var rolesWrap = document.getElementById('rdrit-diag-cap-roles');
            var capData   = window.rdritDiagCaps && rdritDiagCaps[cap];
            rolesWrap.textContent = capData ? capData.roles.join(', ') : '—';

            // Utilisateurs ayant cette cap
            var usersWrap = document.getElementById('rdrit-diag-cap-users');
            usersWrap.innerHTML = '';
            var found = 0;
            if(window.rdritDiagUsers) {
                Object.keys(rdritDiagUsers).forEach(function(uid){
                    var u = rdritDiagUsers[uid];
                    if(u.caps.indexOf(cap) !== -1) {
                        var div = document.createElement('div');
                        div.innerHTML = '<strong>' + u.name + '</strong>'
                            + ' <span style="color:#9ca3af;font-size:.9em">(' + u.roles.join(', ') + ')</span>';
                        usersWrap.appendChild(div);
                        found++;
                    }
                });
            }
            if(!found) usersWrap.innerHTML = '<span style="color:#9ca3af;font-style:italic">Aucun utilisateur</span>';

            diagCapResult.style.display = 'block';
        });
    }

    /* ── Lien tab depuis caps selector ── */
    document.querySelectorAll('.rdrit-tab-link[data-tab]').forEach(function(a){
        a.addEventListener('click', function(e){
            e.preventDefault();
            var tab = this.dataset.tab;
            document.querySelectorAll('.rdrit-tab,.rdrit-tab-content').forEach(function(el){
                el.classList.remove('active');
            });
            var btn  = document.querySelector('.rdrit-tab[data-tab="'+tab+'"]');
            var pane = document.getElementById('tab-'+tab);
            if(btn)  btn.classList.add('active');
            if(pane) pane.classList.add('active');
        });
    });

    /* ── Feed CPT toggles ── */
    var feedCpts = [];
    document.querySelectorAll('.rdrit-feed-cpt-toggle').forEach(function(el){
        if(el.checked) feedCpts.push(el.dataset.cpt);
    });
    document.querySelectorAll('.rdrit-feed-cpt-toggle').forEach(function(el){
        el.addEventListener('change', function(){
            var cpt = this.dataset.cpt;
            if(this.checked){ if(!feedCpts.includes(cpt)) feedCpts.push(cpt); }
            else { feedCpts = feedCpts.filter(function(c){ return c!==cpt; }); }
            save('rdrit_feed_cpts', feedCpts.join(','), this.dataset.nonce);
        });
    });

    /* ── AdBlock action show/hide sub-sections ── */
    function updateAdblockSubs(val){
        var banner = document.querySelector('.rdrit-adblock-sub--banner');
        var images = document.querySelector('.rdrit-adblock-sub--images');
        if(banner) banner.style.display = (val==='banner'||val==='both') ? 'block' : 'none';
        if(images) images.style.display = (val==='images'||val==='both') ? 'block' : 'none';
    }
    var adblockActionSel = document.querySelector('[data-key="rdrit_adblock_action"]');
    if(adblockActionSel){
        updateAdblockSubs(adblockActionSel.value);
        adblockActionSel.addEventListener('change', function(){ updateAdblockSubs(this.value); });
    }

    /* ── AdBlock banner color preview ── */
    var bannerColorInput = document.querySelector('[data-key="rdrit_adblock_banner_color"]');
    if(bannerColorInput){
        bannerColorInput.addEventListener('input', function(){
            var preview = document.querySelector('.rdrit-adblock-banner-preview');
            if(preview) preview.style.background = this.value;
        });
    }

    /* ── updateBadgePreview ── */
    function updateBadgePreview(wrapId, changes){
        const wrap = document.getElementById(wrapId);
        if(!wrap) return;
        const style     = changes.style     || wrap.dataset.style;
        const colorMode = changes.colorMode || wrap.dataset.colorMode;
        const color     = changes.color     || wrap.dataset.color;
        wrap.dataset.style=style; wrap.dataset.colorMode=colorMode;
        if(changes.color) wrap.dataset.color=color;

        wrap.querySelectorAll('.rdrit-term-badge').forEach((badge,i)=>{
            badge.className='rdrit-term-badge rdrit-badge-style--'+style;
            if(style==='mono'){ badge.style.cssText=''; return; }
            let col;
            if(colorMode==='auto')        col=PALETTE[i%PALETTE.length];
            else if(colorMode==='custom') col=color;
            else if(colorMode==='accent') col='#2271b1';
            else                          col='#6b7280';
            badge.style.setProperty('--rdrit-badge-color', col);
        });
    }
})();
</script>
<?php }
