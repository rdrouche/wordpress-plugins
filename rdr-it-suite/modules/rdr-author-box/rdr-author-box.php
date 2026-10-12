<?php
/**
 * Module : rdr-author-box v1.0.4
 * Photo custom : URL manuelle OU upload média WP (meta rdr_author_photo).
 * Fallback : gravatar WP natif.
 * Double affichage : guard contre l'Élément GP encore actif.
 *
 * @package RDR\Modules\AuthorBox
 */

namespace RDR\Modules\AuthorBox;

add_action( 'rdr_suite_module_loaded_rdr-author-box', __NAMESPACE__ . '\\boot' );

function boot(): void {

    // ── AJAX rdrit_save_option ────────────────────────────────────────────
    if ( ! \has_action( 'wp_ajax_rdrit_save_option' ) ) {
        \add_action( 'wp_ajax_rdrit_save_option', __NAMESPACE__ . '\\ajax_save_option' );
    }

    // ── AJAX upload photo profil ──────────────────────────────────────────
    \add_action( 'wp_ajax_rdr_author_photo_upload', __NAMESPACE__ . '\\ajax_photo_upload' );

    // ── Front CSS ─────────────────────────────────────────────────────────
    \add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_front' );

    // ── Admin CSS/JS pour l'upload ────────────────────────────────────────
    \add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_admin' );

    // ── Injection Author Box ──────────────────────────────────────────────
    $hook     = (string) \get_option( 'rdr_author_box_hook', 'generate_after_entry_content' );
    $priority = (int)    \get_option( 'rdr_author_box_priority', 10 );
    \add_action( $hook, __NAMESPACE__ . '\\maybe_render', $priority );

    // ── Meta box "Masquer" ────────────────────────────────────────────────
    \add_action( 'add_meta_boxes', __NAMESPACE__ . '\\register_meta_box' );
    \add_action( 'save_post',      __NAMESPACE__ . '\\save_meta_box', 10, 2 );

    // ── Champs profil ─────────────────────────────────────────────────────
    \add_action( 'show_user_profile',        __NAMESPACE__ . '\\profile_fields' );
    \add_action( 'edit_user_profile',        __NAMESPACE__ . '\\profile_fields' );
    \add_action( 'personal_options_update',  __NAMESPACE__ . '\\save_profile_fields' );
    \add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save_profile_fields' );
}

/* =========================================================================
   AJAX — save option générique
   ========================================================================= */
function ajax_save_option(): void {
    if ( ! isset( $_POST['nonce'] )
        || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ), 'rdrit_nonce' )
    ) {
        \wp_send_json_error( [ 'message' => 'invalid_nonce' ] );
    }
    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_send_json_error( [ 'message' => 'forbidden' ] );
    }

    $key = isset( $_POST['option_key'] ) ? \sanitize_key( \wp_unslash( $_POST['option_key'] ) ) : '';
    if ( ! $key || strpos( $key, 'rdr_author_box_' ) !== 0 ) {
        \wp_send_json_error( [ 'message' => 'forbidden_key', 'key' => $key ] );
    }

    $raw     = isset( $_POST['value'] ) ? \wp_unslash( $_POST['value'] ) : '';
    $decoded = json_decode( $raw, true );
    $value   = ( JSON_ERROR_NONE === json_last_error() && null !== $decoded )
               ? $decoded : \sanitize_text_field( $raw );

    \update_option( $key, $value, false );
    \wp_send_json_success( [ 'key' => $key ] );
}

/* =========================================================================
   AJAX — upload photo profil
   ========================================================================= */
