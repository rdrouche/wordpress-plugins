<?php
/**
 * RDR-IT Tools — HTML Blocks v1
 *
 * CPT rdrit_html_block + shortcode [rdrit_block id="slug-ou-id"]
 * - Scripts <script> extraits et réinjectés via JS pour garantir l'exécution
 * - Polylang : si traduction disponible, affiche celle de la langue courante
 * - Sans script : HTML brut affiché directement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   CPT — rdrit_html_block
   ============================================================ */
add_action( 'init', 'rdrit_register_html_block_cpt' );
function rdrit_register_html_block_cpt() {
    register_post_type( 'rdrit_html_block', array(
        'labels' => array(
            'name'               => 'Blocs HTML',
            'singular_name'      => 'Bloc HTML',
            'add_new'            => 'Ajouter',
            'add_new_item'       => 'Ajouter un bloc HTML',
            'edit_item'          => 'Modifier le bloc',
            'new_item'           => 'Nouveau bloc',
            'search_items'       => 'Rechercher',
            'not_found'          => 'Aucun bloc trouvé',
            'not_found_in_trash' => 'Aucun bloc dans la corbeille',
            'menu_name'          => 'Blocs HTML',
        ),
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => false, // Géré via RDR-IT Tools
        'show_in_rest'       => false, // Éditeur classique uniquement
        'supports'           => array( 'title', 'editor', 'revisions' ),
        'has_archive'        => false,
        'rewrite'            => false,
        'query_var'          => false,
        'menu_icon'          => 'dashicons-editor-code',
        'capabilities'       => array(
            'edit_post'          => 'manage_options',
            'read_post'          => 'manage_options',
            'delete_post'        => 'manage_options',
            'edit_posts'         => 'manage_options',
            'edit_others_posts'  => 'manage_options',
            'publish_posts'      => 'manage_options',
            'read_private_posts' => 'manage_options',
        ),
    ) );
}

/* ── Polylang : enregistrer le CPT ── */
add_filter( 'pll_get_post_types', 'rdrit_html_block_pll_types', 10, 2 );
function rdrit_html_block_pll_types( array $types, bool $is_settings ): array {
    $types['rdrit_html_block'] = 'rdrit_html_block';
    return $types;
}

/* ── Forcer l'éditeur classique (TinyMCE) en mode texte/code ── */
add_filter( 'use_block_editor_for_post_type', 'rdrit_html_block_classic_editor', 10, 2 );
function rdrit_html_block_classic_editor( bool $use_block, string $post_type ): bool {
    if ( $post_type === 'rdrit_html_block' ) return false;
    return $use_block;
}

/* ── Désactiver les filtres wpautop/wptexturize sur ce CPT ── */
add_action( 'the_post', 'rdrit_html_block_disable_filters' );
function rdrit_html_block_disable_filters( WP_Post $post ) {
    if ( $post->post_type !== 'rdrit_html_block' ) return;
    remove_filter( 'the_content', 'wpautop' );
    remove_filter( 'the_content', 'wptexturize' );
    remove_filter( 'the_content', 'convert_smilies' );
    remove_filter( 'the_content', 'capital_P_dangit' );
}

/* ── Ajouter un message d'aide dans l'éditeur ── */
add_action( 'edit_form_after_title', 'rdrit_html_block_editor_notice' );
function rdrit_html_block_editor_notice( WP_Post $post ) {
    if ( $post->post_type !== 'rdrit_html_block' ) return;
    $slug = $post->post_name ?: '(slug généré à la publication)';
    ?>
    <div class="notice notice-info inline" style="margin:10px 0">
        <p>
            Shortcode : <code>[rdrit_block id="<?php echo esc_html( $slug ); ?>"]</code>
            ou <code>[rdrit_block id="<?php echo esc_html( $post->ID ); ?>"]</code>
            — Collez votre HTML directement, les balises <code>&lt;script&gt;</code>
            sont gérées automatiquement.
        </p>
    </div>
    <?php
}

