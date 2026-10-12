<?php
/**
 * Handles the [rdr-dif-ban id="XX"] shortcode.
 * Outputs a skeleton placeholder; the actual banner is loaded via AJAX.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Shortcode {

    public function init(): void {
        add_shortcode( 'rdr-dif-ban', [ $this, 'render' ] );
    }

    /**
     * Render the skeleton placeholder.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( [ 'id' => '0' ], $atts, 'rdr-dif-ban' );
        $id   = absint( $atts['id'] );

        if ( ! $id ) {
            return '';
        }

        $banner = get_post( $id );
        if ( ! $banner || $banner->post_type !== RDR_BD_Post_Type::CPT || $banner->post_status !== 'publish' ) {
            return '';
        }

        // Dimensions for skeleton / layout stability
        $width  = (int) get_post_meta( $id, '_rdr_bd_width',  true ) ?: 300;
        $height = (int) get_post_meta( $id, '_rdr_bd_height', true ) ?: 250;

        $uid = 'rdr-bd-' . $id . '-' . wp_rand( 1000, 9999 );

        // Inline style for CLS prevention
        $style = sprintf(
            'width:%dpx;max-width:100%%;height:%dpx;',
            $width,
            $height
        );

        return sprintf(
            '<div id="%s" class="rdr-bd-wrapper rdr-bd-skeleton" style="%s" data-banner-id="%d" aria-label="Advertisement" role="complementary"></div>',
            esc_attr( $uid ),
            esc_attr( $style ),
            $id
        );
    }
}