function ajax_photo_upload(): void {
    \check_ajax_referer( 'rdr_author_photo_nonce', 'nonce' );

    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( ! $user_id || ! \current_user_can( 'edit_user', $user_id ) ) {
        \wp_send_json_error( [ 'message' => 'forbidden' ] );
    }
    if ( empty( $_FILES['photo'] ) ) {
        \wp_send_json_error( [ 'message' => 'no_file' ] );
    }

    // Utiliser la médiathèque WP
    if ( ! function_exists( 'media_handle_upload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    $attachment_id = \media_handle_upload( 'photo', 0 );
    if ( \is_wp_error( $attachment_id ) ) {
        \wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
    }

    $url = \wp_get_attachment_url( $attachment_id );
    \update_user_meta( $user_id, 'rdr_author_photo_id',  $attachment_id );
    \update_user_meta( $user_id, 'rdr_author_photo_url', $url );

    \wp_send_json_success( [ 'url' => $url, 'attachment_id' => $attachment_id ] );
}

/* =========================================================================
   ENQUEUE
   ========================================================================= */
function enqueue_front(): void {
    if ( ! \is_singular() ) return;
    \wp_enqueue_style(
        'rdr-author-box',
        \RDR_SUITE_URL . 'modules/rdr-author-box/assets/author-box.css',
        [], '1.0.4'
    );
}

function enqueue_admin( string $hook ): void {
    // Charger sur les pages profil uniquement
    if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) return;
    \wp_enqueue_media(); // médiathèque WP
}

/* =========================================================================
   FRONT — rendu
   ========================================================================= */
function maybe_render(): void {
    $post_types = \get_option( 'rdr_author_box_post_types', [ 'post' ] );
    if ( ! is_array( $post_types ) || empty( $post_types ) ) $post_types = [ 'post' ];
    if ( ! \is_singular( $post_types ) ) return;

    $post_id = \get_the_ID();
    if ( $post_id && \get_post_meta( $post_id, '_rdr_author_box_hide', true ) ) return;

    render_author_box();
}

function render_author_box(): void {
    $author_id = (int) \get_the_author_meta( 'ID' );
    if ( ! $author_id ) return;

    $display_name = (string) \get_user_meta( $author_id, 'rdr_author_display_name', true );
    if ( ! $display_name ) {
        $display_name = (string) \get_the_author_meta( 'display_name', $author_id );
    }
    $bio          = \get_the_author_meta( 'description',  $author_id );
    $avatar_size  = max( 60, (int) \get_option( 'rdr_author_box_avatar_size', 100 ) );
    $avatar_url   = get_author_photo_url( $author_id, $avatar_size );
    $title        = get_author_title( $author_id );
    $social_links = get_social_links( $author_id );
    ?>
    <div class="rdr-author-box" itemscope itemtype="https://schema.org/Person">

        <?php if ( $avatar_url ) : ?>
        <div class="rdr-author-box__avatar">
            <img
                src="<?php echo \esc_url( $avatar_url ); ?>"
                alt="<?php echo \esc_attr( $display_name ); ?>"
                width="<?php echo (int) $avatar_size; ?>"
                height="<?php echo (int) $avatar_size; ?>"
                loading="lazy"
                itemprop="image"
            />
        </div>
        <?php endif; ?>

        <div class="rdr-author-box__content">
            <div class="rdr-author-box__name" itemprop="name"><?php echo \esc_html( $display_name ); ?></div>

            <?php if ( $title ) : ?>
            <div class="rdr-author-box__title" itemprop="jobTitle"><?php echo \esc_html( $title ); ?></div>
            <?php endif; ?>

            <?php if ( $bio ) : ?>
            <div class="rdr-author-box__bio" itemprop="description"><?php echo \wp_kses_post( $bio ); ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $social_links ) ) : ?>
            <div class="rdr-author-box__social" aria-label="Réseaux sociaux">
                <?php foreach ( $social_links as $link ) : ?>
                <a
                    href="<?php echo \esc_url( $link['url'] ); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rdr-author-box__social-link"
                    aria-label="<?php echo \esc_attr( $link['label'] ); ?>"
                    title="<?php echo \esc_attr( $link['label'] ); ?>"
                ><?php echo get_social_icon( $link['key'] ); // phpcs:ignore ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/* =========================================================================
   META BOX — Masquer au cas par cas
   ========================================================================= */
function register_meta_box(): void {
    $post_types = \get_option( 'rdr_author_box_post_types', [ 'post' ] );
    if ( ! is_array( $post_types ) ) $post_types = [ 'post' ];
    foreach ( $post_types as $pt ) {
        \add_meta_box( 'rdr-author-box-hide', 'Author Box',
            __NAMESPACE__ . '\\render_meta_box', $pt, 'side', 'default' );
    }
}

function render_meta_box( \WP_Post $post ): void {
    \wp_nonce_field( 'rdr_author_box_meta', 'rdr_author_box_meta_nonce' );
    $hidden = (bool) \get_post_meta( $post->ID, '_rdr_author_box_hide', true );
    ?>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:4px 0;font-size:13px;">
        <input type="checkbox" name="rdr_author_box_hide" value="1" <?php \checked( $hidden ); ?> />
        Masquer l'Author Box sur cet article
    </label>
    <?php
}

function save_meta_box( int $post_id, \WP_Post $post ): void {
    if ( ! isset( $_POST['rdr_author_box_meta_nonce'] ) ) return;
    if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['rdr_author_box_meta_nonce'] ) ), 'rdr_author_box_meta' ) ) return;
    if ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! \current_user_can( 'edit_post', $post_id ) ) return;
    if ( 'revision' === $post->post_type ) return;

    if ( ! empty( $_POST['rdr_author_box_hide'] ) ) {
        \update_post_meta( $post_id, '_rdr_author_box_hide', '1' );
    } else {
        \delete_post_meta( $post_id, '_rdr_author_box_hide' );
    }
}

