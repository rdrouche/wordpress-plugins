<?php
defined( 'ABSPATH' ) || exit;

/* ──────────────────────────────────────────────
   Menu administration
   ────────────────────────────────────────────── */
add_action( 'admin_menu', function () {
    add_options_page(
        __( 'RDR Bookmarks', 'rdr-bookmarks' ),
        __( 'RDR Bookmarks', 'rdr-bookmarks' ),
        'manage_options',
        'rdr-bookmarks',
        'rdr_bm_admin_page'
    );
} );

/* ──────────────────────────────────────────────
   register_setting
   ────────────────────────────────────────────── */
add_action( 'admin_init', function () {

    register_setting( 'rdr_bm_settings', 'rdr_bm_enabled_cpts', [
        'type'              => 'array',
        'sanitize_callback' => function ( $val ) {
            if ( ! is_array( $val ) ) return [];
            return array_map( 'sanitize_key', $val );
        },
        'default' => [],
    ] );

    // Labels : autorise les emojis (UTF-8 valide uniquement)
    register_setting( 'rdr_bm_settings', 'rdr_bm_labels', [
        'type'              => 'array',
        'sanitize_callback' => function ( $val ) {
            if ( ! is_array( $val ) ) return [];
            $out = [];
            foreach ( $val as $k => $v ) {
                $out[ sanitize_key( $k ) ] = wp_check_invalid_utf8( (string) $v );
            }
            return $out;
        },
        'default' => [],
    ] );

    register_setting( 'rdr_bm_settings', 'rdr_bm_switch_side', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_key',
        'default'           => 'right',
    ] );

    register_setting( 'rdr_bm_settings', 'rdr_bm_priority_favorite', [
        'type'    => 'integer',
        'default' => 40,
    ] );

    register_setting( 'rdr_bm_settings', 'rdr_bm_priority_read_later', [
        'type'    => 'integer',
        'default' => 30,
    ] );

    // Emojis boutons shortcode — champs séparés pour clarté
    register_setting( 'rdr_bm_settings', 'rdr_bm_emoji_favorite', [
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return wp_check_invalid_utf8( trim( (string) $v ) ); },
        'default'           => '★',
    ] );

    register_setting( 'rdr_bm_settings', 'rdr_bm_emoji_read_later', [
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return wp_check_invalid_utf8( trim( (string) $v ) ); },
        'default'           => '🕐',
    ] );

    // ── Mode lecture ──
    register_setting( 'rdr_bm_settings', 'rdr_bm_reading_mode', [
        'type'    => 'integer',
        'default' => 1,
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_reading_sidebar_sel', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#right-sidebar',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_reading_content_sel', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '#primary',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_reading_label', [
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return wp_check_invalid_utf8( trim( (string) $v ) ); },
        'default'           => 'Mode lecture',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_reading_emoji', [
        'type'              => 'string',
        'sanitize_callback' => function ( $v ) { return wp_check_invalid_utf8( trim( (string) $v ) ); },
        'default'           => '📖',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_priority_reading', [
        'type'    => 'integer',
        'default' => 20,
    ] );

    // ── Boutons de navigation ──
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_display', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_key',
        'default'           => 'both',  // both | favorites | read_later | none
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_position', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_key',
        'default'           => 'after',  // before | after
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_hook', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => 'generate_inside_navigation',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_page_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_behavior', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_key',
        'default'           => 'auto', // auto | modal | page
    ] );
    register_setting( 'rdr_bm_settings', 'rdr_bm_nav_priority', [
        'type'    => 'integer',
        'default' => 9,
    ] );
} );

/* ──────────────────────────────────────────────
   CSS + JS inline pour l'admin (preview live)
   ────────────────────────────────────────────── */