/* ============================================================
   SHORTCODE [rdrit_block id="slug-ou-id"]
   ============================================================ */
add_shortcode( 'rdrit_block', 'rdrit_html_block_shortcode' );
function rdrit_html_block_shortcode( array $atts ): string {
    $atts = shortcode_atts( array( 'id' => '' ), $atts, 'rdrit_block' );
    $id   = sanitize_text_field( $atts['id'] );
    if ( ! $id ) return '';

    // Résolution par ID numérique ou slug
    if ( is_numeric( $id ) ) {
        $post = get_post( (int) $id );
        if ( ! $post || $post->post_type !== 'rdrit_html_block' || $post->post_status !== 'publish' ) {
            return '';
        }
    } else {
        $posts = get_posts( array(
            'name'        => $id,
            'post_type'   => 'rdrit_html_block',
            'post_status' => 'publish',
            'numberposts' => 1,
            'lang'        => '', // Polylang : chercher dans toutes les langues
        ) );
        if ( empty( $posts ) ) return '';
        $post = $posts[0];
    }

    // Polylang : traduire si disponible
    if ( function_exists( 'pll_get_post' ) ) {
        $translated_id = (int) pll_get_post( $post->ID );
        if ( $translated_id && $translated_id !== $post->ID ) {
            $translated = get_post( $translated_id );
            if ( $translated && $translated->post_status === 'publish' ) {
                $post = $translated;
            }
        }
    }

    // Récupérer le contenu brut (sans filtres WP)
    $raw = $post->post_content;
    if ( empty( $raw ) ) return '';

    // Extraire les blocs <script>...</script>
    $scripts = array();
    $html    = preg_replace_callback(
        '#<script(\s[^>]*)?>.*?</script>#is',
        function ( array $m ) use ( &$scripts ) {
            $scripts[] = $m[0];
            return ''; // Retirer du HTML
        },
        $raw
    );

    // HTML nettoyé
    $output = $html;

    // Réinjecter les scripts via un wrapper JS pour garantir l'exécution
    if ( ! empty( $scripts ) ) {
        $block_id = 'rdrit-block-' . $post->ID . '-' . uniqid();
        $output  .= "\n" . implode( "\n", $scripts );
    }

    return $output;
}

/* ============================================================
   RÉCUPÉRER LE CONTENU BRUT (sans wpautop etc.)
   Utilisé aussi en AJAX pour la prévisualisation
   ============================================================ */
function rdrit_get_block_raw_content( int $post_id ): string {
    global $wpdb;
    // Lire directement depuis la DB pour éviter tous les filtres
    return (string) $wpdb->get_var(
        $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
    );
}

/* ============================================================
   AJAX — liste des blocs pour l'onglet admin
   ============================================================ */
add_action( 'wp_ajax_rdrit_blocks_list', 'rdrit_blocks_list_ajax' );
function rdrit_blocks_list_ajax() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $posts = get_posts( array(
        'post_type'      => 'rdrit_html_block',
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'lang'           => '',
    ) );

    $blocks = array();
    foreach ( $posts as $p ) {
        $has_script = (bool) preg_match( '#<script#i', $p->post_content );
        $lang       = function_exists( 'pll_get_post_language' )
            ? pll_get_post_language( $p->ID )
            : '';

        // Traductions disponibles
        $translations = array();
        if ( function_exists( 'pll_get_post_translations' ) ) {
            $translations = pll_get_post_translations( $p->ID );
        }

        $blocks[] = array(
            'id'           => $p->ID,
            'title'        => $p->post_title,
            'slug'         => $p->post_name,
            'status'       => $p->post_status,
            'has_script'   => $has_script,
            'lang'         => $lang,
            'translations' => $translations,
            'edit_url'     => get_edit_post_link( $p->ID, 'raw' ),
            'shortcode'    => '[rdrit_block id="' . $p->post_name . '"]',
        );
    }

    wp_send_json_success( $blocks );
}