/* =========================================================================
   HELPERS
   ========================================================================= */

/**
 * Retourne l'URL de la photo de l'auteur.
 * Priorité : 1. photo uploadée (meta rdr_author_photo_url)
 *            2. URL manuelle (meta rdr_author_photo_url_manual)
 *            3. gravatar WP natif
 */
function get_author_photo_url( int $author_id, int $size = 100 ): string {
    // 1. Photo uploadée via médiathèque
    $uploaded = (string) \get_user_meta( $author_id, 'rdr_author_photo_url', true );
    if ( $uploaded ) return $uploaded;

    // 2. URL manuelle
    $manual = (string) \get_user_meta( $author_id, 'rdr_author_photo_url_manual', true );
    if ( $manual ) return $manual;

    // 3. Gravatar WP
    return (string) \get_avatar_url( $author_id, [ 'size' => $size ] );
}

function get_author_title( int $author_id ): string {
    $lang = '';
    if ( \function_exists( 'pll_current_language' ) ) {
        $lang = '_' . \pll_current_language();
    }
    $title = $lang ? (string) \get_user_meta( $author_id, 'rdr_author_title' . $lang, true ) : '';
    if ( ! $title ) $title = (string) \get_user_meta( $author_id, 'rdr_author_title', true );
    return $title;
}

function get_social_links( int $author_id ): array {
    $config = \get_option( 'rdr_author_box_social_networks', get_default_social_networks() );
    if ( ! is_array( $config ) ) $config = get_default_social_networks();
    $links = [];
    foreach ( $config as $net ) {
        if ( empty( $net['key'] ) || empty( $net['enabled'] ) ) continue;
        $url = (string) \get_user_meta( $author_id, 'rdr_social_' . $net['key'], true );
        if ( ! $url && $net['key'] === 'website' ) {
            $url = (string) \get_the_author_meta( 'user_url', $author_id );
        }
        if ( $url ) {
            $links[] = [ 'key' => $net['key'], 'label' => $net['label'] ?? $net['key'], 'url' => $url ];
        }
    }
    return $links;
}

function get_default_social_networks(): array {
    return [
        [ 'key' => 'linkedin',  'label' => 'LinkedIn',  'enabled' => true ],
        [ 'key' => 'youtube',   'label' => 'YouTube',   'enabled' => true ],
        [ 'key' => 'github',    'label' => 'GitHub',    'enabled' => true ],
        [ 'key' => 'credly',    'label' => 'Credly',    'enabled' => true ],
        [ 'key' => 'twitter',   'label' => 'Twitter/X', 'enabled' => false ],
        [ 'key' => 'mastodon',  'label' => 'Mastodon',  'enabled' => false ],
        [ 'key' => 'instagram', 'label' => 'Instagram', 'enabled' => false ],
        [ 'key' => 'website',   'label' => 'Site web',  'enabled' => false ],
    ];
}

