<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPPT_Settings {

    public static function init() {
        $instance = new self();
        add_action( 'admin_menu',    array( $instance, 'add_menu' ) );
        add_action( 'admin_init',    array( $instance, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_settings_scripts' ) );
        // AJAX test connection.
        add_action( 'wp_ajax_wppt_test_connection', array( $instance, 'ajax_test_connection' ) );
    }

    public function add_menu() {
        add_options_page(
            __( 'Polylang Auto Translator', 'wp-polylang-translator' ),
            __( 'Polylang Translator', 'wp-polylang-translator' ),
            'manage_options',
            'wppt-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function enqueue_settings_scripts( $hook ) {
        if ( 'settings_page_wppt-settings' !== $hook ) {
            return;
        }
        wp_enqueue_script( 'wppt-settings', WPPT_URL . 'admin/js/admin.js', array( 'jquery' ), WPPT_VERSION, true );
        wp_localize_script( 'wppt-settings', 'wpptAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wppt_nonce' ),
            'strings' => array(
                'testing' => __( 'Test en cours…', 'wp-polylang-translator' ),
                'ok'      => __( 'Connexion OK', 'wp-polylang-translator' ),
                'error'   => __( 'Erreur : ', 'wp-polylang-translator' ),
            ),
        ) );
    }

    public function register_settings() {
        $fields = array(
            'primary_service'        => 'sanitize_text_field',
            'deepl_api_key'          => 'sanitize_text_field',
            'deepl_fallback'         => 'sanitize_text_field',
            'deepl_fallback_service' => 'sanitize_text_field',
            'google_api_key'         => 'sanitize_text_field',
            'libretranslate_url'     => 'esc_url_raw',
            'libretranslate_api_key' => 'sanitize_text_field',
            'untranslated_words'     => 'sanitize_textarea_field',
            'custom_translations'    => 'sanitize_textarea_field',
            'auto_translate'         => 'sanitize_text_field',
            'translate_excerpt'      => 'sanitize_text_field',
            'translate_taxonomies'   => 'sanitize_text_field',
            'relink_internal_urls'       => 'sanitize_text_field',
            'libretranslate_free_url'    => 'esc_url_raw',
        );

        foreach ( $fields as $key => $sanitize_cb ) {
            register_setting( 'wppt_settings_group', 'wppt_' . $key, array( 'sanitize_callback' => $sanitize_cb ) );
        }

        // Post types is an array.
        register_setting( 'wppt_settings_group', 'wppt_post_types', array(
            'sanitize_callback' => function( $val ) {
                if ( ! is_array( $val ) ) {
                    return array();
                }
                return array_map( 'sanitize_key', $val );
            },
        ) );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'wppt_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission refusée.' ) );
        }

        $service = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';

        switch ( $service ) {
            case 'deepl':
                $provider = new WPPT_DeepL_Provider();
                break;
            case 'google':
                $provider = new WPPT_Google_Provider();
                break;
            case 'google_free':
                $provider = new WPPT_Google_Free_Provider();
                break;
            case 'libretranslate':
                $provider = new WPPT_LibreTranslate_Provider();
                break;
            case 'libretranslate_free':
                $provider = new WPPT_LibreTranslate_Free_Provider();
                break;
            default:
                wp_send_json_error( array( 'message' => 'Service inconnu.' ) );
                return;
        }

        if ( ! $provider->is_configured() ) {
            wp_send_json_error( array( 'message' => 'Service non configuré (clé/URL manquante).' ) );
            return;
        }

        $result = $provider->translate( 'Hello', 'en', 'fr' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        } else {
            wp_send_json_success( array( 'message' => 'OK – "Hello" → "' . esc_html( $result ) . '"' ) );
        }
    }

    public function render_settings_page() {
        $primary   = get_option( 'wppt_primary_service', 'deepl' );
        $post_types_all = get_post_types( array( 'public' => true ), 'objects' );
        $selected_pts   = get_option( 'wppt_post_types', array( 'post', 'page' ) );
        if ( ! is_array( $selected_pts ) ) {
            $selected_pts = array( 'post', 'page' );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Polylang Auto Translator — Paramètres', 'wp-polylang-translator' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wppt_settings_group' ); ?>

                <!-- SERVICE SECTION -->
                <h2><?php esc_html_e( 'Service de traduction', 'wp-polylang-translator' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Service principal', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <select name="wppt_primary_service" id="wppt_primary_service">
                                <option value="deepl" <?php selected( $primary, 'deepl' ); ?>>DeepL</option>
                                <option value="google" <?php selected( $primary, 'google' ); ?>>Google Translate (API payante)</option>
                                <option value="google_free" <?php selected( $primary, 'google_free' ); ?>>Google Translate (gratuit, sans clé)</option>
                                <option value="libretranslate" <?php selected( $primary, 'libretranslate' ); ?>>LibreTranslate (instance privée / avec clé)</option>
                                <option value="libretranslate_free" <?php selected( $primary, 'libretranslate_free' ); ?>>LibreTranslate (public, sans clé)</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- DEEPL -->
                <div id="wppt-section-deepl" class="wppt-service-section">
                    <h3>DeepL</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="wppt_deepl_api_key"><?php esc_html_e( 'Clé API DeepL', 'wp-polylang-translator' ); ?></label></th>
                            <td>
                                <input type="password" name="wppt_deepl_api_key" id="wppt_deepl_api_key"
                                    value="<?php echo esc_attr( get_option( 'wppt_deepl_api_key', '' ) ); ?>"
                                    class="regular-text" autocomplete="new-password">
                                <p class="description"><?php esc_html_e( 'Clé API DeepL (format : xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx ou :fx pour les comptes gratuits).', 'wp-polylang-translator' ); ?></p>
                                <button type="button" class="button wppt-test-btn" data-service="deepl"><?php esc_html_e( 'Tester la connexion', 'wp-polylang-translator' ); ?></button>
                                <span class="wppt-test-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Fallback automatique', 'wp-polylang-translator' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wppt_deepl_fallback" value="1" id="wppt_deepl_fallback"
                                        <?php checked( '1', get_option( 'wppt_deepl_fallback', '1' ) ); ?>>
                                    <?php esc_html_e( 'Basculer vers un service de secours si le quota DeepL est dépassé ou en cas d\'erreur', 'wp-polylang-translator' ); ?>
                                </label>
                                <p class="description" style="margin-top:6px;">
                                    <?php esc_html_e( 'Si DeepL retourne une erreur de quota (code 456) en cours de traduction, le plugin reprend automatiquement avec le service de secours choisi ci-dessous. Les chunks déjà traduits par DeepL sont conservés.', 'wp-polylang-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr id="wppt-fallback-service-row">
                            <th><?php esc_html_e( 'Service de secours (fallback)', 'wp-polylang-translator' ); ?></th>
                            <td>
                                <?php $fb = get_option( 'wppt_deepl_fallback_service', 'google_free' ); ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="radio" name="wppt_deepl_fallback_service" value="google_free"
                                        <?php checked( $fb, 'google_free' ); ?>>
                                    <?php esc_html_e( 'Google Translate gratuit (sans clé API)', 'wp-polylang-translator' ); ?>
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="wppt_deepl_fallback_service" value="libretranslate"
                                        <?php checked( $fb, 'libretranslate' ); ?>>
                                    <?php esc_html_e( 'LibreTranslate (instance configurée ci-dessous)', 'wp-polylang-translator' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- GOOGLE FREE -->
                <div id="wppt-section-google_free" class="wppt-service-section">
                    <h3><?php esc_html_e( 'Google Translate gratuit (sans clé API)', 'wp-polylang-translator' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Fonctionnement', 'wp-polylang-translator' ); ?></th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e( 'Utilise l\'endpoint non officiel', 'wp-polylang-translator' ); ?>
                                    <code>https://www.google.com/async/translate</code>
                                    <?php esc_html_e( 'via une requête GET — aucune clé API requise.', 'wp-polylang-translator' ); ?>
                                </p>
                                <p class="description" style="color:#b45309;">
                                    ⚠ <?php esc_html_e( 'Endpoint non officiel : peut être instable ou bloqué par Google. Idéal pour les faibles volumes. Pour la production, préférez DeepL ou l\'API Google officielle.', 'wp-polylang-translator' ); ?>
                                </p>
                                <p class="description">
                                    <?php esc_html_e( 'Le HTML est traduit nœud par nœud (DOMDocument) : le balisage est préservé, chaque segment texte est envoyé séparément.', 'wp-polylang-translator' ); ?>
                                </p>
                                <button type="button" class="button wppt-test-btn" data-service="google_free">
                                    <?php esc_html_e( 'Tester la connexion', 'wp-polylang-translator' ); ?>
                                </button>
                                <span class="wppt-test-result"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- GOOGLE (paid API) -->
                <div id="wppt-section-google" class="wppt-service-section">
                    <h3><?php esc_html_e( 'Google Translate (API payante)', 'wp-polylang-translator' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="wppt_google_api_key"><?php esc_html_e( 'Clé API Google Cloud Translation', 'wp-polylang-translator' ); ?></label></th>
                            <td>
                                <input type="password" name="wppt_google_api_key" id="wppt_google_api_key"
                                    value="<?php echo esc_attr( get_option( 'wppt_google_api_key', '' ) ); ?>"
                                    class="regular-text" autocomplete="new-password">
                                <p class="description"><?php esc_html_e( 'Clé API pour l\'API Google Cloud Translation v2 (Basic).', 'wp-polylang-translator' ); ?></p>
                                <button type="button" class="button wppt-test-btn" data-service="google"><?php esc_html_e( 'Tester la connexion', 'wp-polylang-translator' ); ?></button>
                                <span class="wppt-test-result"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- LIBRETRANSLATE -->
                <div id="wppt-section-libretranslate" class="wppt-service-section">
                    <h3>LibreTranslate</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="wppt_libretranslate_url"><?php esc_html_e( 'URL de l\'instance LibreTranslate', 'wp-polylang-translator' ); ?></label></th>
                            <td>
                                <input type="url" name="wppt_libretranslate_url" id="wppt_libretranslate_url"
                                    value="<?php echo esc_url( get_option( 'wppt_libretranslate_url', '' ) ); ?>"
                                    class="regular-text" placeholder="https://translate.example.com">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wppt_libretranslate_api_key"><?php esc_html_e( 'Clé API (optionnelle)', 'wp-polylang-translator' ); ?></label></th>
                            <td>
                                <input type="password" name="wppt_libretranslate_api_key" id="wppt_libretranslate_api_key"
                                    value="<?php echo esc_attr( get_option( 'wppt_libretranslate_api_key', '' ) ); ?>"
                                    class="regular-text" autocomplete="new-password">
                                <button type="button" class="button wppt-test-btn" data-service="libretranslate"><?php esc_html_e( 'Tester la connexion', 'wp-polylang-translator' ); ?></button>
                                <span class="wppt-test-result"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- LIBRETRANSLATE FREE -->
                <div id="wppt-section-libretranslate_free" class="wppt-service-section">
                    <h3><?php esc_html_e( 'LibreTranslate (sans clé API)', 'wp-polylang-translator' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="wppt_libretranslate_free_url"><?php esc_html_e( 'URL de votre instance (optionnel)', 'wp-polylang-translator' ); ?></label></th>
                            <td>
                                <input type="url" name="wppt_libretranslate_free_url" id="wppt_libretranslate_free_url"
                                    value="<?php echo esc_url( get_option( 'wppt_libretranslate_free_url', '' ) ); ?>"
                                    class="regular-text" placeholder="https://mon-instance.example.com">
                                <p class="description">
                                    <?php esc_html_e( 'Si renseignée, votre instance est essayée en premier. Laissez vide pour utiliser uniquement les instances publiques.', 'wp-polylang-translator' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Instances publiques (fallback)', 'wp-polylang-translator' ); ?></th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e( 'Si votre instance n\'est pas configurée ou échoue, les instances publiques suivantes sont essayées dans l\'ordre :', 'wp-polylang-translator' ); ?>
                                </p>
                                <ul style="list-style:disc;margin-left:20px;font-family:monospace;font-size:12px;">
                                    <li>translate.terraprint.co</li>
                                    <li>lt.vern.cc</li>
                                    <li>translate.projectsegfau.lt</li>
                                    <li>libretranslate.pussthecat.org</li>
                                </ul>
                                <p class="description" style="color:#b45309;margin-top:6px;">
                                    ⚠ <?php esc_html_e( 'Les instances publiques sont tierces, sans garantie de disponibilité. Pour la production, configurez votre propre instance.', 'wp-polylang-translator' ); ?>
                                </p>
                                <button type="button" class="button wppt-test-btn" data-service="libretranslate_free" style="margin-top:8px;">
                                    <?php esc_html_e( 'Tester la connexion', 'wp-polylang-translator' ); ?>
                                </button>
                                <span class="wppt-test-result"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- OPTIONS DE TRADUCTION -->
                <h2><?php esc_html_e( 'Options de traduction', 'wp-polylang-translator' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Types de contenu', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <?php foreach ( $post_types_all as $pt ) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="wppt_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
                                        <?php checked( in_array( $pt->name, $selected_pts, true ) ); ?>>
                                    <?php echo esc_html( $pt->labels->singular_name ); ?> (<code><?php echo esc_html( $pt->name ); ?></code>)
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Traduction automatique', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wppt_auto_translate" value="1"
                                    <?php checked( '1', get_option( 'wppt_auto_translate', '1' ) ); ?>>
                                <?php esc_html_e( 'Traduire automatiquement dès la création d\'une traduction via Polylang (+)', 'wp-polylang-translator' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Traduire l\'extrait', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wppt_translate_excerpt" value="1"
                                    <?php checked( '1', get_option( 'wppt_translate_excerpt', '1' ) ); ?>>
                                <?php esc_html_e( 'Inclure l\'extrait (résumé) dans la traduction si présent', 'wp-polylang-translator' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Traduire les taxonomies', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wppt_translate_taxonomies" value="1"
                                    <?php checked( '1', get_option( 'wppt_translate_taxonomies', '1' ) ); ?>>
                                <?php esc_html_e( 'Traduire et assigner les catégories, tags et taxonomies personnalisées', 'wp-polylang-translator' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Réécrire les liens internes', 'wp-polylang-translator' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wppt_relink_internal_urls" value="1"
                                    <?php checked( '1', get_option( 'wppt_relink_internal_urls', '1' ) ); ?>>
                                <?php esc_html_e( 'Remplacer les liens internes (posts, pages, termes) par leur équivalent dans la langue cible, si la traduction existe', 'wp-polylang-translator' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Ex : un lien vers /fr/ma-page/ devient /en/my-page/ dans le contenu traduit.', 'wp-polylang-translator' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- MOTS NON TRADUITS -->
                <h2><?php esc_html_e( 'Mots à ne pas traduire', 'wp-polylang-translator' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wppt_untranslated_words"><?php esc_html_e( 'Liste (un mot/terme par ligne)', 'wp-polylang-translator' ); ?></label></th>
                        <td>
                            <textarea name="wppt_untranslated_words" id="wppt_untranslated_words"
                                rows="10" class="large-text"><?php echo esc_textarea( get_option( 'wppt_untranslated_words', '' ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Ces mots/termes ne seront pas traduits (ex : Active Directory, Docker, Kubernetes).', 'wp-polylang-translator' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- TRADUCTIONS PERSONNALISÉES -->
                <h2><?php esc_html_e( 'Traductions personnalisées', 'wp-polylang-translator' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="wppt_custom_translations"><?php esc_html_e( 'Correspondances (une par ligne)', 'wp-polylang-translator' ); ?></label></th>
                        <td>
                            <textarea name="wppt_custom_translations" id="wppt_custom_translations"
                                rows="10" class="large-text"><?php echo esc_textarea( get_option( 'wppt_custom_translations', '' ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Format : CODE:terme|CODE:traduction — ex : FR:Sommaire|EN:Table of Contents', 'wp-polylang-translator' ); ?><br>
                                <?php esc_html_e( 'Ces correspondances sont appliquées APRÈS la traduction automatique pour corriger les termes spécifiques.', 'wp-polylang-translator' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
        .wppt-service-section { background:#f9f9f9; border:1px solid #e0e0e0; padding:12px 20px; margin-bottom:16px; border-radius:4px; }
        .wppt-test-result { margin-left:10px; font-weight:600; }
        .wppt-test-result.ok    { color:#0a8a45; }
        .wppt-test-result.error { color:#c0392b; }
        </style>

        <script>
        jQuery(function($) {
            function updateServiceVisibility() {
                var selected = $('#wppt_primary_service').val();
                $('.wppt-service-section').hide();
                $('#wppt-section-' + selected).show();
                // DeepL section is always shown when DeepL is primary (fallback config lives there).
                if (selected === 'deepl') { $('#wppt-section-deepl').show(); }
                // LibreTranslate private config is also useful when free is primary (as reference).
                if (selected === 'libretranslate_free') {
                    $('#wppt-section-libretranslate_free').show();
                }
            }
            function updateFallbackRow() {
                if ($('#wppt_deepl_fallback').is(':checked')) {
                    $('#wppt-fallback-service-row').show();
                } else {
                    $('#wppt-fallback-service-row').hide();
                }
            }
            $('#wppt_primary_service').on('change', updateServiceVisibility);
            $('#wppt_deepl_fallback').on('change', updateFallbackRow);
            updateServiceVisibility();
            updateFallbackRow();
        });
        </script>
        <?php
    }
}
