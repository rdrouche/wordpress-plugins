<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integrates with Polylang hooks to trigger automatic translation.
 *
 * Flows:
 *  1. New translation: detect `from_post` + `new_lang` URL params → server-side translate + redirect.
 *  2. Re-translate: meta box button → AJAX endpoint.
 *  3. Taxonomy translation: on term save, translate if Polylang says it's translatable.
 */
class WPPT_Polylang_Integration {

    const NOTICE_PREFIX = 'wppt_notice_';
    const LOCK_PREFIX   = 'wppt_lock_';
    const DONE_PREFIX   = 'wppt_done_';

    public static function init() {
        $instance = new self();

        // Register AJAX endpoints (logged-in users only).
        add_action( 'wp_ajax_wppt_translate_post', array( $instance, 'ajax_translate_post' ) );
        add_action( 'wp_ajax_wppt_translate_term', array( $instance, 'ajax_translate_term' ) );

        // Meta box for the "Re-translate" button on translated posts.
        add_action( 'add_meta_boxes', array( $instance, 'add_retranslate_meta_box' ) );

        // Enqueue admin JS on post edit screens.
        add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_admin_scripts' ) );

        // Notices stockées avant la redirection (succès / erreur de traduction).
        add_action( 'admin_notices', array( $instance, 'render_stored_notice' ) );

        if ( '1' === get_option( 'wppt_auto_translate', '1' ) ) {
            // SERVER-SIDE auto-translate: fires on load-post.php BEFORE the editor
            // renders, then redirects to a clean URL.
            add_action( 'load-post.php', array( $instance, 'server_side_translate_and_redirect' ), 1 );

            // Fallback: save_post hook.
            add_action( 'save_post', array( $instance, 'maybe_auto_translate' ), 99, 3 );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers langues
    // -------------------------------------------------------------------------

    /**
     * Normalise un code langue vers un slug Polylang réellement existant.
     * Gère les cas où Polylang (ou un tiers) renvoie une locale (fr_FR / fr-FR)
     * au lieu du slug de langue (fr).
     *
     * @param string $lang
     * @return string Slug Polylang, ou '' si introuvable.
     */
    public static function normalize_lang( $lang ) {
        $lang = strtolower( trim( (string) $lang ) );
        if ( '' === $lang ) {
            return '';
        }

        $slugs = function_exists( 'pll_languages_list' )
            ? (array) pll_languages_list( array( 'fields' => 'slug' ) )
            : array();

        if ( empty( $slugs ) ) {
            return $lang;
        }

        // 1. Correspondance exacte.
        if ( in_array( $lang, $slugs, true ) ) {
            return $lang;
        }

        // 2. Locale → base (fr_FR / fr-FR → fr).
        $base = preg_split( '/[_-]/', $lang );
        $base = isset( $base[0] ) ? $base[0] : $lang;
        if ( in_array( $base, $slugs, true ) ) {
            return $base;
        }

        // 3. Un slug dont la base correspond (ex. slug 'pt-br' pour 'pt').
        foreach ( $slugs as $slug ) {
            $slug_base = preg_split( '/[_-]/', $slug );
            $slug_base = isset( $slug_base[0] ) ? $slug_base[0] : $slug;
            if ( $slug_base === $base ) {
                return $slug;
            }
        }

        return '';
    }

    /** Langue d'un post, normalisée en slug Polylang. */
    private function post_lang( $post_id ) {
        if ( ! function_exists( 'pll_get_post_language' ) ) {
            return '';
        }
        return self::normalize_lang( (string) pll_get_post_language( $post_id, 'slug' ) );
    }

    /** Langue par défaut, normalisée en slug Polylang. */
    private function default_lang() {
        if ( ! function_exists( 'pll_default_language' ) ) {
            return '';
        }
        return self::normalize_lang( (string) pll_default_language( 'slug' ) );
    }

    // -------------------------------------------------------------------------
    // Notices persistées (avant redirection)
    // -------------------------------------------------------------------------

    private function store_notice( $post_id, $type, $message ) {
        set_transient(
            self::NOTICE_PREFIX . (int) $post_id,
            array( 'type' => $type, 'message' => $message ),
            5 * MINUTE_IN_SECONDS
        );
    }

    public function render_stored_notice() {
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id ) {
            return;
        }

        $notice = get_transient( self::NOTICE_PREFIX . $post_id );
        if ( ! $notice || ! is_array( $notice ) ) {
            return;
        }
        delete_transient( self::NOTICE_PREFIX . $post_id );

        $class = ( 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
        printf(
            '<div class="notice %1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
            esc_attr( $class ),
            esc_html__( 'Polylang Auto Translator :', 'wp-polylang-translator' ),
            esc_html( $notice['message'] )
        );
    }

    // -------------------------------------------------------------------------
    // Admin scripts
    // -------------------------------------------------------------------------

    public function enqueue_admin_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        wp_enqueue_script(
            'wppt-admin',
            WPPT_URL . 'admin/js/admin.js',
            array( 'jquery' ),
            WPPT_VERSION,
            true
        );

        $from_post = isset( $_GET['from_post'] ) ? absint( $_GET['from_post'] ) : 0;
        $new_lang  = isset( $_GET['new_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['new_lang'] ) ) : '';
        $post_id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

        $source_lang = $from_post ? $this->post_lang( $from_post ) : '';

        wp_localize_script( 'wppt-admin', 'wpptAdmin', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'adminUrl'      => admin_url(),
            'nonce'         => wp_create_nonce( 'wppt_nonce' ),
            'fromPost'      => $from_post,
            'newLang'       => self::normalize_lang( $new_lang ),
            'postId'        => $post_id,
            'sourceLang'    => $source_lang,
            'autoTranslate' => get_option( 'wppt_auto_translate', '1' ),
            'strings'       => array(
                'translating'   => __( 'Traduction en cours…', 'wp-polylang-translator' ),
                'done'          => __( 'Traduction terminée ! Vérifiez le contenu avant de publier.', 'wp-polylang-translator' ),
                'error'         => __( 'Erreur lors de la traduction : ', 'wp-polylang-translator' ),
                'retranslating' => __( 'Retraduction depuis la langue source…', 'wp-polylang-translator' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Server-side translate + redirect (primary auto-translate path)
    // -------------------------------------------------------------------------

    public function server_side_translate_and_redirect() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        $from_post = isset( $_GET['from_post'] ) ? absint( $_GET['from_post'] ) : 0;
        $new_lang  = isset( $_GET['new_lang'] )  ? sanitize_text_field( wp_unslash( $_GET['new_lang'] ) ) : '';
        $post_id   = isset( $_GET['post'] )      ? absint( $_GET['post'] ) : 0;

        if ( ! $from_post || ! $new_lang || ! $post_id ) {
            return;
        }

        $target_lang = self::normalize_lang( $new_lang );
        if ( '' === $target_lang ) {
            return;
        }

        // Verrou anti-concurrence + drapeau "déjà traduit".
        $lock = self::LOCK_PREFIX . $post_id;
        $done = self::DONE_PREFIX . $post_id;
        if ( get_transient( $done ) || get_transient( $lock ) ) {
            return;
        }

        $source_post = get_post( $from_post );
        if ( ! $source_post ) {
            return;
        }

        $source_lang = $this->post_lang( $from_post );
        if ( '' === $source_lang ) {
            $this->store_notice( $post_id, 'error', __( 'Impossible de déterminer la langue de l\'article source dans Polylang.', 'wp-polylang-translator' ) );
            return;
        }

        // Le verrou est libéré à la fin : contrairement à la v1.0.0 il n'empêche
        // plus toute nouvelle tentative pendant 5 minutes après un échec.
        set_transient( $lock, '1', 10 * MINUTE_IN_SECONDS );

        // Prevent maybe_auto_translate from firing recursively.
        update_post_meta( $post_id, '_wppt_auto_translating', '1' );

        @set_time_limit( 300 );

        $result = $this->translate_post( $source_post, $post_id, $source_lang, $target_lang );

        delete_post_meta( $post_id, '_wppt_auto_translating' );
        delete_transient( $lock );

        if ( is_wp_error( $result ) ) {
            $this->store_notice( $post_id, 'error', $result->get_error_message() );
        } else {
            set_transient( $done, '1', 10 * MINUTE_IN_SECONDS );
            $this->store_notice( $post_id, 'success', __( 'Traduction terminée. Vérifiez le contenu avant de publier.', 'wp-polylang-translator' ) );
        }

        wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Meta box – Re-translate
    // -------------------------------------------------------------------------

    public function add_retranslate_meta_box() {
        $post_types = get_option( 'wppt_post_types', array( 'post', 'page' ) );
        if ( ! is_array( $post_types ) || empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        foreach ( $post_types as $pt ) {
            add_meta_box(
                'wppt_retranslate',
                __( 'Traduction automatique', 'wp-polylang-translator' ),
                array( $this, 'render_retranslate_meta_box' ),
                $pt,
                'side',
                'default'
            );
        }
    }

    public function render_retranslate_meta_box( $post ) {
        if ( ! function_exists( 'pll_get_post_language' ) ) {
            echo '<p>' . esc_html__( 'Polylang est inactif.', 'wp-polylang-translator' ) . '</p>';
            return;
        }

        $current_lang = $this->post_lang( $post->ID );
        $default_lang = $this->default_lang();

        if ( '' === $current_lang ) {
            echo '<p>' . esc_html__( 'Aucune langue Polylang définie pour cet article. Choisissez d\'abord une langue.', 'wp-polylang-translator' ) . '</p>';
            return;
        }

        if ( $current_lang === $default_lang ) {
            echo '<p>' . esc_html__( 'Cet article est dans la langue par défaut (source). Pas de retraduction possible.', 'wp-polylang-translator' ) . '</p>';
            return;
        }

        // Groupe de traductions Polylang.
        $translations = function_exists( 'pll_get_post_translations' )
            ? (array) pll_get_post_translations( $post->ID )
            : array();

        // On retire l'article courant du groupe.
        $candidates = array();
        foreach ( $translations as $lang => $tid ) {
            $lang = self::normalize_lang( $lang );
            if ( $lang && (int) $tid && (int) $tid !== (int) $post->ID ) {
                $candidates[ $lang ] = (int) $tid;
            }
        }

        $source_id   = 0;
        $source_lang = '';

        if ( $default_lang && isset( $candidates[ $default_lang ] ) ) {
            $source_id   = $candidates[ $default_lang ];
            $source_lang = $default_lang;
        } elseif ( ! empty( $candidates ) ) {
            // Repli : n'importe quelle autre langue du groupe (v1.0.0 abandonnait ici).
            $source_lang = (string) array_key_first( $candidates );
            $source_id   = $candidates[ $source_lang ];
        }

        echo '<p>';
        if ( $source_id ) {
            echo '<strong>' . esc_html__( 'Source :', 'wp-polylang-translator' ) . '</strong> '
                . esc_html( get_the_title( $source_id ) )
                . ' <em>(' . esc_html( strtoupper( $source_lang ) ) . ')</em><br><br>';
            printf(
                '<button type="button" id="wppt-retranslate-btn" class="button button-primary" data-post-id="%d" data-source-id="%d" data-source-lang="%s" data-target-lang="%s">%s</button>',
                esc_attr( $post->ID ),
                esc_attr( $source_id ),
                esc_attr( $source_lang ),
                esc_attr( $current_lang ),
                esc_html__( 'Retraduire depuis la source', 'wp-polylang-translator' )
            );
        } else {
            esc_html_e( 'Aucun article source trouvé : cet article n\'est relié à aucune autre langue dans Polylang.', 'wp-polylang-translator' );
        }
        echo '</p>';

        // Bloc de diagnostic — indispensable pour comprendre les régressions Polylang.
        printf(
            '<p style="font-size:11px;color:#666;margin-top:8px;">%s<br>%s : <code>%s</code><br>%s : <code>%s</code><br>%s : <code>%s</code></p>',
            esc_html__( 'Diagnostic Polylang', 'wp-polylang-translator' ),
            esc_html__( 'Langue de l\'article', 'wp-polylang-translator' ),
            esc_html( $current_lang ),
            esc_html__( 'Langue par défaut', 'wp-polylang-translator' ),
            esc_html( '' !== $default_lang ? $default_lang : '(vide)' ),
            esc_html__( 'Groupe de traductions', 'wp-polylang-translator' ),
            esc_html( empty( $translations ) ? '(vide)' : wp_json_encode( $translations ) )
        );

        echo '<div id="wppt-retranslate-status" style="margin-top:8px;"></div>';
    }

    // -------------------------------------------------------------------------
    // AJAX – Translate a post
    // -------------------------------------------------------------------------

    public function ajax_translate_post() {
        check_ajax_referer( 'wppt_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'wp-polylang-translator' ) ) );
        }

        $source_id   = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $target_id   = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        $source_lang = isset( $_POST['source_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['source_lang'] ) ) : '';
        $target_lang = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '';

        if ( ! $source_id || ! $target_id || ! $target_lang ) {
            wp_send_json_error( array( 'message' => __( 'Paramètres manquants.', 'wp-polylang-translator' ) ) );
        }

        $source_post = get_post( $source_id );
        if ( ! $source_post ) {
            wp_send_json_error( array( 'message' => __( 'Article source introuvable.', 'wp-polylang-translator' ) ) );
        }

        $source_lang = self::normalize_lang( $source_lang );
        $target_lang = self::normalize_lang( $target_lang );

        if ( '' === $source_lang ) {
            $source_lang = $this->post_lang( $source_id );
        }

        @set_time_limit( 300 );

        $result = $this->translate_post( $source_post, $target_id, $source_lang, $target_lang );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array_merge(
            array( 'message' => __( 'Traduction terminée.', 'wp-polylang-translator' ) ),
            is_array( $result ) ? $result : array()
        ) );
    }

    // -------------------------------------------------------------------------
    // AJAX – Translate a taxonomy term
    // -------------------------------------------------------------------------

    public function ajax_translate_term() {
        check_ajax_referer( 'wppt_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_categories' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'wp-polylang-translator' ) ) );
        }

        $source_term_id = isset( $_POST['source_term_id'] ) ? absint( $_POST['source_term_id'] ) : 0;
        $taxonomy       = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $source_lang    = isset( $_POST['source_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['source_lang'] ) ) : '';
        $target_lang    = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '';

        $source_lang = self::normalize_lang( $source_lang );
        $target_lang = self::normalize_lang( $target_lang );

        if ( ! $source_term_id || ! $taxonomy || ! $source_lang || ! $target_lang ) {
            wp_send_json_error( array( 'message' => __( 'Paramètres manquants.', 'wp-polylang-translator' ) ) );
        }

        $source_term = get_term( $source_term_id, $taxonomy );
        if ( ! $source_term || is_wp_error( $source_term ) ) {
            wp_send_json_error( array( 'message' => __( 'Terme source introuvable.', 'wp-polylang-translator' ) ) );
        }

        $translator = WPPT_Translator::get_instance();

        $translated_name = $translator->translate_text( $source_term->name, $source_lang, $target_lang );
        if ( is_wp_error( $translated_name ) ) {
            wp_send_json_error( array( 'message' => $translated_name->get_error_message() ) );
        }

        $translated_desc = '';
        if ( ! empty( $source_term->description ) ) {
            $res = $translator->translate_text( $source_term->description, $source_lang, $target_lang );
            if ( ! is_wp_error( $res ) ) {
                $translated_desc = $res;
            }
        }

        $translations   = function_exists( 'pll_get_term_translations' ) ? (array) pll_get_term_translations( $source_term_id ) : array();
        $target_term_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

        if ( $target_term_id ) {
            wp_update_term( $target_term_id, $taxonomy, array(
                'name'        => $translated_name,
                'description' => $translated_desc,
            ) );
        } else {
            $new_term = wp_insert_term( $translated_name, $taxonomy, array(
                'description' => $translated_desc,
            ) );
            if ( ! is_wp_error( $new_term ) ) {
                $target_term_id = (int) $new_term['term_id'];
                if ( function_exists( 'pll_set_term_language' ) ) {
                    pll_set_term_language( $target_term_id, $target_lang );
                }
                if ( function_exists( 'pll_save_term_translations' ) ) {
                    $translations[ $source_lang ] = (int) $source_term_id;
                    $translations[ $target_lang ] = $target_term_id;
                    pll_save_term_translations( $translations );
                }
            }
        }

        wp_send_json_success( array(
            'message'         => __( 'Terme traduit.', 'wp-polylang-translator' ),
            'translated_name' => $translated_name,
        ) );
    }

    // -------------------------------------------------------------------------
    // Core translation logic
    // -------------------------------------------------------------------------

    /**
     * Translate a source post into a target post.
     *
     * @param WP_Post $source_post
     * @param int     $target_post_id
     * @param string  $source_lang
     * @param string  $target_lang
     * @return array|WP_Error Tableau des chaînes traduites, ou WP_Error.
     */
    public function translate_post( $source_post, $target_post_id, $source_lang, $target_lang ) {
        $translator = WPPT_Translator::get_instance();

        $update_data = array( 'ID' => $target_post_id );
        $translated  = array();
        $errors      = array();
        $content_err = null;

        // --- Title ---
        $translated_title = $translator->translate_text( $source_post->post_title, $source_lang, $target_lang );
        if ( ! is_wp_error( $translated_title ) ) {
            $update_data['post_title'] = $translated_title;
            $translated['title']       = $translated_title;
        } else {
            $errors[] = 'Titre : ' . $translated_title->get_error_message();
        }

        // --- Content ---
        if ( ! empty( $source_post->post_content ) ) {
            $translated_content = $translator->translate_content( $source_post->post_content, $source_lang, $target_lang );

            if ( ! is_wp_error( $translated_content ) ) {
                if ( '1' === get_option( 'wppt_relink_internal_urls', '1' ) ) {
                    $translated_content = $this->relink_internal_urls( $translated_content, $target_lang );
                }
                $update_data['post_content'] = $translated_content;
                $translated['content']       = $translated_content;
            } else {
                $content_err = $translated_content;
                $errors[]    = 'Contenu : ' . $translated_content->get_error_message();

                // Filet de sécurité : on recopie le contenu source non traduit
                // plutôt que de laisser un article vide sans blocs Gutenberg.
                $update_data['post_content'] = $source_post->post_content;
                $translated['content']       = $source_post->post_content;
            }
        }

        // --- Excerpt ---
        if ( '1' === get_option( 'wppt_translate_excerpt', '1' ) && ! empty( $source_post->post_excerpt ) ) {
            $translated_excerpt = $translator->translate_text( $source_post->post_excerpt, $source_lang, $target_lang );
            if ( ! is_wp_error( $translated_excerpt ) ) {
                $update_data['post_excerpt'] = $translated_excerpt;
                $translated['excerpt']       = $translated_excerpt;
            } else {
                $errors[] = 'Extrait : ' . $translated_excerpt->get_error_message();
            }
        }

        if ( ! empty( $errors ) ) {
            WPPT_Translator::log( 'Errors during translation', $errors );
        }

        // ---------------------------------------------------------------------
        // CRUCIAL : un article encore au statut `auto-draft` est traité comme un
        // article vierge par l'éditeur de blocs — son post_title / post_content
        // sont ignorés au chargement, quoi qu'il y ait en base. On promeut donc
        // la traduction en `draft` au moment de l'enregistrement.
        // Sans cela : la traduction est bien écrite en base, mais l'éditeur
        // affiche un article vide (aucun bloc Gutenberg).
        // ---------------------------------------------------------------------
        $current_status = get_post_status( $target_post_id );
        if ( 'auto-draft' === $current_status ) {
            $update_data['post_status'] = 'draft';
        }

        // Save to DB — unhook our own save_post callback first to avoid recursion.
        remove_action( 'save_post', array( $this, 'maybe_auto_translate' ), 99 );
        $saved_id = wp_update_post( wp_slash( $update_data ), true );
        add_action( 'save_post', array( $this, 'maybe_auto_translate' ), 99, 3 );

        if ( is_wp_error( $saved_id ) ) {
            WPPT_Translator::log( 'wp_update_post failed', array( $saved_id->get_error_message() ) );
            return $saved_id;
        }

        // Vérification post-enregistrement : ce qui est réellement en base.
        $saved_post = get_post( $target_post_id );
        if ( $saved_post ) {
            WPPT_Translator::log( 'Post saved', array(
                'id'            => (int) $target_post_id,
                'status_before' => $current_status,
                'status_after'  => $saved_post->post_status,
                'title_len'     => strlen( (string) $saved_post->post_title ),
                'content_len'   => strlen( (string) $saved_post->post_content ),
                'blocks'        => substr_count( (string) $saved_post->post_content, '<!-- wp:' ),
            ) );
        }

        // IMPORTANT : wp_update_post() déclenche save_post et peut faire perdre
        // à Polylang la langue et le groupe de traductions du post cible
        // (c'est ce qui provoque « Aucun article source trouvé »).
        // On réaffirme donc explicitement la langue et le lien de traduction.
        $this->ensure_polylang_link( (int) $source_post->ID, (int) $target_post_id, $source_lang, $target_lang );

        // --- Taxonomies ---
        if ( '1' === get_option( 'wppt_translate_taxonomies', '1' ) ) {
            $this->translate_post_taxonomies( $source_post->ID, $target_post_id, $source_lang, $target_lang );
        }

        if ( $content_err instanceof WP_Error ) {
            return $content_err;
        }

        return $translated;
    }

    /**
     * Réaffirme la langue du post cible et le lien de traduction avec la source.
     */
    private function ensure_polylang_link( $source_post_id, $target_post_id, $source_lang, $target_lang ) {
        if ( ! $source_post_id || ! $target_post_id || '' === $target_lang ) {
            return;
        }

        if ( function_exists( 'pll_set_post_language' ) ) {
            $current = $this->post_lang( $target_post_id );
            if ( $current !== $target_lang ) {
                pll_set_post_language( $target_post_id, $target_lang );
            }
        }

        if ( ! function_exists( 'pll_save_post_translations' ) ) {
            return;
        }

        $translations = function_exists( 'pll_get_post_translations' )
            ? (array) pll_get_post_translations( $source_post_id )
            : array();

        if ( '' !== $source_lang ) {
            $translations[ $source_lang ] = (int) $source_post_id;
        }
        $translations[ $target_lang ] = (int) $target_post_id;

        pll_save_post_translations( $translations );
    }

    /**
     * Translate and assign taxonomies from source post to target post.
     */
    private function translate_post_taxonomies( $source_post_id, $target_post_id, $source_lang, $target_lang ) {
        if ( ! function_exists( 'pll_is_translated_taxonomy' ) ) {
            return;
        }

        $taxonomies = get_post_taxonomies( $source_post_id );

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! pll_is_translated_taxonomy( $taxonomy ) ) {
                continue;
            }

            $terms = wp_get_post_terms( $source_post_id, $taxonomy, array( 'fields' => 'all' ) );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            $target_term_ids = array();
            $translator      = WPPT_Translator::get_instance();

            foreach ( $terms as $term ) {
                $translations   = function_exists( 'pll_get_term_translations' ) ? (array) pll_get_term_translations( $term->term_id ) : array();
                $target_term_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

                if ( ! $target_term_id ) {
                    $translated_name = $translator->translate_text( $term->name, $source_lang, $target_lang );
                    if ( is_wp_error( $translated_name ) ) {
                        continue;
                    }
                    $translated_desc = '';
                    if ( ! empty( $term->description ) ) {
                        $res = $translator->translate_text( $term->description, $source_lang, $target_lang );
                        if ( ! is_wp_error( $res ) ) {
                            $translated_desc = $res;
                        }
                    }
                    $new = wp_insert_term( $translated_name, $taxonomy, array( 'description' => $translated_desc ) );
                    if ( ! is_wp_error( $new ) ) {
                        $target_term_id = (int) $new['term_id'];
                        if ( function_exists( 'pll_set_term_language' ) ) {
                            pll_set_term_language( $target_term_id, $target_lang );
                        }
                        if ( function_exists( 'pll_save_term_translations' ) ) {
                            $translations[ $source_lang ] = (int) $term->term_id;
                            $translations[ $target_lang ] = $target_term_id;
                            pll_save_term_translations( $translations );
                        }
                    }
                }

                if ( $target_term_id ) {
                    $target_term_ids[] = $target_term_id;
                }
            }

            if ( ! empty( $target_term_ids ) ) {
                wp_set_post_terms( $target_post_id, $target_term_ids, $taxonomy );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internal URL rewriting
    // -------------------------------------------------------------------------

    private function relink_internal_urls( $content, $target_lang ) {
        if ( ! function_exists( 'pll_get_post' ) ) {
            return $content;
        }

        $home_url = rtrim( home_url(), '/' );

        return preg_replace_callback(
            '/href=(["\'])(' . preg_quote( $home_url, '/' ) . '[^"\'#?]*)\1/',
            function ( $matches ) use ( $target_lang ) {
                $quote = $matches[1];
                $url   = rtrim( $matches[2], '/' );

                // --- Posts / pages ---
                $post_id = url_to_postid( $url );
                if ( $post_id ) {
                    $translated_id = (int) pll_get_post( $post_id, $target_lang );
                    if ( $translated_id && $translated_id !== $post_id ) {
                        $new_url = get_permalink( $translated_id );
                        if ( $new_url ) {
                            return 'href=' . $quote . esc_url( $new_url ) . $quote;
                        }
                    }
                    return $matches[0];
                }

                // --- Taxonomy terms ---
                if ( function_exists( 'pll_get_term' ) ) {
                    $slug       = basename( (string) parse_url( $url, PHP_URL_PATH ) );
                    $taxonomies = get_taxonomies( array( 'public' => true ) );
                    foreach ( $taxonomies as $tax ) {
                        $term = get_term_by( 'slug', $slug, $tax );
                        if ( ! $term || is_wp_error( $term ) ) {
                            continue;
                        }
                        $term_link = get_term_link( $term, $tax );
                        if ( is_wp_error( $term_link ) ) {
                            continue;
                        }
                        if ( rtrim( $term_link, '/' ) !== $url ) {
                            continue;
                        }
                        $translated_term_id = pll_get_term( $term->term_id, $target_lang );
                        if ( $translated_term_id ) {
                            $new_url = get_term_link( (int) $translated_term_id, $tax );
                            if ( ! is_wp_error( $new_url ) ) {
                                return 'href=' . $quote . esc_url( $new_url ) . $quote;
                            }
                        }
                        break;
                    }
                }

                return $matches[0];
            },
            $content
        );
    }

    // -------------------------------------------------------------------------
    // Auto-translate on new translation creation (fallback save_post)
    // -------------------------------------------------------------------------

    public function maybe_auto_translate( $post_id, $post, $update ) {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( 'auto-draft' !== $post->post_status && 'draft' !== $post->post_status ) {
            return;
        }
        if ( ! isset( $_POST['from_post'] ) && ! isset( $_GET['from_post'] ) ) {
            return;
        }

        $from_post_id = isset( $_POST['from_post'] ) ? absint( $_POST['from_post'] ) : absint( $_GET['from_post'] );
        $new_lang     = isset( $_POST['new_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['new_lang'] ) ) : ( isset( $_GET['new_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['new_lang'] ) ) : '' );
        $new_lang     = self::normalize_lang( $new_lang );

        if ( ! $from_post_id || '' === $new_lang ) {
            return;
        }

        $source_post = get_post( $from_post_id );
        if ( ! $source_post ) {
            return;
        }

        $flag_key = '_wppt_auto_translating';
        if ( get_post_meta( $post_id, $flag_key, true ) ) {
            return;
        }
        update_post_meta( $post_id, $flag_key, '1' );

        $source_lang = $this->post_lang( $from_post_id );
        if ( '' === $source_lang ) {
            delete_post_meta( $post_id, $flag_key );
            return;
        }

        $result = $this->translate_post( $source_post, $post_id, $source_lang, $new_lang );

        delete_post_meta( $post_id, $flag_key );

        if ( is_wp_error( $result ) ) {
            $this->store_notice( $post_id, 'error', $result->get_error_message() );
        }
    }
}