function get_social_icon( string $key ): string {
    static $icons = null;
    if ( null === $icons ) {
        $icons = [
            'linkedin'  => '<svg aria-hidden="true" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M100.28 448H7.4V148.9h92.88zM53.79 108.1C24.09 108.1 0 83.5 0 53.8a53.79 53.79 0 0 1 107.58 0c0 29.7-24.1 54.3-53.79 54.3zM447.9 448h-92.68V302.4c0-34.7-.7-79.2-48.29-79.2-48.29 0-55.69 37.7-55.69 76.7V448h-92.78V148.9h89.08v40.8h1.3c12.4-23.5 42.69-48.3 87.88-48.3 94 0 111.28 61.9 111.28 142.3V448z"/></svg>',
            'youtube'   => '<svg aria-hidden="true" viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"/></svg>',
            'github'    => '<svg aria-hidden="true" viewBox="0 0 640 640" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M237.9 461.4C237.9 463.4 235.6 465 232.7 465C229.4 465.3 227.1 463.7 227.1 461.4C227.1 459.4 229.4 457.8 232.3 457.8C235.3 457.5 237.9 459.1 237.9 461.4zM206.8 456.9C206.1 458.9 208.1 461.2 211.1 461.8C213.7 462.8 216.7 461.8 217.3 459.8C217.9 457.8 216 455.5 213 454.6C210.4 453.9 207.5 454.9 206.8 456.9zM251 455.2C248.1 455.9 246.1 457.8 246.4 460.1C246.7 462.1 249.3 463.4 252.3 462.7C255.2 462 257.2 460.1 256.9 458.1C256.6 456.2 253.9 454.9 251 455.2zM316.8 72C178.1 72 72 177.3 72 316C72 426.9 141.8 521.8 241.5 555.2C254.3 557.5 258.8 549.6 258.8 543.1C258.8 536.9 258.5 502.7 258.5 481.7C258.5 481.7 188.5 496.7 173.8 451.9C173.8 451.9 162.4 422.8 146 415.3C146 415.3 123.1 399.6 147.6 399.9C147.6 399.9 172.5 401.9 186.2 425.7C208.1 464.3 244.8 453.2 259.1 446.6C261.4 430.6 267.9 419.5 275.1 412.9C219.2 406.7 162.8 398.6 162.8 302.4C162.8 274.9 170.4 261.1 186.4 243.5C183.8 237 175.3 210.2 189 175.6C209.9 169.1 258 202.6 258 202.6C278 197 299.5 194.1 320.8 194.1C342.1 194.1 363.6 197 383.6 202.6C383.6 202.6 431.7 169 452.6 175.6C466.3 210.3 457.8 237 455.2 243.5C471.2 261.2 481 275 481 302.4C481 398.9 422.1 406.6 366.2 412.9C375.4 420.8 383.2 435.8 383.2 459.3C383.2 493 382.9 534.7 382.9 542.9C382.9 549.4 387.5 557.3 400.2 555C500.2 521.8 568 426.9 568 316C568 177.3 455.5 72 316.8 72z"/></svg>',
            'twitter'   => '<svg aria-hidden="true" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/></svg>',
            'website'   => '<svg aria-hidden="true" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M352 256c0 22.2-1.2 43.6-3.3 64H163.3c-2.2-20.4-3.3-41.8-3.3-64s1.2-43.6 3.3-64H348.7c2.2 20.4 3.3 41.8 3.3 64zm28.8-64H503.9c5.3 20.5 8.1 41.9 8.1 64s-2.8 43.5-8.1 64H380.8c2.1-20.6 3.2-42 3.2-64s-1.1-43.4-3.2-64zm112.6-32H376.7c-10-63.9-29.8-117.4-55.3-151.6c78.3 20.7 142 77.5 171.9 151.6zm-149.1 0H167.7c6.1-36.4 15.5-68.6 27-94.7c10.5-23.6 22.2-40.7 33.5-51.5C239.4 3.2 248.7 0 256 0s16.6 3.2 27.8 13.8c11.3 10.8 23 27.9 33.5 51.5c11.5 26 20.9 58.2 27 94.7zm-209 0H18.6C48.6 85.9 112.2 29.1 190.6 8.4C165.1 42.6 145.3 96.1 135.3 160zM8.1 192H131.2c-2.1 20.6-3.2 42-3.2 64s1.1 43.4 3.2 64H8.1C2.8 299.5 0 278.1 0 256s2.8-43.5 8.1-64zM194.7 446.6c-11.5-26-20.9-58.2-27-94.6H344.3c-6.1 36.4-15.5 68.6-27 94.6c-10.5 23.6-22.2 40.7-33.5 51.5C272.6 508.8 263.3 512 256 512s-16.6-3.2-27.8-13.8c-11.3-10.8-23-27.9-33.5-51.5zM135.3 352c10 63.9 29.8 117.4 55.3 151.6C112.2 482.9 48.6 426.1 18.6 352H135.3zm358.1 0c-30 74.1-93.6 130.9-171.9 151.6c25.5-34.2 45.2-87.7 55.3-151.6H493.4z"/></svg>',
            'mastodon'  => '<svg aria-hidden="true" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M433 179.1c0-97.2-63.7-125.7-63.7-125.7-62.5-28.7-228.5-28.4-290.4 0 0 0-63.7 28.5-63.7 125.7 0 115.7-6.6 259.4 105.6 289.1 40.5 10.7 75.3 13 103.3 11.4 50.8-2.8 79.3-18.1 79.3-18.1l-1.7-36.9s-36.3 11.4-77.1 10.1c-40.4-1.4-83-4.4-89.6-54a102.5 102.5 0 0 1-.9-13.9c85.6 20.9 158.7 9.1 178.8 6.7 56.1-6.7 105-41.3 111.2-72.9 9.8-49.8 9-121.5 9-121.5zm-75.1 125.2h-46.6v-114.2c0-49.7-64-51.6-64 6.9v62.5h-46.3V197c0-58.5-64-56.6-64-6.9v114.2H90.2c0-122.1-5.2-147.9 18.4-175 25.9-28.9 79.8-30.8 103.8 6.1l11.6 19.5 11.6-19.5c24.1-37.1 78.1-34.8 103.8-6.1 23.7 27.3 18.4 53 18.4 175z"/></svg>',
            'instagram' => '<svg aria-hidden="true" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>',
            'credly'    => '<svg aria-hidden="true" viewBox="0 0 640 640" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M271.2 56C265.1 49.8 256.2 47.3 247.8 49.6C239.4 51.9 232.9 58.4 230.8 66.8L215.5 127C214.4 131.4 209.9 134 205.6 132.7L145.8 115.9C137.4 113.5 128.4 115.9 122.3 122C116.2 128.1 113.8 137.1 116.2 145.5L133.1 205.3C134.3 209.6 131.7 214.1 127.4 215.2L67.1 230.5C58.7 232.6 52.1 239.2 49.8 247.6C47.5 256 50 264.9 56.2 271L100.7 314.3C103.9 317.4 103.9 322.6 100.7 325.8L56.3 369.1C50.1 375.2 47.6 384.1 49.9 392.5C52.2 400.9 58.8 407.4 67.2 409.6L127.4 424.9C131.8 426 134.4 430.5 133.1 434.8L116.2 494.5C113.8 502.9 116.2 511.9 122.3 518C128.4 524.1 137.4 526.5 145.8 524.1L205.6 507.2C209.9 506 214.4 508.6 215.5 512.9L230.8 573.1C232.9 581.5 239.5 588.1 247.9 590.4C256.3 592.7 265.2 590.2 271.3 584L314.6 539.5C317.7 536.3 322.9 536.3 326.1 539.5L369.3 584C375.4 590.2 384.3 592.7 392.7 590.4C401.1 588.1 407.6 581.5 409.8 573.1L425.1 513C426.2 508.6 430.7 506 435 507.3L494.8 524.2C503.2 526.6 512.2 524.2 518.3 518.1C524.4 512 526.8 503 524.4 494.6L507.5 434.8C506.3 430.5 508.9 426 513.2 424.9L573.4 409.6C581.8 407.5 588.4 400.9 590.7 392.5C593 384.1 590.5 375.1 584.3 369.1L539.8 325.8C536.6 322.7 536.6 317.5 539.8 314.3L584.3 271C590.5 264.9 593 256 590.7 247.6C588.4 239.2 581.8 232.7 573.4 230.5L513.2 215.2C508.8 214.1 506.2 209.6 507.5 205.3L524.4 145.5C526.8 137.1 524.4 128.1 518.3 122C512.2 115.9 503.2 113.5 494.8 115.9L435 132.8C430.7 134 426.2 131.4 425.1 127.1L409.8 66.8C407.7 58.4 401.1 51.8 392.7 49.5C384.3 47.2 375.4 49.7 369.3 55.9L326 100.5C322.9 103.7 317.7 103.7 314.5 100.5L271.2 56z"/></svg>',
        ];
    }
    return $icons[ $key ] ?? '<svg aria-hidden="true" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><circle fill="currentColor" cx="256" cy="256" r="200"/></svg>';
}

