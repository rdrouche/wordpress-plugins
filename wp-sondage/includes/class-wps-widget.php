<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Widget WordPress natif — permet d'ajouter un sondage dans n'importe quelle sidebar.
 */
class WPS_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'wps_poll_widget',
            __( 'WP Sondage', 'wp-sondage' ),
            [
                'description' => __( 'Affiche un sondage dans la sidebar.', 'wp-sondage' ),
                'classname'   => 'widget_wps_poll',
            ]
        );
    }

    /** Front-end */
    public function widget( $args, $instance ) {
        // Pas de détection bot au rendu (empoisonnerait le cache de page).
        // Le filtrage se fait côté AJAX.
        $poll_id = (int) ( $instance['poll_id'] ?? 0 );
        $lazy    = ! empty( $instance['lazy'] );

        if ( ! $poll_id ) return;

        $post = get_post( $poll_id );
        if ( ! $post || $post->post_type !== 'wps_poll' || $post->post_status !== 'publish' ) return;
        if ( get_post_meta( $poll_id, '_wps_active', true ) !== '1' ) return;

        echo $args['before_widget'];

        $title = apply_filters( 'widget_title', $instance['title'] ?? '', $instance, $this->id_base );
        if ( $title ) echo $args['before_title'] . esc_html( $title ) . $args['after_title'];

        if ( $lazy ) {
            // Aucun nonce ici : il serait périmé une fois la page en cache.
            printf(
                '<div class="wps-lazy-widget wps-theme-auto" data-poll-id="%d" role="status" aria-busy="true" aria-label="%s">
                    <div class="wps-skeleton-inner" aria-hidden="true">
                        <div class="wps-skeleton-line wps-skeleton-line--title"></div>
                        <div class="wps-skeleton-line wps-skeleton-line--full"></div>
                        <div class="wps-skeleton-line wps-skeleton-line--med"></div>
                        <div class="wps-skeleton-line wps-skeleton-line--full"></div>
                        <div class="wps-skeleton-line wps-skeleton-line--short"></div>
                        <div class="wps-skeleton-btn"></div>
                    </div>
                </div>',
                $poll_id,
                esc_attr__( 'Chargement du sondage…', 'wp-sondage' )
            );
        } else {
            // Rendu serveur avec état personnel → page non cacheable
            WPS_Cache::set_donotcache_constants();
            WPS_Public::render_poll_widget( $poll_id );
        }

        echo $args['after_widget'];
    }

    /** Back-end (formulaire admin) */
    public function form( $instance ) {
        $title   = $instance['title']   ?? '';
        $poll_id = $instance['poll_id'] ?? '';
        $lazy    = ! empty( $instance['lazy'] );

        // Liste des sondages actifs
        $polls = get_posts( [
            'post_type'      => 'wps_poll',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [ [ 'key' => '_wps_active', 'value' => '1' ] ],
        ] );
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Titre (optionnel)', 'wp-sondage' ); ?></label>
            <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>"
                   name="<?php echo $this->get_field_name( 'title' ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'poll_id' ); ?>"><?php _e( 'Sondage', 'wp-sondage' ); ?></label>
            <select class="widefat" id="<?php echo $this->get_field_id( 'poll_id' ); ?>"
                    name="<?php echo $this->get_field_name( 'poll_id' ); ?>">
                <option value=""><?php _e( '— Choisir —', 'wp-sondage' ); ?></option>
                <?php foreach ( $polls as $p ) : ?>
                <option value="<?php echo $p->ID; ?>" <?php selected( $poll_id, $p->ID ); ?>>
                    <?php echo esc_html( $p->post_title ); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label>
                <input type="checkbox" name="<?php echo $this->get_field_name( 'lazy' ); ?>" value="1" <?php checked( $lazy ); ?>>
                <?php _e( 'Chargement différé (lazy)', 'wp-sondage' ); ?>
            </label>
        </p>
        <?php
    }

    /** Sanitize */
    public function update( $new, $old ) {
        return [
            'title'   => sanitize_text_field( $new['title']   ?? '' ),
            'poll_id' => absint( $new['poll_id'] ?? 0 ),
            'lazy'    => ! empty( $new['lazy'] ) ? 1 : 0,
        ];
    }
}

/* ── Enregistrement ── */
add_action( 'widgets_init', function () {
    register_widget( 'WPS_Widget' );
} );
