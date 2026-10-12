<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Admin {

    public static function init() {
        add_action( 'admin_menu',                          [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init',                          [ __CLASS__, 'register_settings' ] );
        add_filter( 'manage_wps_poll_posts_columns',       [ __CLASS__, 'columns' ] );
        add_action( 'manage_wps_poll_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
        add_filter( 'manage_edit-wps_poll_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'pre_get_posts',                       [ __CLASS__, 'sort_by_votes' ] );
        add_action( 'admin_enqueue_scripts',               [ __CLASS__, 'assets' ] );
        add_action( 'post_row_actions',                    [ __CLASS__, 'row_actions' ], 10, 2 );
        // Actions bulk personnalisées
        add_filter( 'bulk_actions-edit-wps_poll',          [ __CLASS__, 'bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-wps_poll',   [ __CLASS__, 'handle_bulk' ], 10, 3 );
        add_action( 'admin_notices',                       [ __CLASS__, 'bulk_notices' ] );
        // Reset AJAX dans l'admin
        add_action( 'wp_ajax_wps_admin_reset',             [ __CLASS__, 'ajax_reset' ] );
        // Pré-remplir les meta avec les valeurs par défaut à la création
        add_action( 'add_meta_boxes',                      [ __CLASS__, 'maybe_set_defaults' ] );
    }

    /* ── Assets ── */
    public static function assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        if ( in_array( $hook, [ 'post.php', 'post-new.php' ] ) && $screen->post_type === 'wps_poll' ) {
            wp_enqueue_script( 'wps-admin', WPS_PLUGIN_URL . 'admin/js/wps-admin.js', [ 'jquery', 'jquery-ui-sortable' ], WPS_VERSION, true );
            wp_enqueue_style( 'wps-admin',  WPS_PLUGIN_URL . 'admin/css/wps-admin.css', [], WPS_VERSION );
        }
        // Styles légers sur la liste
        if ( $screen->id === 'edit-wps_poll' ) {
            wp_add_inline_style( 'wp-admin', '
                .column-wps_votes { width:70px; text-align:center; }
                .column-wps_status { width:90px; }
                .column-wps_active { width:55px; text-align:center; }
                .column-wps_end_date { width:110px; }
                .wps-reset-link { color:#b32d2e; }
                .wps-reset-link:hover { color:#a00; }
            ' );
        }
    }

    /* ── Pré-remplir les defaults à la création ── */
    public static function maybe_set_defaults( ) {
        global $post;
        if ( ! $post || $post->post_type !== 'wps_poll' || $post->post_status !== 'auto-draft' ) return;
        $default_results = get_option( 'wps_results_default', 'public' );
        if ( ! get_post_meta( $post->ID, '_wps_results', true ) ) {
            update_post_meta( $post->ID, '_wps_results', $default_results );
        }
        if ( get_post_meta( $post->ID, '_wps_active', true ) === '' ) {
            update_post_meta( $post->ID, '_wps_active', '1' );
        }
    }

    /* ── Page réglages ── */
    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=wps_poll',
            __( 'Réglages WP Sondage', 'wp-sondage' ),
            __( 'Réglages', 'wp-sondage' ),
            'manage_options',
            'wps_settings',
            [ __CLASS__, 'settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'wps_settings_group', 'wps_antiflood_minutes', [ 'sanitize_callback' => 'absint',             'default' => 60 ] );
        register_setting( 'wps_settings_group', 'wps_results_default',   [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'public' ] );
        register_setting( 'wps_settings_group', 'wps_archive_page_id',   [ 'sanitize_callback' => 'absint', 'default' => 0 ] );

        // Une option par langue Polylang
        if ( function_exists( 'pll_languages_list' ) ) {
            // pll_languages_list() sans argument retourne les slugs de langue
            foreach ( pll_languages_list() as $lang_slug ) {
                register_setting(
                    'wps_settings_group',
                    'wps_archive_page_id_' . $lang_slug,
                    [ 'sanitize_callback' => 'absint', 'default' => 0 ]
                );
            }
        }
    }

    /**
     * Retourne les langues Polylang sous forme de tableau associatif :
     * [ 'fr' => ['slug'=>'fr','name'=>'Français','flag'=>'🇫🇷'], ... ]
     */
    private static function get_pll_languages(): array {
        if ( ! function_exists( 'pll_languages_list' ) ) return [];

        $result = [];
        // Les slugs
        $slugs = pll_languages_list(); // ['fr','en',...]
        // Les noms
        $names = pll_languages_list( [ 'fields' => 'name' ] ); // ['Français','English',...]
        // Les locales (pour déduire les drapeaux si besoin)
        $locales = pll_languages_list( [ 'fields' => 'locale' ] );

        // Correspondance emoji drapeau basique depuis locale
        $flag_map = [
            'fr_FR' => '🇫🇷', 'fr_BE' => '🇧🇪', 'fr_CA' => '🇨🇦', 'fr_CH' => '🇨🇭',
            'en_US' => '🇺🇸', 'en_GB' => '🇬🇧', 'en_CA' => '🇨🇦', 'en_AU' => '🇦🇺',
            'de_DE' => '🇩🇪', 'de_AT' => '🇦🇹', 'de_CH' => '🇨🇭',
            'es_ES' => '🇪🇸', 'es_MX' => '🇲🇽', 'es_AR' => '🇦🇷',
            'it_IT' => '🇮🇹', 'pt_PT' => '🇵🇹', 'pt_BR' => '🇧🇷',
            'nl_NL' => '🇳🇱', 'pl_PL' => '🇵🇱', 'ru_RU' => '🇷🇺',
            'ja'    => '🇯🇵', 'zh_CN' => '🇨🇳', 'zh_TW' => '🇹🇼',
            'ar'    => '🇸🇦', 'ko_KR' => '🇰🇷',
        ];

        foreach ( $slugs as $i => $slug ) {
            $locale   = $locales[ $i ] ?? '';
            $flag     = $flag_map[ $locale ] ?? '🌐';
            $result[ $slug ] = [
                'slug'   => $slug,
                'name'   => $names[ $i ] ?? strtoupper( $slug ),
                'locale' => $locale,
                'flag'   => $flag,
            ];
        }
        return $result;
    }

    public static function settings_page() {
        $pll_langs = self::get_pll_languages();
        $has_pll   = ! empty( $pll_langs );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Réglages WP Sondage', 'wp-sondage' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wps_settings_group' ); ?>
                <table class="form-table">

                    <!-- ══ Page archive ══ -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin:0 0 4px;padding:0;font-size:14px;font-weight:600;border-bottom:1px solid #e2e4e7;padding-bottom:8px">
                                <?php _e( '📄 Page archive des sondages', 'wp-sondage' ); ?>
                            </h2>
                            <p style="font-weight:400;font-size:13px;color:#646970;margin:6px 0 0">
                                <?php _e( 'Sélectionnez une page WordPress normale pour afficher la liste des sondages. La liste est injectée automatiquement après le contenu de la page — aucun shortcode nécessaire.', 'wp-sondage' ); ?>
                                <br>
                                <strong><?php _e( 'Breadcrumb Rank Math', 'wp-sondage' ); ?></strong> :
                                <?php _e( 'HOME → [Cette page] → Titre du sondage (géré automatiquement par le plugin).', 'wp-sondage' ); ?>
                                <br>
                                <strong><?php _e( 'Slug conseillé pour la page', 'wp-sondage' ); ?></strong> :
                                <code>sondages</code> <?php _e( '(le CPT utilise désormais', 'wp-sondage' ); ?> <code>survey</code> <?php _e( '— aucun conflit).', 'wp-sondage' ); ?>
                            </p>
                        </th>
                    </tr>

                    <?php if ( $has_pll ) :
                        foreach ( $pll_langs as $lang ) :
                            $opt_key = 'wps_archive_page_id_' . $lang['slug'];
                            $val     = (int) get_option( $opt_key, 0 );
                    ?>
                    <tr>
                        <th scope="row" style="vertical-align:top;padding-top:14px">
                            <span style="font-size:1.4em;line-height:1;vertical-align:middle"><?php echo $lang['flag']; ?></span>
                            <span style="vertical-align:middle;margin-left:4px">
                                <?php echo esc_html( $lang['name'] ); ?>
                                <small style="display:block;color:#646970;font-weight:400"><?php echo esc_html( $lang['slug'] ); ?></small>
                            </span>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_pages( [
                                'name'              => $opt_key,
                                'id'                => $opt_key,
                                'selected'          => $val,
                                'show_option_none'  => __( '— Aucune —', 'wp-sondage' ),
                                'option_none_value' => '0',
                            ] );
                            // Note : wp_dropdown_pages n'a pas de filtre 'lang' natif.
                            // Polylang filtre automatiquement via son hook 'get_pages'
                            // si la langue est active. Le dropdown montrera les pages
                            // dans toutes les langues mais l'admin saura choisir.
                            ?>
                            <?php if ( $val ) : ?>
                            <p class="description">
                                <a href="<?php echo esc_url( get_permalink( $val ) ); ?>" target="_blank">↗ <?php _e( 'Voir la page', 'wp-sondage' ); ?></a>
                                &nbsp;—&nbsp;
                                <a href="<?php echo esc_url( get_edit_post_link( $val ) ); ?>">✏ <?php _e( 'Modifier', 'wp-sondage' ); ?></a>
                                &nbsp;—&nbsp;
                                <code><?php echo esc_html( get_the_title( $val ) ); ?></code>
                                (<?php echo esc_html( get_post_status( $val ) ); ?>)
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                        endforeach;
                    else :
                        $val = (int) get_option( 'wps_archive_page_id', 0 );
                    ?>
                    <tr>
                        <th scope="row"><?php _e( 'Page archive', 'wp-sondage' ); ?></th>
                        <td>
                            <?php wp_dropdown_pages( [
                                'name'              => 'wps_archive_page_id',
                                'selected'          => $val,
                                'show_option_none'  => __( '— Aucune —', 'wp-sondage' ),
                                'option_none_value' => '0',
                            ] ); ?>
                            <?php if ( $val ) : ?>
                            <p class="description">
                                <a href="<?php echo esc_url( get_permalink( $val ) ); ?>" target="_blank">↗ <?php _e( 'Voir', 'wp-sondage' ); ?></a>
                                &nbsp;—&nbsp;
                                <a href="<?php echo esc_url( get_edit_post_link( $val ) ); ?>">✏ <?php _e( 'Modifier', 'wp-sondage' ); ?></a>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <tr><td colspan="2"><hr style="border:none;border-top:1px solid #e2e4e7;margin:8px 0"></td></tr>

                    <!-- ══ Antiflood ══ -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2 style="margin:0 0 4px;padding:0;font-size:14px;font-weight:600;border-bottom:1px solid #e2e4e7;padding-bottom:8px">
                                <?php _e( '🛡 Antiflood & Résultats', 'wp-sondage' ); ?>
                            </h2>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Délai antiflood (minutes)', 'wp-sondage' ); ?></th>
                        <td>
                            <input type="number" name="wps_antiflood_minutes" min="1" class="small-text"
                                   value="<?php echo esc_attr( get_option( 'wps_antiflood_minutes', 60 ) ); ?>">
                            <p class="description">
                                <?php _e( 'Durée pendant laquelle un même visiteur ne peut revoter.', 'wp-sondage' ); ?>
                                <br><?php _e( 'Basé sur IP + User-Agent hashés SHA-256, sans cookie — RGPD compliant.', 'wp-sondage' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e( 'Résultats par défaut', 'wp-sondage' ); ?></th>
                        <td>
                            <select name="wps_results_default">
                                <option value="public"  <?php selected( get_option( 'wps_results_default' ), 'public' ); ?>><?php _e( 'Publics (visibles après vote)', 'wp-sondage' ); ?></option>
                                <option value="private" <?php selected( get_option( 'wps_results_default' ), 'private' ); ?>><?php _e( 'Privés (admin uniquement)', 'wp-sondage' ); ?></option>
                            </select>
                            <p class="description"><?php _e( 'Valeur pré-sélectionnée pour les nouveaux sondages.', 'wp-sondage' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php _e( '🚀 Cache & CDN', 'wp-sondage' ); ?></h2>
            <?php
            $caches = class_exists( 'WPS_Cache' ) ? WPS_Cache::detect_cache_plugin() : [];
            if ( $caches ) : ?>
            <div class="notice notice-info inline" style="margin:8px 0;padding:10px 12px">
                <p style="margin:0 0 6px">
                    <strong><?php _e( 'Cache détecté :', 'wp-sondage' ); ?></strong>
                    <?php echo esc_html( implode( ', ', $caches ) ); ?>
                </p>
                <p style="margin:0;font-size:12px">
                    <?php _e( 'Le plugin est conçu pour fonctionner avec les caches de page : les nonces sont servis par AJAX (jamais figés dans le HTML), les états personnels sont chargés après coup, et <code>admin-ajax.php</code> est exclu automatiquement.', 'wp-sondage' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <p class="description" style="margin-bottom:8px">
                <?php _e( 'Si des requêtes échouent malgré tout, vérifiez ces exclusions dans votre plugin de cache :', 'wp-sondage' ); ?>
            </p>
            <table class="widefat striped" style="max-width:680px">
                <thead><tr>
                    <th style="width:180px"><?php _e( 'Réglage', 'wp-sondage' ); ?></th>
                    <th><?php _e( 'Valeur à exclure', 'wp-sondage' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><?php _e( 'URL à ne pas cacher', 'wp-sondage' ); ?></td>
                        <td><code>/wp-admin/admin-ajax.php</code></td>
                    </tr>
                    <tr>
                        <td><?php _e( 'Chaînes de requête', 'wp-sondage' ); ?></td>
                        <td><code>action=wps_*</code></td>
                    </tr>
                    <tr>
                        <td><?php _e( 'Cloudflare — Page Rules', 'wp-sondage' ); ?></td>
                        <td><code>*/wp-admin/admin-ajax.php*</code> → <em>Cache Level: Bypass</em></td>
                    </tr>
                    <tr>
                        <td><?php _e( 'LiteSpeed — Do Not Cache URI', 'wp-sondage' ); ?></td>
                        <td><code>/wp-admin/admin-ajax.php</code></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px">
                💡 <?php _e( 'Les pages contenant des sondages <strong>peuvent</strong> rester en cache : le contenu dynamique est injecté par AJAX après le chargement.', 'wp-sondage' ); ?>
            </p>

            <hr>
            <h2><?php _e( 'Shortcodes', 'wp-sondage' ); ?></h2>
            <table class="widefat striped" style="max-width:680px">
                <thead><tr>
                    <th><?php _e('Shortcode','wp-sondage'); ?></th>
                    <th><?php _e('Description','wp-sondage'); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code>[wps_poll id="ID"]</code></td>
                        <td><?php _e('Affiche un sondage (lazy par défaut). Utilisable dans n\'importe quelle page/article.','wp-sondage'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[wps_poll id="ID" lazy="0"]</code></td>
                        <td><?php _e('Sans lazy-loading (chargement immédiat).','wp-sondage'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[wps_random]</code></td>
                        <td><?php _e('Sondage aléatoire ouvert non encore répondu par le visiteur.','wp-sondage'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[wps_random category="slug"]</code></td>
                        <td><?php _e('Idem, filtré par catégorie.','wp-sondage'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px">
                ℹ️ <?php _e( 'La page archive ne nécessite aucun shortcode — la liste est injectée automatiquement.', 'wp-sondage' ); ?>
            </p>

            <hr>
            <h2><?php _e( 'Statistiques globales', 'wp-sondage' ); ?></h2>
            <?php
            global $wpdb;
            $s_polls   = (int) wp_count_posts( 'wps_poll' )->publish;
            $s_votes   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes" );
            $s_choices = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wps_choices" );
            ?>
            <ul>
                <li><?php printf( __( '<strong>%d</strong> sondage(s) publiés', 'wp-sondage' ), $s_polls ); ?></li>
                <li><?php printf( __( '<strong>%d</strong> vote(s) enregistré(s)', 'wp-sondage' ), $s_votes ); ?></li>
                <li><?php printf( __( '<strong>%d</strong> réponse(s) configurée(s)', 'wp-sondage' ), $s_choices ); ?></li>
            </ul>
        </div>
    <?php }

    /* ── Colonnes liste ── */
    public static function columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['wps_question'] = __( 'Question', 'wp-sondage' );
                $new['wps_type']     = __( 'Type', 'wp-sondage' );
                $new['wps_votes']    = __( 'Votes', 'wp-sondage' );
                $new['wps_status']   = __( 'Statut', 'wp-sondage' );
                $new['wps_end_date'] = __( 'Fin', 'wp-sondage' );
                $new['wps_active']   = __( 'Front', 'wp-sondage' );
            }
        }
        unset( $new['date'] );
        $new['date'] = __( 'Date', 'wp-sondage' );
        return $new;
    }

    public static function column_content( $col, $post_id ) {
        global $wpdb;
        switch ( $col ) {
            case 'wps_question':
                $q = get_post_meta( $post_id, '_wps_question', true );
                echo esc_html( $q ?: '—' );
                break;
            case 'wps_type':
                echo get_post_meta( $post_id, '_wps_type', true ) === 'checkbox'
                    ? '<abbr title="' . esc_attr__( 'Choix multiples', 'wp-sondage' ) . '">☑ Multiple</abbr>'
                    : '<abbr title="' . esc_attr__( 'Choix unique', 'wp-sondage' ) . '">◉ Unique</abbr>';
                break;
            case 'wps_votes':
                $cnt = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes WHERE poll_id = %d", $post_id
                ) );
                echo '<strong>' . $cnt . '</strong>';
                if ( $cnt > 0 ) {
                    $url = wp_nonce_url(
                        admin_url( 'admin-ajax.php?action=wps_admin_reset&poll_id=' . $post_id ),
                        'wps_admin_reset_' . $post_id
                    );
                    echo ' <a href="' . esc_url( $url ) . '" class="wps-reset-link"'
                        . ' onclick="return confirm(\'' . esc_js( __( 'Supprimer tous les votes ?', 'wp-sondage' ) ) . '\')"'
                        . ' title="' . esc_attr__( 'Réinitialiser les votes', 'wp-sondage' ) . '">🗑</a>';
                }
                break;
            case 'wps_status':
                if ( get_post_meta( $post_id, '_wps_closed', true ) === '1' ) {
                    echo '<span style="color:#c00">● ' . esc_html__( 'Fermé', 'wp-sondage' ) . '</span>';
                } else {
                    $end = get_post_meta( $post_id, '_wps_end_date', true );
                    if ( $end && strtotime( $end ) < time() ) {
                        echo '<span style="color:#f90">● ' . esc_html__( 'Expiré', 'wp-sondage' ) . '</span>';
                    } else {
                        echo '<span style="color:#090">● ' . esc_html__( 'Ouvert', 'wp-sondage' ) . '</span>';
                    }
                }
                break;
            case 'wps_end_date':
                $d = get_post_meta( $post_id, '_wps_end_date', true );
                echo $d ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $d ) ) ) : '—';
                break;
            case 'wps_active':
                $active = get_post_meta( $post_id, '_wps_active', true );
                echo $active === '1'
                    ? '<span title="' . esc_attr__( 'Visible en front', 'wp-sondage' ) . '">✅</span>'
                    : '<span title="' . esc_attr__( 'Masqué en front', 'wp-sondage' ) . '">❌</span>';
                break;
        }
    }

    /* ── Tri par votes ── */
    public static function sortable_columns( $cols ) {
        $cols['wps_votes']    = 'wps_votes';
        $cols['wps_end_date'] = 'wps_end_date';
        return $cols;
    }

    public static function sort_by_votes( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( $query->get( 'post_type' ) !== 'wps_poll' ) return;

        $orderby = $query->get( 'orderby' );

        if ( $orderby === 'wps_votes' ) {
            global $wpdb;
            // Sous-requête : joint le compte de votes
            add_filter( 'posts_join', function( $join ) use ( $wpdb ) {
                $join .= " LEFT JOIN (
                    SELECT poll_id, COUNT(*) AS vote_count
                    FROM {$wpdb->prefix}wps_votes
                    GROUP BY poll_id
                ) AS wps_vc ON ({$wpdb->posts}.ID = wps_vc.poll_id) ";
                return $join;
            } );
            add_filter( 'posts_orderby', function( $orderby_sql ) use ( $query ) {
                $dir = strtoupper( $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
                return "COALESCE(wps_vc.vote_count, 0) $dir";
            } );
        }

        if ( $orderby === 'wps_end_date' ) {
            $query->set( 'meta_key',  '_wps_end_date' );
            $query->set( 'orderby',   'meta_value' );
        }
    }

    /* ── Bulk actions ── */
    public static function bulk_actions( $actions ) {
        $actions['wps_close']   = __( 'Clore les sondages', 'wp-sondage' );
        $actions['wps_open']    = __( 'Ouvrir les sondages', 'wp-sondage' );
        $actions['wps_show']    = __( 'Afficher en front', 'wp-sondage' );
        $actions['wps_hide']    = __( 'Masquer en front', 'wp-sondage' );
        $actions['wps_reset']   = __( 'Réinitialiser les votes', 'wp-sondage' );
        return $actions;
    }

    public static function handle_bulk( $redirect, $action, $post_ids ) {
        $count = 0;
        global $wpdb;

        foreach ( $post_ids as $id ) {
            if ( get_post_type( $id ) !== 'wps_poll' ) continue;
            switch ( $action ) {
                case 'wps_close':
                    update_post_meta( $id, '_wps_closed', '1' );
                    $count++;
                    break;
                case 'wps_open':
                    update_post_meta( $id, '_wps_closed', '0' );
                    $count++;
                    break;
                case 'wps_show':
                    update_post_meta( $id, '_wps_active', '1' );
                    $count++;
                    break;
                case 'wps_hide':
                    update_post_meta( $id, '_wps_active', '0' );
                    $count++;
                    break;
                case 'wps_reset':
                    $wpdb->delete( $wpdb->prefix . 'wps_votes', [ 'poll_id' => (int) $id ], [ '%d' ] );
                    $count++;
                    break;
            }
        }

        if ( $count ) {
            $redirect = add_query_arg( [
                'wps_bulk_action' => $action,
                'wps_bulk_count'  => $count,
            ], $redirect );
        }
        return $redirect;
    }

    public static function bulk_notices() {
        if ( empty( $_GET['wps_bulk_action'] ) || empty( $_GET['wps_bulk_count'] ) ) return;
        $action = sanitize_key( $_GET['wps_bulk_action'] );
        $count  = (int) $_GET['wps_bulk_count'];

        $messages = [
            'wps_close' => _n( '%d sondage clôturé.', '%d sondages clôturés.', $count, 'wp-sondage' ),
            'wps_open'  => _n( '%d sondage réouvert.', '%d sondages réouverts.', $count, 'wp-sondage' ),
            'wps_show'  => _n( '%d sondage affiché en front.', '%d sondages affichés en front.', $count, 'wp-sondage' ),
            'wps_hide'  => _n( '%d sondage masqué.', '%d sondages masqués.', $count, 'wp-sondage' ),
            'wps_reset' => _n( 'Votes de %d sondage réinitialisés.', 'Votes de %d sondages réinitialisés.', $count, 'wp-sondage' ),
        ];

        if ( isset( $messages[ $action ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . sprintf( esc_html( $messages[ $action ] ), $count )
                . '</p></div>';
        }
    }

    /* ── Reset AJAX depuis la liste admin ── */
    public static function ajax_reset() {
        $poll_id = (int) ( $_GET['poll_id'] ?? 0 );
        check_admin_referer( 'wps_admin_reset_' . $poll_id );

        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission refusée.' );
        if ( ! $poll_id ) wp_die( 'Sondage invalide.' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'wps_votes', [ 'poll_id' => $poll_id ], [ '%d' ] );

        wp_redirect( add_query_arg( [
            'wps_bulk_action' => 'wps_reset',
            'wps_bulk_count'  => 1,
        ], admin_url( 'edit.php?post_type=wps_poll' ) ) );
        exit;
    }

    /* ── Row actions ── */
    public static function row_actions( $actions, $post ) {
        if ( $post->post_type !== 'wps_poll' ) return $actions;
        $actions['wps_shortcode'] = '<code>[wps_poll id="' . $post->ID . '"]</code>';
        return $actions;
    }
}