/* =========================================================================
   CHAMPS PROFIL UTILISATEUR
   ========================================================================= */
function profile_fields( \WP_User $user ): void {
    $social_config = \get_option( 'rdr_author_box_social_networks', get_default_social_networks() );
    if ( ! is_array( $social_config ) ) $social_config = get_default_social_networks();

    $languages = [];
    if ( \function_exists( 'pll_languages_list' ) ) {
        foreach ( \pll_languages_list( [ 'fields' => false ] ) as $lang ) {
            $languages[] = [ 'slug' => $lang->slug, 'name' => $lang->name ];
        }
    }

    // Photo actuelle
    $photo_url    = get_author_photo_url( $user->ID, 120 );
    $photo_manual = (string) \get_user_meta( $user->ID, 'rdr_author_photo_url_manual', true );
    $photo_att_id = (int)    \get_user_meta( $user->ID, 'rdr_author_photo_id', true );
    $upload_nonce = \wp_create_nonce( 'rdr_author_photo_nonce' );
    ?>
    <h2>Author Box — RDR-IT Suite</h2>
    <table class="form-table" role="presentation">

        <!-- ── Photo de profil ─────────────────────────────────────── -->
        <tr>
            <th>Photo de l'auteur</th>
            <td>
                <div id="rdr-photo-wrap" style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">

                    <!-- Aperçu -->
                    <div>
                        <img id="rdr-photo-preview"
                             src="<?php echo \esc_url( $photo_url ); ?>"
                             alt="Aperçu photo"
                             style="width:100px;height:100px;object-fit:cover;border-radius:50%;border:2px solid #ddd;display:block;" />
                        <?php if ( $photo_att_id ) : ?>
                        <button type="button" id="rdr-photo-remove"
                                style="margin-top:6px;font-size:11px;color:#e53e3e;background:none;border:none;cursor:pointer;padding:0;"
                                data-user-id="<?php echo $user->ID; ?>">
                            ✕ Supprimer la photo
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;flex-direction:column;gap:10px;">

                        <!-- Upload fichier -->
                        <div>
                            <label style="font-weight:600;display:block;margin-bottom:4px;">Uploader une photo</label>
                            <input type="file" id="rdr-photo-file" accept="image/*"
                                   style="font-size:13px;" />
                            <button type="button" id="rdr-photo-upload-btn" class="button"
                                    data-user-id="<?php echo $user->ID; ?>"
                                    data-nonce="<?php echo \esc_attr( $upload_nonce ); ?>"
                                    style="margin-top:6px;display:block;">
                                Uploader
                            </button>
                            <span id="rdr-upload-status" style="font-size:12px;margin-top:4px;display:block;"></span>
                        </div>

                        <!-- Médiathèque WP -->
                        <div>
                            <label style="font-weight:600;display:block;margin-bottom:4px;">Choisir dans la médiathèque</label>
                            <button type="button" id="rdr-photo-media-btn" class="button"
                                    data-user-id="<?php echo $user->ID; ?>">
                                Ouvrir la médiathèque
                            </button>
                            <input type="hidden" id="rdr-photo-att-id" name="rdr_author_photo_id"
                                   value="<?php echo \esc_attr( $photo_att_id ); ?>" />
                            <input type="hidden" id="rdr-photo-att-url" name="rdr_author_photo_url"
                                   value="<?php echo \esc_attr( \get_user_meta( $user->ID, 'rdr_author_photo_url', true ) ); ?>" />
                        </div>

                        <!-- URL manuelle -->
                        <div>
                            <label for="rdr_author_photo_url_manual"
                                   style="font-weight:600;display:block;margin-bottom:4px;">
                                Ou coller une URL externe
                            </label>
                            <input type="url" id="rdr_author_photo_url_manual"
                                   name="rdr_author_photo_url_manual"
                                   value="<?php echo \esc_attr( $photo_manual ); ?>"
                                   class="regular-text" placeholder="https://…" />
                            <p class="description" style="margin-top:4px;">
                                Utilisée uniquement si aucune photo n'est uploadée.
                            </p>
                        </div>
                    </div>
                </div>
            </td>
        </tr>

        <!-- ── Nom affiché ─────────────────────────────────────────── -->
        <tr>
            <th><label for="rdr_display_name">Nom affiché</label></th>
            <td>
                <input type="text" name="rdr_display_name" id="rdr_display_name"
                       value="<?php echo \esc_attr( \get_user_meta( $user->ID, 'rdr_author_display_name', true ) ); ?>"
                       placeholder="<?php echo \esc_attr( $user->display_name ); ?>"
                       class="regular-text" />
                <p class="description">
                    Nom affiché dans l'Author Box uniquement — indépendant du profil WP.<br>
                    Laisser vide pour utiliser le nom WP par défaut&nbsp;:
                    <em><?php echo \esc_html( $user->display_name ); ?></em>.
                </p>
            </td>
        </tr>

        <!-- ── Titre / Poste ────────────────────────────────────────── -->
        <tr>
            <th><label>Titre / Poste</label></th>
            <td>
                <?php if ( empty( $languages ) ) : ?>
                    <input type="text" name="rdr_author_title"
                           value="<?php echo \esc_attr( \get_user_meta( $user->ID, 'rdr_author_title', true ) ); ?>"
                           class="regular-text" />
                <?php else : ?>
                    <?php foreach ( $languages as $lang ) :
                        $mk = 'rdr_author_title_' . $lang['slug']; ?>
                    <label style="display:block;margin-bottom:6px;">
                        <strong><?php echo \esc_html( $lang['name'] ); ?></strong><br>
                        <input type="text" name="<?php echo \esc_attr( $mk ); ?>"
                               value="<?php echo \esc_attr( \get_user_meta( $user->ID, $mk, true ) ); ?>"
                               class="regular-text" />
                    </label>
                    <?php endforeach; ?>
                    <label style="display:block;margin-top:6px;color:#666;">
                        <em>Fallback (toutes langues)</em><br>
                        <input type="text" name="rdr_author_title"
                               value="<?php echo \esc_attr( \get_user_meta( $user->ID, 'rdr_author_title', true ) ); ?>"
                               class="regular-text" />
                    </label>
                <?php endif; ?>
            </td>
        </tr>

        <!-- ── Réseaux sociaux ──────────────────────────────────────── -->
        <?php
        $enabled_nets = array_filter( $social_config, static fn( $n ) => ! empty( $n['enabled'] ) );
        foreach ( $enabled_nets as $net ) :
            $mk  = 'rdr_social_' . $net['key'];
            $val = \get_user_meta( $user->ID, $mk, true );
        ?>
        <tr>
            <th><label for="<?php echo \esc_attr( $mk ); ?>"><?php echo \esc_html( $net['label'] ?? $net['key'] ); ?></label></th>
            <td>
                <input type="url" name="<?php echo \esc_attr( $mk ); ?>"
                       id="<?php echo \esc_attr( $mk ); ?>"
                       value="<?php echo \esc_attr( $val ); ?>"
                       class="regular-text" placeholder="https://" />
            </td>
        </tr>
        <?php endforeach; ?>

    </table>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var ajax      = (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
        var preview   = document.getElementById('rdr-photo-preview');
        var fileInput = document.getElementById('rdr-photo-file');
        var uploadBtn = document.getElementById('rdr-photo-upload-btn');
        var status    = document.getElementById('rdr-upload-status');
        var mediaBtn  = document.getElementById('rdr-photo-media-btn');
        var attId     = document.getElementById('rdr-photo-att-id');
        var attUrl    = document.getElementById('rdr-photo-att-url');
        var removeBtn = document.getElementById('rdr-photo-remove');

        /* Prévisualisation locale avant upload */
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (!this.files || !this.files[0]) return;
                var reader = new FileReader();
                reader.onload = function (e) {
                    if (preview) preview.src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            });
        }

        /* Upload via AJAX */
        if (uploadBtn) {
            uploadBtn.addEventListener('click', function () {
                if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                    status.textContent = 'Sélectionnez d\'abord un fichier.';
                    return;
                }
                status.textContent = 'Upload en cours…';
                status.style.color = '#666';

                var fd = new FormData();
                fd.append('action',  'rdr_author_photo_upload');
                fd.append('nonce',   uploadBtn.dataset.nonce);
                fd.append('user_id', uploadBtn.dataset.userId);
                fd.append('photo',   fileInput.files[0]);

                fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) {
                            status.textContent = '✓ Photo enregistrée.';
                            status.style.color = '#00b894';
                            if (preview) preview.src = d.data.url;
                            if (attId)  attId.value  = d.data.attachment_id;
                            if (attUrl) attUrl.value = d.data.url;
                        } else {
                            status.textContent = '✗ ' + (d && d.data ? d.data.message : 'Erreur.');
                            status.style.color = '#e53e3e';
                        }
                    })
                    .catch(function () {
                        status.textContent = '✗ Erreur réseau.';
                        status.style.color = '#e53e3e';
                    });
            });
        }

        /* Médiathèque WP */
        if (mediaBtn && window.wp && window.wp.media) {
            var frame;
            mediaBtn.addEventListener('click', function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title:    'Choisir une photo de profil',
                    button:   { text: 'Utiliser cette photo' },
                    multiple: false,
                    library:  { type: 'image' },
                });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    if (preview) preview.src = url;
                    if (attId)  attId.value  = att.id;
                    if (attUrl) attUrl.value = att.url;
                    /* Sauvegarder en base via AJAX rdrit */
                    var body = new URLSearchParams({
                        action:     'rdrit_save_option',
                        nonce:      '<?php echo \esc_js( \wp_create_nonce( 'rdrit_nonce' ) ); ?>',
                        option_key: 'rdr_author_photo_user_<?php echo $user->ID; ?>',
                        value:      JSON.stringify({ id: att.id, url: att.url }),
                    });
                    /* On stocke via user_meta directement — fetch custom action */
                    var fd2 = new FormData();
                    fd2.append('action',        'rdr_author_photo_from_media');
                    fd2.append('nonce',         '<?php echo \esc_js( \wp_create_nonce( 'rdr_author_photo_nonce' ) ); ?>');
                    fd2.append('user_id',       '<?php echo $user->ID; ?>');
                    fd2.append('attachment_id', att.id);
                    fd2.append('url',           att.url);
                    fetch(ajax, { method: 'POST', body: fd2, credentials: 'same-origin' });
                });
                frame.open();
            });
        }

        /* Supprimer la photo */
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (!confirm('Supprimer la photo ? Le gravatar sera utilisé à la place.')) return;
                var fd = new FormData();
                fd.append('action',  'rdr_author_photo_remove');
                fd.append('nonce',   '<?php echo \esc_js( \wp_create_nonce( 'rdr_author_photo_nonce' ) ); ?>');
                fd.append('user_id', '<?php echo $user->ID; ?>');
                fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) {
                            if (preview) preview.src = d.data.gravatar;
                            if (attId)  attId.value  = '';
                            if (attUrl) attUrl.value = '';
                            removeBtn.style.display = 'none';
                        }
                    });
            });
        }
    });
    </script>
    <?php
}