add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'settings_page_rdr-bookmarks' ) return;
    ?>
    <style>
        .rdr-bm-emoji-row   { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .rdr-bm-emoji-input { font-size:22px; width:64px; text-align:center;
                              border:1px solid #8c8f94; border-radius:4px; padding:4px 6px;
                              line-height:1.4; }
        .rdr-bm-preview-btn { display:inline-flex; align-items:center; gap:8px;
                              background:#1e1e24; color:#e2e2e8; padding:7px 16px;
                              border-radius:20px; font-size:13px; border:none;
                              box-shadow:0 2px 8px rgba(0,0,0,.4); cursor:default; }
        .rdr-bm-preview-btn .badge { background:#5c8ee6; color:#fff; border-radius:999px;
                                     padding:1px 8px; font-size:11px; font-weight:700; }
        .rdr-bm-hint        { color:#646970; font-size:12px; margin-top:4px; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        function live(inputId, targetId) {
            var i = document.getElementById(inputId);
            var t = document.getElementById(targetId);
            if (!i || !t) return;
            i.addEventListener('input', function () { t.textContent = i.value || '?'; });
        }
        live('rdr-emo-fav',  'prev-emo-fav');
        live('rdr-emo-rl',   'prev-emo-rl');

        // Labels boutons aussi en live preview
        ['favorite','read_later'].forEach(function(k){
            var inp = document.querySelector('[name="rdr_bm_labels['+k+']"]');
            var tgt = document.getElementById('prev-label-'+k);
            if (!inp || !tgt) return;
            inp.addEventListener('input', function(){ tgt.textContent = inp.value || '—'; });
        });
    });
    </script>
    <?php
} );

/* ──────────────────────────────────────────────
   Page HTML
   ────────────────────────────────────────────── */
