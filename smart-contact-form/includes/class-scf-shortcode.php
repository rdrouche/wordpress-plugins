<?php
defined( 'ABSPATH' ) || exit;

class SCF_Shortcode {

    public static function register(): void {
        add_shortcode( 'smart_contact_form', [ __CLASS__, 'render' ] );
        // Alias
        add_shortcode( 'contact_form', [ __CLASS__, 'render' ] );
    }

    /**
     * [smart_contact_form id="123" class="my-form" title="0"]
     */
    public static function render( array $atts ): string {
        $atts = shortcode_atts( [
            'id'    => 0,
            'class' => '',
            'title' => '1',
        ], $atts, 'smart_contact_form' );

        $form_id = (int) $atts['id'];
        if ( ! $form_id ) return '';

        // Resolve Polylang
        $form_id = SCF_Polylang::resolve_form_id( $form_id );

        $form_post = get_post( $form_id );
        if ( ! $form_post || SCF_Post_Type::POST_TYPE !== $form_post->post_type ) return '';

        $extra_class = sanitize_html_class( $atts['class'] );
        $show_title  = filter_var( $atts['title'], FILTER_VALIDATE_BOOLEAN );

        ob_start();
        ?>
        <div class="scf-wrapper <?php echo esc_attr( $extra_class ); ?>"
             id="scf-wrapper-<?php echo esc_attr( $form_id ); ?>"
             data-form-id="<?php echo esc_attr( $form_id ); ?>">

            <?php if ( $show_title ) : ?>
                <h2 class="scf-form-title"><?php echo esc_html( get_the_title( $form_id ) ); ?></h2>
            <?php endif; ?>

            <div class="scf-form-container scf-loading">
                <div class="scf-spinner" aria-label="<?php esc_attr_e( 'Loading form…', 'smart-contact-form' ); ?>">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