function save_profile_fields( int $user_id ): void {
    if ( ! \current_user_can( 'edit_user', $user_id ) ) return;

    // Nom affiché Author Box (stocké en meta dédiée — NE MODIFIE PAS le display_name WP)
    if ( isset( $_POST['rdr_display_name'] ) ) {
        $rdr_name = \sanitize_text_field( \wp_unslash( $_POST['rdr_display_name'] ) );
        if ( $rdr_name ) {
            \update_user_meta( $user_id, 'rdr_author_display_name', $rdr_name );
        } else {
            // Champ vide = utiliser le display_name WP natif (supprimer la meta)
            \delete_user_meta( $user_id, 'rdr_author_display_name' );
        }
    }

    // Photo médiathèque (champ hidden mis à jour par JS)
    if ( isset( $_POST['rdr_author_photo_id'] ) ) {
        $att_id = (int) $_POST['rdr_author_photo_id'];
        if ( $att_id ) {
            \update_user_meta( $user_id, 'rdr_author_photo_id',  $att_id );
            \update_user_meta( $user_id, 'rdr_author_photo_url', \wp_get_attachment_url( $att_id ) );
        } else {
            \delete_user_meta( $user_id, 'rdr_author_photo_id' );
        }
    }

    // URL manuelle
    if ( isset( $_POST['rdr_author_photo_url_manual'] ) ) {
        $manual = \esc_url_raw( \wp_unslash( $_POST['rdr_author_photo_url_manual'] ) );
        if ( $manual ) {
            \update_user_meta( $user_id, 'rdr_author_photo_url_manual', $manual );
        } else {
            \delete_user_meta( $user_id, 'rdr_author_photo_url_manual' );
        }
    }

    // Titre
    if ( isset( $_POST['rdr_author_title'] ) ) {
        \update_user_meta( $user_id, 'rdr_author_title', \sanitize_text_field( \wp_unslash( $_POST['rdr_author_title'] ) ) );
    }

    // Titres Polylang
    if ( \function_exists( 'pll_languages_list' ) ) {
        foreach ( \pll_languages_list() as $slug ) {
            $mk = 'rdr_author_title_' . $slug;
            if ( isset( $_POST[ $mk ] ) ) {
                \update_user_meta( $user_id, $mk, \sanitize_text_field( \wp_unslash( $_POST[ $mk ] ) ) );
            }
        }
    }

    // Réseaux sociaux
    $social_config = \get_option( 'rdr_author_box_social_networks', get_default_social_networks() );
    if ( is_array( $social_config ) ) {
        foreach ( $social_config as $net ) {
            if ( empty( $net['key'] ) ) continue;
            $mk = 'rdr_social_' . $net['key'];
            if ( isset( $_POST[ $mk ] ) ) {
                \update_user_meta( $user_id, $mk, \esc_url_raw( \wp_unslash( $_POST[ $mk ] ) ) );
            }
        }
    }
}