function rdr_bm_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $all_cpts = get_post_types( [ 'public' => true ], 'objects' );
    $exclude  = [ 'attachment' ];
    $enabled  = (array) get_option( 'rdr_bm_enabled_cpts', [] );

    $label_defaults = [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'consent'    => "Ce site utilise le stockage local (localStorage) pour sauvegarder vos favoris. Aucune donnée n'est transmise à un serveur. Acceptez-vous ?",
        'accept'     => 'Accepter',
        'decline'    => 'Refuser',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ];
    $labels   = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    $side     = get_option( 'rdr_bm_switch_side', 'right' );
    $prio_fav = (int) get_option( 'rdr_bm_priority_favorite', 40 );
    $prio_rl  = (int) get_option( 'rdr_bm_priority_read_later', 30 );
    $emo_fav  = get_option( 'rdr_bm_emoji_favorite',  '★' );
    $emo_rl   = get_option( 'rdr_bm_emoji_read_later', '🕐' );
    ?>
    <div class="wrap">
        <h1><?= esc_html__( 'RDR Bookmarks — Configuration', 'rdr-bookmarks' ) ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'rdr_bm_settings' ); ?>

            <!-- ═══ 1. Types de contenu ═══ -->
            <h2><?= esc_html__( 'Types de contenu activés', 'rdr-bookmarks' ) ?></h2>
            <p class="description"><?= esc_html__( 'Les switches Favoris / À lire apparaîtront sur les singuliers de ces types.', 'rdr-bookmarks' ) ?></p>
            <table class="form-table" role="presentation"><tbody>
            <?php foreach ( $all_cpts as $cpt ) :
                if ( in_array( $cpt->name, $exclude, true ) ) continue; ?>
                <tr>
                    <th scope="row">
                        <?= esc_html( $cpt->labels->name ) ?>
                        <code style="font-weight:400;margin-left:4px"><?= esc_html( $cpt->name ) ?></code>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdr_bm_enabled_cpts[]"
                                   value="<?= esc_attr( $cpt->name ) ?>"
                                   <?php checked( in_array( $cpt->name, $enabled, true ) ) ?>>
                            <?= esc_html__( 'Activer', 'rdr-bookmarks' ) ?>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <!-- ═══ 2. Position floating stack ═══ -->
            <h2><?= esc_html__( 'Position des switches flottants', 'rdr-bookmarks' ) ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?= esc_html__( 'Côté', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <select name="rdr_bm_switch_side">
                            <option value="right" <?php selected( $side, 'right' ) ?>><?= esc_html__( 'Droite', 'rdr-bookmarks' ) ?></option>
                            <option value="left"  <?php selected( $side, 'left'  ) ?>><?= esc_html__( 'Gauche', 'rdr-bookmarks' ) ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Priorité — Favoris', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="number" name="rdr_bm_priority_favorite"
                               value="<?= esc_attr( $prio_fav ) ?>" min="1" max="200" style="width:80px">
                        <p class="description"><?= esc_html__( 'Plus petit = plus bas. Coloration = 60, Glossaire = 50. Recommandé : Favoris 40, À lire 30.', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Priorité — À lire', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="number" name="rdr_bm_priority_read_later"
                               value="<?= esc_attr( $prio_rl ) ?>" min="1" max="200" style="width:80px">
                    </td>
                </tr>
            </table>

            <!-- ═══ 3. Icônes shortcode ═══ -->
            <h2><?= esc_html__( 'Icônes des boutons (shortcode & modale)', 'rdr-bookmarks' ) ?></h2>
            <p class="description"><?= esc_html__( 'Copiez-collez un emoji ou tapez un caractère. Il apparaîtra dans les boutons du shortcode [rdr_bookmarks], dans la modale et dans les switches flottants.', 'rdr-bookmarks' ) ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?= esc_html__( 'Icône Favoris', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <div class="rdr-bm-emoji-row">
                            <input type="text" id="rdr-emo-fav" name="rdr_bm_emoji_favorite"
                                   value="<?= esc_attr( $emo_fav ) ?>" maxlength="8"
                                   class="rdr-bm-emoji-input">
                            <div class="rdr-bm-preview-btn">
                                <span id="prev-emo-fav"><?= esc_html( $emo_fav ) ?></span>
                                <span id="prev-label-favorite"><?= esc_html( $labels['favorite'] ) ?></span>
                                <span class="badge">3</span>
                            </div>
                        </div>
                        <p class="rdr-bm-hint"><?= esc_html__( 'Suggestions : ★ ♥ 🔖 🌟 ❤️ 💛 👍 🏷️', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Icône À lire', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <div class="rdr-bm-emoji-row">
                            <input type="text" id="rdr-emo-rl" name="rdr_bm_emoji_read_later"
                                   value="<?= esc_attr( $emo_rl ) ?>" maxlength="8"
                                   class="rdr-bm-emoji-input">
                            <div class="rdr-bm-preview-btn">
                                <span id="prev-emo-rl"><?= esc_html( $emo_rl ) ?></span>
                                <span id="prev-label-read_later"><?= esc_html( $labels['read_later'] ) ?></span>
                                <span class="badge">2</span>
                            </div>
                        </div>
                        <p class="rdr-bm-hint"><?= esc_html__( 'Suggestions : 🕐 📖 📚 🔔 📌 ⏳ 🗂️ 🗃️', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
            </table>

            <!-- ═══ 4. Labels ═══ -->
            <h2><?= esc_html__( 'Labels et textes', 'rdr-bookmarks' ) ?></h2>
            <p class="description"><?= esc_html__( 'Si Polylang est actif, ces valeurs sont enregistrées comme chaînes traduisibles (Polylang > Chaînes de caractères).', 'rdr-bookmarks' ) ?></p>
            <table class="form-table" role="presentation">
            <?php
            $label_fields = [
                'favorite'   => __( 'Label — Favoris (switch & bouton)', 'rdr-bookmarks' ),
                'read_later' => __( 'Label — À lire (switch & bouton)', 'rdr-bookmarks' ),
                'search'     => __( 'Placeholder — barre de recherche modale', 'rdr-bookmarks' ),
                'consent'    => __( 'Texte — tooltip consentement RGPD', 'rdr-bookmarks' ),
                'accept'     => __( 'Bouton — Accepter (RGPD)', 'rdr-bookmarks' ),
                'decline'    => __( 'Bouton — Refuser (RGPD)', 'rdr-bookmarks' ),
                'empty_fav'  => __( 'Message — liste Favoris vide', 'rdr-bookmarks' ),
                'empty_rl'   => __( 'Message — liste À lire vide', 'rdr-bookmarks' ),
                'no_results' => __( 'Message — aucun résultat de recherche', 'rdr-bookmarks' ),
            ];
            foreach ( $label_fields as $key => $desc ) : ?>
                <tr>
                    <th scope="row"><?= esc_html( $desc ) ?></th>
                    <td>
                        <?php if ( $key === 'consent' ) : ?>
                            <textarea name="rdr_bm_labels[<?= esc_attr( $key ) ?>]"
                                      rows="3" class="large-text"><?= esc_textarea( $labels[ $key ] ?? '' ) ?></textarea>
                        <?php else : ?>
                            <input type="text"
                                   name="rdr_bm_labels[<?= esc_attr( $key ) ?>]"
                                   value="<?= esc_attr( $labels[ $key ] ?? '' ) ?>"
                                   class="regular-text">
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </table>

            <!-- ═══ 5. Mode lecture ═══ -->
            <h2><?= esc_html__( 'Mode lecture', 'rdr-bookmarks' ) ?></h2>
            <p class="description"><?= esc_html__( 'Ajoute un switch flottant sur les singuliers activés pour masquer la sidebar. État non mémorisé — reset à chaque chargement de page.', 'rdr-bookmarks' ) ?></p>
            <?php
            $rm_enabled  = (int) get_option( 'rdr_bm_reading_mode', 1 );
            $rm_sidebar  = get_option( 'rdr_bm_reading_sidebar_sel', '#right-sidebar' );
            $rm_content  = get_option( 'rdr_bm_reading_content_sel', '#primary' );
            $rm_label    = get_option( 'rdr_bm_reading_label', 'Mode lecture' );
            $rm_emoji    = get_option( 'rdr_bm_reading_emoji', '📖' );
            $rm_priority = (int) get_option( 'rdr_bm_priority_reading', 20 );
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?= esc_html__( 'Activer', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdr_bm_reading_mode" value="1"
                                   <?php checked( $rm_enabled, 1 ) ?>>
                            <?= esc_html__( 'Afficher le switch Mode lecture', 'rdr-bookmarks' ) ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Sélecteur sidebar', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="text" name="rdr_bm_reading_sidebar_sel"
                               value="<?= esc_attr( $rm_sidebar ) ?>"
                               class="regular-text" placeholder="#right-sidebar">
                        <p class="description"><?= esc_html__( 'Sélecteur CSS de la sidebar à masquer. GeneratePress RDR-IT : #right-sidebar', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Sélecteur contenu', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="text" name="rdr_bm_reading_content_sel"
                               value="<?= esc_attr( $rm_content ) ?>"
                               class="regular-text" placeholder="#primary">
                        <p class="description"><?= esc_html__( 'Sélecteur CSS du contenu principal, zone étendue quand la sidebar est masquée. GeneratePress RDR-IT : #primary', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Icône', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="text" name="rdr_bm_reading_emoji"
                               value="<?= esc_attr( $rm_emoji ) ?>"
                               maxlength="8" style="font-size:20px;width:56px;text-align:center;border:1px solid #8c8f94;border-radius:4px;padding:4px 6px">
                        <p class="rdr-bm-hint"><?= esc_html__( 'Suggestions : 📖 📰 👁️ 🔍 🧘 🖥️', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Label', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="text" name="rdr_bm_reading_label"
                               value="<?= esc_attr( $rm_label ) ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Priorité dans le stack', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="number" name="rdr_bm_priority_reading"
                               value="<?= esc_attr( $rm_priority ) ?>" min="1" max="200" style="width:80px">
                        <p class="description"><?= esc_html__( 'Valeur basse = position plus basse. Recommandé : 20 (sous Favoris=40).', 'rdr-bookmarks' ) ?></p>
                    </td>
                </tr>
            </table>

            <!-- ═══ 6. Boutons de navigation ═══ -->
            <h2><?= esc_html__( 'Boutons dans la navigation', 'rdr-bookmarks' ) ?></h2>
            <p class="description"><?= esc_html__( 'Affiche des boutons avec compteur directement dans la barre de navigation du thème. Au clic, ouvre la modale Favoris / À lire.', 'rdr-bookmarks' ) ?></p>
            <?php
            $nav_display  = get_option( 'rdr_bm_nav_display', 'both' );
            $nav_position = get_option( 'rdr_bm_nav_position', 'after' );
            $nav_hook     = get_option( 'rdr_bm_nav_hook', 'generate_inside_navigation' );
            ?>
            <?php
            $nav_behavior = get_option( 'rdr_bm_nav_behavior', 'auto' );
            $nav_page_url = get_option( 'rdr_bm_nav_page_url', '' );
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?= esc_html__( 'Boutons affichés', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <select name="rdr_bm_nav_display">
                            <option value="both"       <?php selected( $nav_display, 'both' ) ?>><?= esc_html__( 'Favoris + À lire (les deux)', 'rdr-bookmarks' ) ?></option>
                            <option value="favorites"  <?php selected( $nav_display, 'favorites' ) ?>><?= esc_html__( 'Favoris uniquement', 'rdr-bookmarks' ) ?></option>
                            <option value="read_later" <?php selected( $nav_display, 'read_later' ) ?>><?= esc_html__( 'À lire uniquement', 'rdr-bookmarks' ) ?></option>
                            <option value="combined"   <?php selected( $nav_display, 'combined' ) ?>><?= esc_html__( 'Un seul bouton (total des deux)', 'rdr-bookmarks' ) ?></option>
                            <option value="none"       <?php selected( $nav_display, 'none' ) ?>><?= esc_html__( 'Désactivé', 'rdr-bookmarks' ) ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Comportement au clic', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <select name="rdr_bm_nav_behavior">
                            <option value="auto" <?php selected( $nav_behavior, 'auto' ) ?>><?= esc_html__( 'Auto — modale si disponible, sinon redirection vers la page', 'rdr-bookmarks' ) ?></option>
                            <option value="modal"<?php selected( $nav_behavior, 'modal' ) ?>><?= esc_html__( 'Toujours ouvrir la modale (injectée sur toutes les pages)', 'rdr-bookmarks' ) ?></option>
                            <option value="page" <?php selected( $nav_behavior, 'page' ) ?>><?= esc_html__( 'Toujours rediriger vers la page', 'rdr-bookmarks' ) ?></option>
                        </select>
                        <p class="description">
                            <?= esc_html__( 'Mode "Auto" recommandé : si la page courante contient [rdr_bookmarks], la modale s\'ouvre directement. Sinon, redirige vers l\'URL configurée ci-dessous.', 'rdr-bookmarks' ) ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'URL de la page [rdr_bookmarks]', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="url" name="rdr_bm_nav_page_url"
                               value="<?= esc_attr( $nav_page_url ) ?>"
                               class="regular-text"
                               placeholder="https://monsite.com/mes-articles/">
                        <p class="description">
                            <?= esc_html__( 'URL de la page contenant [rdr_bookmarks]. Redirection si la modale n\'est pas disponible.', 'rdr-bookmarks' ) ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Position dans la nav', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <select name="rdr_bm_nav_position">
                            <option value="before" <?php selected( $nav_position, 'before' ) ?>><?= esc_html__( 'Avant les autres éléments', 'rdr-bookmarks' ) ?></option>
                            <option value="after"  <?php selected( $nav_position, 'after' ) ?>><?= esc_html__( 'Après les autres éléments', 'rdr-bookmarks' ) ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Priorité (weight)', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <?php $nav_priority = (int) get_option( 'rdr_bm_nav_priority', 9 ); ?>
                        <input type="number" name="rdr_bm_nav_priority"
                               value="<?= esc_attr( $nav_priority ) ?>" min="1" max="999" style="width:80px">
                        <p class="description">
                            <?= esc_html__( 'Priorité du hook/filtre. Plus petit = s\'exécute avant. Ex : 9 place les boutons avant le toggle thème (priorité 10). Valeur par défaut : 9.', 'rdr-bookmarks' ) ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?= esc_html__( 'Hook WordPress', 'rdr-bookmarks' ) ?></th>
                    <td>
                        <input type="text" name="rdr_bm_nav_hook"
                               value="<?= esc_attr( $nav_hook ) ?>"
                               class="regular-text">
                        <p class="description">
                            <?= esc_html__( 'GeneratePress : generate_inside_navigation (recommandé). Injecte les boutons à l\'interieur de la barre de navigation, au même niveau que le toggle thème.', 'rdr-bookmarks' ) ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Enregistrer les réglages', 'rdr-bookmarks' ) ); ?>
        </form>

        <hr>
        <h2><?= esc_html__( 'Shortcode', 'rdr-bookmarks' ) ?></h2>
        <p><?= esc_html__( 'Placez ce shortcode dans un widget HTML/texte de votre pied de page :', 'rdr-bookmarks' ) ?></p>
        <p><code>[rdr_bookmarks]</code></p>
        <p><?= esc_html__( 'Il affiche deux boutons icône + compteur ouvrant la modale à onglets avec recherche.', 'rdr-bookmarks' ) ?></p>

        <hr>
        <h2>📋 <?= esc_html__( 'Documentation', 'rdr-bookmarks' ) ?></h2>

        <h3><?= esc_html__( 'Shortcodes disponibles', 'rdr-bookmarks' ) ?></h3>
        <table class="widefat striped" style="max-width:800px">
            <thead><tr>
                <th><?= esc_html__( 'Shortcode', 'rdr-bookmarks' ) ?></th>
                <th><?= esc_html__( 'Usage', 'rdr-bookmarks' ) ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code>[rdr_bookmarks]</code></td>
                    <td><?= esc_html__( 'Boutons Favoris + À lire avec compteurs. Au clic, ouvre une modale à onglets avec recherche. À placer dans un widget de pied de page.', 'rdr-bookmarks' ) ?></td>
                </tr>
                <tr>
                    <td><code>[rdr_bookmarks_page]</code></td>
                    <td><?= esc_html__( 'Affiche directement les listes Favoris / À lire dans le contenu d\'une page WP (onglets + recherche, sans modale). Créer une page dédiée, coller ce shortcode, copier son URL dans "URL de la page" ci-dessus.', 'rdr-bookmarks' ) ?></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top:1.5em"><?= esc_html__( 'Hook WordPress — valeurs disponibles', 'rdr-bookmarks' ) ?></h3>
        <table class="widefat striped" style="max-width:800px">
            <thead><tr>
                <th style="width:260px"><?= esc_html__( 'Valeur du champ Hook', 'rdr-bookmarks' ) ?></th>
                <th><?= esc_html__( 'Comportement', 'rdr-bookmarks' ) ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code>generate_inside_navigation</code></td>
                    <td><?= esc_html__( 'GeneratePress — injecte via un hook action dans la barre de nav. Les boutons apparaissent au même niveau que le toggle thème et les icônes.', 'rdr-bookmarks' ) ?></td>
                </tr>
                <tr>
                    <td><code>wp_nav_menu_items</code></td>
                    <td>
                        <?= esc_html__( 'Universel (tous thèmes) — injecte les boutons comme élément de liste dans le menu principal via un filtre. Compatible avec n\'importe quel thème WordPress.', 'rdr-bookmarks' ) ?>
                        <br><br>
                        <strong><?= esc_html__( 'Exemple de code équivalent :', 'rdr-bookmarks' ) ?></strong>
                        <pre style="background:#1e1e24;color:#c9d1d9;padding:12px;border-radius:6px;font-size:12px;overflow-x:auto;margin-top:8px">// Priorité 9 = s'exécute AVANT le toggle (priorité 10)
add_filter( 'wp_nav_menu_items', function( $items, $args ) {
    if ( $args->theme_location !== 'primary' ) return $items;
    $items .= '[vos boutons ici]';
    return $items;
}, 9, 2 );</pre>
                        <?= esc_html__( 'Le plugin cible automatiquement le menu "primary". Pour cibler un autre emplacement de menu, utilisez le filtre PHP :', 'rdr-bookmarks' ) ?>
                        <pre style="background:#1e1e24;color:#c9d1d9;padding:12px;border-radius:6px;font-size:12px;margin-top:8px">add_filter( 'rdr_bm_nav_menu_location', fn() => 'mon-menu' );</pre>
                    </td>
                </tr>
                <tr>
                    <td><code><?= esc_html__( 'n\'importe quel hook action', 'rdr-bookmarks' ) ?></code></td>
                    <td><?= esc_html__( 'Ex : wp_nav_menu_before_items, generate_after_primary_menu, etc. Si la valeur n\'est pas wp_nav_menu_items, elle est traitée comme un hook action WordPress standard.', 'rdr-bookmarks' ) ?></td>
                </tr>
            </tbody>
        </table>

        <h3 style="margin-top:1.5em"><?= esc_html__( 'Stockage localStorage', 'rdr-bookmarks' ) ?></h3>
        <table class="widefat striped" style="max-width:800px">
            <thead><tr>
                <th><?= esc_html__( 'Clé', 'rdr-bookmarks' ) ?></th>
                <th><?= esc_html__( 'Contenu', 'rdr-bookmarks' ) ?></th>
            </tr></thead>
            <tbody>
                <tr><td><code>rdr_bm_consent</code></td><td><?= esc_html__( '"yes" ou "no" — consentement RGPD de l\'utilisateur', 'rdr-bookmarks' ) ?></td></tr>
                <tr><td><code>rdr_bm_favorites_{lang}</code></td><td><?= esc_html__( 'Liste des favoris (tableau JSON) par langue Polylang', 'rdr-bookmarks' ) ?></td></tr>
                <tr><td><code>rdr_bm_read_later_{lang}</code></td><td><?= esc_html__( 'Liste À lire (tableau JSON) par langue Polylang', 'rdr-bookmarks' ) ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php
}