// ── AJAX : photo depuis médiathèque (appelé par le frame WP media) ────────
\add_action( 'wp_ajax_rdr_author_photo_from_media', static function (): void {
    \check_ajax_referer( 'rdr_author_photo_nonce', 'nonce' );
    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( ! $user_id || ! \current_user_can( 'edit_user', $user_id ) ) {
        \wp_send_json_error();
    }
    $att_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
    $url    = isset( $_POST['url'] ) ? \esc_url_raw( \wp_unslash( $_POST['url'] ) ) : '';
    if ( $att_id && $url ) {
        \update_user_meta( $user_id, 'rdr_author_photo_id',  $att_id );
        \update_user_meta( $user_id, 'rdr_author_photo_url', $url );
    }
    \wp_send_json_success();
} );

// ── AJAX : supprimer la photo ─────────────────────────────────────────────
\add_action( 'wp_ajax_rdr_author_photo_remove', static function (): void {
    \check_ajax_referer( 'rdr_author_photo_nonce', 'nonce' );
    $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
    if ( ! $user_id || ! \current_user_can( 'edit_user', $user_id ) ) {
        \wp_send_json_error();
    }
    \delete_user_meta( $user_id, 'rdr_author_photo_id' );
    \delete_user_meta( $user_id, 'rdr_author_photo_url' );
    $gravatar = \get_avatar_url( $user_id, [ 'size' => 120 ] );
    \wp_send_json_success( [ 'gravatar' => $gravatar ] );
} );
