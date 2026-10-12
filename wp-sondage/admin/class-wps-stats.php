<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPS_Stats — Page de statistiques admin
 * Vue globale + détail par sondage avec graphiques
 */
class WPS_Stats {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
        add_action( 'wp_ajax_wps_stats_poll', [ __CLASS__, 'ajax_poll_detail' ] );
    }

    public static function add_page() {
        add_submenu_page(
            'edit.php?post_type=wps_poll',
            __( 'Statistiques', 'wp-sondage' ),
            __( 'Statistiques', 'wp-sondage' ),
            'manage_options',
            'wps_stats',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ════════════════════════════════════════
       DONNÉES
    ════════════════════════════════════════ */

    private static function get_global_stats(): array {
        global $wpdb;
        $vt = $wpdb->prefix . 'wps_votes';
        $ct = $wpdb->prefix . 'wps_choices';

        $total_votes  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vt" );
        $total_polls  = (int) wp_count_posts( 'wps_poll' )->publish;
        $open_polls   = 0;
        $total_voters = 0; // votes uniques (par poll_id+ip_hash)

        // Votes par jour sur les 30 derniers jours
        $votes_by_day = $wpdb->get_results(
            "SELECT DATE(voted_at) AS day, COUNT(*) AS cnt
             FROM $vt
             WHERE voted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(voted_at)
             ORDER BY day ASC"
        );

        // Top 5 sondages par votes
        $top_polls = $wpdb->get_results(
            "SELECT poll_id, COUNT(*) AS cnt
             FROM $vt
             GROUP BY poll_id
             ORDER BY cnt DESC
             LIMIT 5"
        );
        foreach ( $top_polls as &$p ) {
            $p->title    = get_the_title( $p->poll_id ) ?: '—';
            $p->question = get_post_meta( $p->poll_id, '_wps_question', true ) ?: $p->title;
            $p->url      = get_edit_post_link( $p->poll_id );
        }

        // Sondages ouverts
        $all_ids = get_posts( [
            'post_type' => 'wps_poll', 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [ [ 'key' => '_wps_active', 'value' => '1' ] ],
        ] );
        foreach ( $all_ids as $id ) {
            if ( WPS_Ajax::poll_is_open( $id ) ) $open_polls++;
        }

        // Fréquence moyenne (votes/jour sur 30j)
        $days_with_votes = count( $votes_by_day );
        $freq = $days_with_votes > 0
            ? round( array_sum( array_column( (array) $votes_by_day, 'cnt' ) ) / 30, 1 )
            : 0;

        // Votes ce mois-ci vs mois dernier
        $this_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $vt WHERE voted_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
        );
        $last_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $vt
             WHERE voted_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
             AND   voted_at <  DATE_FORMAT(NOW(),'%Y-%m-01')"
        );

        return compact(
            'total_votes', 'total_polls', 'open_polls',
            'freq', 'votes_by_day', 'top_polls',
            'this_month', 'last_month'
        );
    }

    private static function get_all_polls_stats(): array {
        global $wpdb;
        $vt = $wpdb->prefix . 'wps_votes';

        $polls = get_posts( [
            'post_type'      => 'wps_poll',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $result = [];
        foreach ( $polls as $poll ) {
            $id       = $poll->ID;
            $votes    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $vt WHERE poll_id=%d", $id ) );
            $first_v  = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(voted_at) FROM $vt WHERE poll_id=%d", $id ) );
            $last_v   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(voted_at) FROM $vt WHERE poll_id=%d", $id ) );
            $is_open  = WPS_Ajax::poll_is_open( $id );
            $end_date = get_post_meta( $id, '_wps_end_date', true );
            $active   = get_post_meta( $id, '_wps_active', true );
            $results_pub = get_post_meta( $id, '_wps_results', true ) === 'public';

            $result[] = [
                'id'          => $id,
                'question'    => get_post_meta( $id, '_wps_question', true ) ?: $poll->post_title,
                'votes'       => $votes,
                'is_open'     => $is_open,
                'active'      => $active === '1',
                'results_pub' => $results_pub,
                'first_vote'  => $first_v,
                'last_vote'   => $last_v,
                'end_date'    => $end_date,
                'edit_url'    => get_edit_post_link( $id ),
                'view_url'    => get_permalink( $id ),
                'type'        => get_post_meta( $id, '_wps_type', true ),
            ];
        }
        return $result;
    }

    /* ════════════════════════════════════════
       AJAX — détail d'un sondage
    ════════════════════════════════════════ */
    public static function ajax_poll_detail() {
        check_ajax_referer( 'wps_stats_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );
        if ( ! $poll_id ) wp_send_json_error();

        global $wpdb;
        $vt = $wpdb->prefix . 'wps_votes';

        // Résultats globaux + par langue
        $multilang = class_exists( 'WPS_Sync' )
            ? WPS_Sync::get_results_multilang( $poll_id )
            : [ 'global' => WPS_Ajax::get_results_data( $poll_id ), 'by_lang' => [], 'synced' => false ];

        $master_id = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $poll_id ) : $poll_id;

        // Votes par jour (30j) sur le maître
        $by_day = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(voted_at) AS day, COUNT(*) AS cnt, lang
             FROM $vt
             WHERE poll_id=%d AND voted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(voted_at), lang ORDER BY day ASC",
            $master_id
        ) );

        // Votes par heure
        $by_hour = $wpdb->get_results( $wpdb->prepare(
            "SELECT HOUR(voted_at) AS h, COUNT(*) AS cnt
             FROM $vt WHERE poll_id=%d
             GROUP BY HOUR(voted_at) ORDER BY h ASC",
            $master_id
        ) );

        // Snapshots
        $snapshots = class_exists( 'WPS_Sync' )
            ? WPS_Sync::get_snapshots( $master_id )
            : [];

        $snaps_data = array_map( function( $s ) {
            return [
                'id'          => (int) $s->id,
                'label'       => $s->label,
                'snapshot_at' => $s->snapshot_at,
                'trigger'     => $s->trigger_type,
                'total'       => (int) $s->total_votes,
                'choices'     => json_decode( $s->choices_json, true ) ?: [],
            ];
        }, $snapshots );

        wp_send_json_success( [
            'results'    => $multilang['global'],
            'by_lang'    => $multilang['by_lang'],
            'synced'     => $multilang['synced'],
            'master_id'  => $master_id,
            'by_day'     => $by_day,
            'by_hour'    => $by_hour,
            'snapshots'  => $snaps_data,
        ] );
    }

    /* ════════════════════════════════════════
       RENDU PAGE
    ════════════════════════════════════════ */
    public static function render_page() {
        $g     = self::get_global_stats();
        $polls = self::get_all_polls_stats();
        $trend = $g['last_month'] > 0
            ? round( ( $g['this_month'] - $g['last_month'] ) / $g['last_month'] * 100 )
            : ( $g['this_month'] > 0 ? 100 : 0 );
        ?>
        <div class="wrap wps-stats-page">
            <h1><?php _e( 'Statistiques WP Sondage', 'wp-sondage' ); ?></h1>

            <!-- ── Cartes KPI ── -->
            <div class="wps-kpi-grid">
                <div class="wps-kpi-card">
                    <div class="wps-kpi-value"><?php echo number_format( $g['total_votes'] ); ?></div>
                    <div class="wps-kpi-label"><?php _e( 'Votes total', 'wp-sondage' ); ?></div>
                </div>
                <div class="wps-kpi-card">
                    <div class="wps-kpi-value"><?php echo $g['total_polls']; ?></div>
                    <div class="wps-kpi-label"><?php _e( 'Sondages publiés', 'wp-sondage' ); ?></div>
                </div>
                <div class="wps-kpi-card wps-kpi-highlight">
                    <div class="wps-kpi-value"><?php echo $g['open_polls']; ?></div>
                    <div class="wps-kpi-label"><?php _e( 'Sondages ouverts', 'wp-sondage' ); ?></div>
                </div>
                <div class="wps-kpi-card">
                    <div class="wps-kpi-value"><?php echo $g['freq']; ?></div>
                    <div class="wps-kpi-label"><?php _e( 'Votes/jour (moy. 30j)', 'wp-sondage' ); ?></div>
                </div>
                <div class="wps-kpi-card <?php echo $trend >= 0 ? 'wps-kpi-up' : 'wps-kpi-down'; ?>">
                    <div class="wps-kpi-value">
                        <?php echo $g['this_month']; ?>
                        <span class="wps-kpi-trend"><?php echo ($trend >= 0 ? '+' : '') . $trend; ?>%</span>
                    </div>
                    <div class="wps-kpi-label"><?php _e( 'Votes ce mois vs mois dernier', 'wp-sondage' ); ?></div>
                </div>
            </div>

            <!-- ── Graphique activité 30j ── -->
            <div class="wps-stats-row">
                <div class="wps-stats-card wps-stats-card--wide">
                    <h2><?php _e( 'Activité — 30 derniers jours', 'wp-sondage' ); ?></h2>
                    <?php self::render_global_chart( $g['votes_by_day'] ); ?>
                </div>

                <?php if ( $g['top_polls'] ) : ?>
                <div class="wps-stats-card">
                    <h2><?php _e( 'Top 5 sondages', 'wp-sondage' ); ?></h2>
                    <ol class="wps-top-list">
                    <?php foreach ( $g['top_polls'] as $i => $p ) : ?>
                        <li>
                            <span class="wps-top-num"><?php echo $i+1; ?></span>
                            <span class="wps-top-title">
                                <a href="<?php echo esc_url( $p->url ?? '#' ); ?>"><?php echo esc_html( mb_strimwidth( $p->question, 0, 55, '…' ) ); ?></a>
                            </span>
                            <span class="wps-top-votes"><?php echo number_format( $p->cnt ); ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tableau des sondages ── -->
            <div class="wps-stats-card wps-stats-card--full" style="margin-top:20px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                    <h2 style="margin:0"><?php _e( 'Détail par sondage', 'wp-sondage' ); ?></h2>
                    <input type="search" id="wps-stats-search"
                           placeholder="<?php esc_attr_e( 'Filtrer…', 'wp-sondage' ); ?>"
                           class="wps-stats-search">
                </div>
                <table class="wp-list-table widefat fixed striped wps-stats-table" id="wps-stats-table">
                    <thead>
                        <tr>
                            <th class="wps-col-question"><?php _e( 'Question', 'wp-sondage' ); ?></th>
                            <th class="wps-col-votes wps-sortable" data-sort="votes"><?php _e( 'Votes ↕', 'wp-sondage' ); ?></th>
                            <th class="wps-col-status"><?php _e( 'Statut', 'wp-sondage' ); ?></th>
                            <th class="wps-col-last wps-sortable" data-sort="last"><?php _e( 'Dernier vote ↕', 'wp-sondage' ); ?></th>
                            <th class="wps-col-actions"><?php _e( 'Actions', 'wp-sondage' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $polls as $p ) : ?>
                        <tr class="wps-poll-row" data-id="<?php echo $p['id']; ?>"
                            data-question="<?php echo esc_attr( strtolower( $p['question'] ) ); ?>"
                            data-votes="<?php echo $p['votes']; ?>"
                            data-last="<?php echo esc_attr( $p['last_vote'] ?? '' ); ?>">
                            <td class="wps-col-question">
                                <strong><?php echo esc_html( mb_strimwidth( $p['question'], 0, 70, '…' ) ); ?></strong>
                            </td>
                            <td class="wps-col-votes">
                                <strong><?php echo number_format( $p['votes'] ); ?></strong>
                                <?php if ( $p['votes'] > 0 ) : ?>
                                <div class="wps-mini-bar">
                                    <div class="wps-mini-bar-fill" data-votes="<?php echo $p['votes']; ?>" style="width:0%"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="wps-col-status">
                                <?php if ( $p['is_open'] ) : ?>
                                    <span class="wps-status-badge wps-status-open"><?php _e( 'Ouvert', 'wp-sondage' ); ?></span>
                                <?php elseif ( ! $p['active'] ) : ?>
                                    <span class="wps-status-badge wps-status-hidden"><?php _e( 'Masqué', 'wp-sondage' ); ?></span>
                                <?php else : ?>
                                    <span class="wps-status-badge wps-status-closed"><?php _e( 'Fermé', 'wp-sondage' ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! $p['results_pub'] ) : ?>
                                    <span class="wps-status-badge wps-status-priv" title="<?php esc_attr_e( 'Résultats privés', 'wp-sondage' ); ?>">🔒</span>
                                <?php endif; ?>
                            </td>
                            <td class="wps-col-last">
                                <?php if ( $p['last_vote'] ) : ?>
                                    <span title="<?php echo esc_attr( $p['first_vote'] ? sprintf( __( 'Premier vote : %s', 'wp-sondage' ), date_i18n( 'd/m/Y H:i', strtotime( $p['first_vote'] ) ) ) : '' ); ?>">
                                        <?php echo date_i18n( 'd/m/Y H:i', strtotime( $p['last_vote'] ) ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color:#aaa">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="wps-col-actions">
                                <button class="button button-small wps-btn-detail"
                                        data-poll-id="<?php echo $p['id']; ?>"
                                        data-question="<?php echo esc_attr( $p['question'] ); ?>">
                                    <?php _e( 'Détail', 'wp-sondage' ); ?>
                                </button>
                                <?php if ( $p['view_url'] ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( $p['view_url'] ); ?>" target="_blank">↗</a>
                                <?php endif; ?>
                                <a class="button button-small" href="<?php echo esc_url( $p['edit_url'] ); ?>"><?php _e( 'Modifier', 'wp-sondage' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <?php self::render_styles(); ?>
        <?php self::render_scripts( $g, $polls ); ?>
        <?php
    }

    /* ── Graphique activité 30j — rendu PHP pur (pas de JS) ── */
    private static function render_global_chart( array $votes_by_day ): void {
        // Construire le tableau jour → count
        $by_day_map = [];
        foreach ( $votes_by_day as $row ) {
            $by_day_map[ $row->day ] = (int) $row->cnt;
        }

        // 30 jours : J-29 → aujourd'hui
        $days = [];
        $today = new DateTime();
        for ( $i = 29; $i >= 0; $i-- ) {
            $d    = clone $today;
            $d->modify( "-{$i} days" );
            $key  = $d->format( 'Y-m-d' );
            $days[] = [
                'label' => $d->format( 'd/m' ),
                'cnt'   => $by_day_map[ $key ] ?? 0,
                'key'   => $key,
            ];
        }

        $max = max( array_column( $days, 'cnt' ) ?: [0] );
        if ( $max === 0 ) $max = 1; // éviter division par zéro
        ?>
        <div class="wps-php-chart" role="img" aria-label="<?php esc_attr_e( 'Activité des 30 derniers jours', 'wp-sondage' ); ?>">
            <div class="wps-php-chart-bars">
                <?php foreach ( $days as $day ) :
                    $pct     = round( $day['cnt'] / $max * 100 );
                    $is_today = ( $day['key'] === $today->format( 'Y-m-d' ) );
                    $title   = esc_attr( $day['label'] . ' : ' . $day['cnt'] . ' vote' . ( $day['cnt'] > 1 ? 's' : '' ) );
                ?>
                <div class="wps-php-bar-col" title="<?php echo $title; ?>">
                    <div class="wps-php-bar-value"><?php if ( $day['cnt'] ) echo $day['cnt']; ?></div>
                    <div class="wps-php-bar-wrap">
                        <div class="wps-php-bar <?php echo $is_today ? 'wps-php-bar--today' : ''; ?>"
                             style="height:<?php echo $pct; ?>%"></div>
                    </div>
                    <div class="wps-php-bar-label"><?php echo esc_html( $day['label'] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ── Styles inline ── */
    private static function render_styles() { ?>
    <style>
    .wps-stats-page h1 { margin-bottom: 20px; }
    .wps-stats-page h2 { font-size: 14px; font-weight: 600; margin: 0 0 12px; color: #1d2327; }
    .wps-stats-page h4 { font-size: 13px; font-weight: 600; margin: 0 0 10px; }

    /* KPI */
    .wps-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 20px; }
    .wps-kpi-card { background:#fff; border:1px solid #e2e4e7; border-radius:6px; padding:16px; text-align:center; }
    .wps-kpi-value { font-size: 28px; font-weight: 700; color: #1d2327; line-height: 1; }
    .wps-kpi-label { font-size: 12px; color: #646970; margin-top: 6px; }
    .wps-kpi-highlight { border-top: 3px solid #2271b1; }
    .wps-kpi-up   .wps-kpi-value { color: #00a32a; }
    .wps-kpi-down .wps-kpi-value { color: #d63638; }
    .wps-kpi-trend { font-size: 13px; font-weight: 600; margin-left: 4px; }

    /* Cartes */
    .wps-stats-row { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: start; }
    .wps-stats-card { background:#fff; border:1px solid #e2e4e7; border-radius:6px; padding:16px; }
    .wps-stats-card--wide { }
    .wps-stats-card--full { }
    .wps-chart-wrap { position:relative; }

    /* Graphique PHP pur — activité 30j */
    .wps-php-chart {
        width: 100%;
        padding: 4px 0 0;
    }
    .wps-php-chart-bars {
        display:     flex;
        align-items: flex-end;
        gap:         2px;
        height:      140px;
    }
    .wps-php-bar-col {
        flex:        1;
        display:     flex;
        flex-direction: column;
        align-items: center;
        height:      100%;
        cursor:      default;
    }
    .wps-php-bar-value {
        font-size:   9px;
        color:       #646970;
        margin-bottom: 2px;
        min-height:  12px;
        line-height: 1;
    }
    .wps-php-bar-wrap {
        flex:        1;
        width:       100%;
        display:     flex;
        align-items: flex-end;
    }
    .wps-php-bar {
        width:         100%;
        background:    #2271b1;
        border-radius: 2px 2px 0 0;
        min-height:    0;
        transition:    background .15s;
    }
    .wps-php-bar--today { background: #72aee6; }
    .wps-php-bar-col:hover .wps-php-bar { background: #135e96; }
    .wps-php-bar-col:hover .wps-php-bar.wps-php-bar--today { background: #2271b1; }
    .wps-php-bar-label {
        font-size:  8px;
        color:      #8c8f94;
        margin-top: 3px;
        white-space: nowrap;
        /* N'afficher qu'1 label sur 5 pour éviter l'encombrement */
    }
    /* Masquer les labels intermédiaires — on garde j-29, j-24, j-19, j-14, j-9, j-4, today */
    .wps-php-bar-col:not(:nth-child(5n+1)):not(:last-child) .wps-php-bar-label {
        visibility: hidden;
    }

    /* Top list */
    .wps-top-list { margin:0; padding:0; list-style:none; }
    .wps-top-list li { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid #f0f0f1; font-size:13px; }
    .wps-top-list li:last-child { border-bottom:none; }
    .wps-top-num  { width:20px; height:20px; background:#2271b1; color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
    .wps-top-title { flex:1; overflow:hidden; }
    .wps-top-title a { color:#2271b1; text-decoration:none; }
    .wps-top-title a:hover { text-decoration:underline; }
    .wps-top-votes { font-weight:700; color:#1d2327; white-space:nowrap; }

    /* Tableau */
    .wps-stats-search { float:none; width:220px; }
    .wps-stats-table .wps-col-question { width:40%; }
    .wps-stats-table .wps-col-votes    { width:12%; }
    .wps-stats-table .wps-col-status   { width:12%; }
    .wps-stats-table .wps-col-last     { width:16%; font-size:12px; }
    .wps-stats-table .wps-col-actions  { width:20%; white-space:nowrap; }
    .wps-sortable { cursor:pointer; user-select:none; }
    .wps-sortable:hover { color:#2271b1; }

    /* Mini bar */
    .wps-mini-bar { height:4px; background:#f0f0f1; border-radius:99px; margin-top:4px; overflow:hidden; }
    .wps-mini-bar-fill { height:100%; background:#2271b1; border-radius:99px; transition:width .4s; width:0%; }

    /* Status badges */
    .wps-status-badge { display:inline-block; padding:2px 7px; border-radius:99px; font-size:11px; font-weight:600; }
    .wps-status-open   { background:#d7eddb; color:#00a32a; }
    .wps-status-closed { background:#fce8e8; color:#d63638; }
    .wps-status-hidden { background:#f0f0f1; color:#646970; }
    .wps-status-priv   { font-size:12px; }

    /* Modal — attachée à <body> par JS pour éviter le contexte scrollable WP admin
       Dimensions en px fixes, pas de vh, pas de sticky, pas de overflow sur le wrapper */
    #wps-modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 999999;
        background: rgba(0,0,0,.6);
        display: flex;
        align-items: center;
        justify-content: center;
        /* Bloque le scroll du body quand ouverte */
        overflow: hidden;
    }
    #wps-modal-wrap {
        position: relative;
        background: #fff;
        border-radius: 8px;
        /* Largeur et hauteur en px fixes — pas de vh pour éviter la boucle de reflow */
        width: 920px;
        max-width: calc(100vw - 40px);
        height: 720px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 40px rgba(0,0,0,.35);
        overflow: hidden; /* Le overflow est ici, pas sur le wrapper externe */
    }
    #wps-modal-head {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        border-bottom: 1px solid #e2e4e7;
        background: #fff;
        /* PAS de sticky — c'est inutile puisque le header ne scroll pas */
    }
    #wps-modal-head h3 { margin: 0; font-size: 15px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #wps-modal-close {
        flex-shrink: 0;
        background: none; border: none; font-size: 20px; line-height: 1;
        cursor: pointer; color: #646970; padding: 4px 8px; border-radius: 4px; margin-left: 12px;
    }
    #wps-modal-close:hover { background: #f0f0f1; color: #1d2327; }
    #wps-modal-body {
        flex: 1;
        overflow-y: auto; /* Le scroll est ici, dans un conteneur de taille fixe */
        overflow-x: hidden;
        padding: 20px;
    }
    .wps-modal-loader { text-align: center; color: #646970; padding: 40px 20px; }
    .wps-detail-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    /* Conteneurs de canvas avec hauteur FIXE — critique pour éviter la boucle Chart.js */
    .wps-chart-fixed {
        position: relative;
        width: 100%;
        height: 180px; /* hauteur fixe en px */
    }
    .wps-chart-fixed--sm {
        position: relative;
        width: 100%;
        height: 120px;
    }

    /* Résultats dans modal */
    #wps-detail-results .wps-res-row { margin-bottom: 10px; }
    #wps-detail-results .wps-res-label { font-size: 13px; font-weight: 500; margin-bottom: 4px; display: flex; justify-content: space-between; }
    #wps-detail-results .wps-res-bar-wrap { height: 8px; background: #f0f0f1; border-radius: 99px; overflow: hidden; }
    #wps-detail-results .wps-res-bar { height: 100%; background: #2271b1; border-radius: 99px; transition: width .5s cubic-bezier(.4,0,.2,1); width: 0%; }
    #wps-detail-results .wps-res-total { font-size: 12px; color: #646970; margin-bottom: 12px; }

    @media (max-width: 782px) {
        .wps-stats-row { grid-template-columns: 1fr; }
        .wps-detail-row { grid-template-columns: 1fr; }
        .wps-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        #wps-modal-wrap { width: calc(100vw - 20px); height: calc(100vh - 40px); }
    }
    </style>
    <?php }

    /* ── Scripts inline ── */
    private static function render_scripts( array $g, array $polls ) {
        $max_votes = $polls ? max( array_column( $polls, 'votes' ) ) : 1;
        ?>
        <script>
        (function($){
            // ── Mini-barres tableau ──
            var maxV = <?php echo (int) $max_votes ?: 1; ?>;
            document.querySelectorAll('.wps-mini-bar-fill').forEach(function(el) {
                var v = parseInt(el.dataset.votes, 10) || 0;
                setTimeout(function() { el.style.width = Math.round(v/maxV*100) + '%'; }, 150);
            });

            // ── Tri tableau ──
            var sortDir = { votes:-1, last:-1 };
            document.querySelectorAll('.wps-sortable').forEach(function(th) {
                th.addEventListener('click', function() {
                    var col  = this.dataset.sort;
                    var dir  = sortDir[col] = -sortDir[col];
                    var rows = Array.from(document.querySelectorAll('#wps-stats-table tbody .wps-poll-row'));
                    rows.sort(function(a,b) {
                        var av = col==='votes' ? parseInt(a.dataset.votes,10) : (a.dataset.last||'');
                        var bv = col==='votes' ? parseInt(b.dataset.votes,10) : (b.dataset.last||'');
                        if (av < bv) return dir; if (av > bv) return -dir; return 0;
                    });
                    var tbody = document.querySelector('#wps-stats-table tbody');
                    rows.forEach(function(r){ tbody.appendChild(r); });
                });
            });

            // ── Filtre recherche ──
            document.getElementById('wps-stats-search').addEventListener('input', function() {
                var q = this.value.toLowerCase().trim();
                document.querySelectorAll('.wps-poll-row').forEach(function(row) {
                    row.style.display = (!q || row.dataset.question.includes(q)) ? '' : 'none';
                });
            });

            // ── Modal détail ──
            // La modal est créée une seule fois et attachée à <body>
            // pour échapper au contexte scrollable de #wpcontent (source du bug)
            var MODAL_HTML = [
                '<div id="wps-modal-overlay" style="display:none">',
                  '<div id="wps-modal-wrap">',
                    '<div id="wps-modal-head">',
                      '<h3 id="wps-modal-title">—</h3>',
                      '<button id="wps-modal-close" type="button">✕</button>',
                    '</div>',
                    '<div id="wps-modal-body">',
                      '<div class="wps-modal-loader"><?php _e('Chargement…','wp-sondage'); ?></div>',
                      '<div id="wps-modal-content" style="display:none">',

                        /* ── Résultats + Activité ── */
                        '<div class="wps-detail-row">',
                          '<div>',
                            '<h4 style="font-size:13px;font-weight:600;margin:0 0 10px"><?php _e('Résultats globaux','wp-sondage'); ?></h4>',
                            '<div id="wps-detail-results"></div>',
                          '</div>',
                          '<div>',
                            '<h4 style="font-size:13px;font-weight:600;margin:0 0 10px"><?php _e('Activité 30 jours','wp-sondage'); ?></h4>',
                            '<div class="wps-chart-fixed"><canvas id="wps-chart-detail-day"></canvas></div>',
                          '</div>',
                        '</div>',

                        /* ── Répartition par langue ── */
                        '<div id="wps-lang-section" style="display:none;margin-top:18px;border-top:1px solid #e2e4e7;padding-top:14px">',
                          '<h4 style="font-size:13px;font-weight:600;margin:0 0 10px"><?php _e('Répartition par langue','wp-sondage'); ?></h4>',
                          '<div id="wps-lang-bars"></div>',
                        '</div>',

                        /* ── Distribution horaire ── */
                        '<div style="margin-top:18px;border-top:1px solid #e2e4e7;padding-top:14px">',
                          '<h4 style="font-size:13px;font-weight:600;margin:0 0 10px"><?php _e('Distribution par heure','wp-sondage'); ?></h4>',
                          '<div class="wps-chart-fixed--sm"><canvas id="wps-chart-detail-hour"></canvas></div>',
                        '</div>',

                        /* ── Snapshots ── */
                        '<div style="margin-top:18px;border-top:1px solid #e2e4e7;padding-top:14px">',
                          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">',
                            '<h4 style="font-size:13px;font-weight:600;margin:0"><?php _e('Historique (snapshots)','wp-sondage'); ?></h4>',
                            '<div style="display:flex;gap:6px;align-items:center">',
                              '<input type="text" id="wps-snap-label" placeholder="<?php esc_attr_e('Libellé du snapshot…','wp-sondage'); ?>" style="font-size:12px;padding:4px 8px;border:1px solid #ddd;border-radius:4px;width:200px">',
                              '<button id="wps-snap-create" class="button button-small"><?php _e('+ Snapshot','wp-sondage'); ?></button>',
                            '</div>',
                          '</div>',
                          '<div id="wps-snapshots-list"><p style="color:#646970;font-size:13px"><?php _e('Aucun snapshot.','wp-sondage'); ?></p></div>',
                        '</div>',

                      '</div>',
                    '</div>',
                  '</div>',
                '</div>'
            ].join('');
            $('body').append(MODAL_HTML);

            var $overlay       = $('#wps-modal-overlay');
            var chartDay       = null;
            var chartHour      = null;
            var chartSnap      = null;
            var currentPollId  = 0;
            var currentMaster  = 0;
            var statsNonce     = '<?php echo wp_create_nonce('wps_stats_nonce'); ?>';

            function openModal(pollId, question) {
                currentPollId = pollId;
                $('#wps-modal-title').text(question);
                $('#wps-modal-content').hide();
                $('.wps-modal-loader').show();
                $('body').css('overflow', 'hidden');
                $overlay.fadeIn(160);
            }

            function closeModal() {
                $overlay.fadeOut(160, function() { $('body').css('overflow', ''); });
                [chartDay, chartHour, chartSnap].forEach(function(c) { if (c) c.destroy(); });
                chartDay = chartHour = chartSnap = null;
            }

            /* ── Ouvrir modal ── */
            $(document).on('click', '.wps-btn-detail', function() {
                var pollId   = $(this).data('poll-id');
                var question = $(this).data('question');
                openModal(pollId, question);

                $.post(ajaxurl, { action:'wps_stats_poll', nonce:statsNonce, poll_id:pollId })
                .done(function(res) {
                    if (!res.success) { $('.wps-modal-loader').text('Erreur.'); return; }
                    var d = res.data;
                    currentMaster = d.master_id || pollId;

                    /* ── Résultats globaux ── */
                    var top  = d.results.choices.filter(function(c){ return c.parent_id === 0; });
                    var suffix = d.synced ? ' <small style="font-weight:400;color:#888">(toutes langues)</small>' : '';
                    var html = '<p class="wps-res-total">' + d.results.total + ' vote' + (d.results.total > 1 ? 's' : '') + suffix + '</p>';
                    top.forEach(function(c) {
                        html += '<div class="wps-res-row">'
                            + '<div class="wps-res-label"><span>' + escHtml(c.label) + '</span><strong>' + c.percent + '%</strong></div>'
                            + '<div class="wps-res-bar-wrap"><div class="wps-res-bar" data-pct="' + c.percent + '" style="width:0%"></div></div>'
                            + '</div>';
                    });
                    $('#wps-detail-results').html(html);
                    requestAnimationFrame(function(){ requestAnimationFrame(function(){
                        document.querySelectorAll('#wps-detail-results .wps-res-bar').forEach(function(b){ b.style.width = b.dataset.pct + '%'; });
                    }); });

                    /* ── Répartition par langue ── */
                    var $ls = $('#wps-lang-section');
                    if (d.synced && d.by_lang && Object.keys(d.by_lang).length > 1) {
                        var FLAGS = {fr:'🇫🇷',en:'🇬🇧',de:'🇩🇪',es:'🇪🇸',it:'🇮🇹',pt:'🇵🇹',nl:'🇳🇱',pl:'🇵🇱',ru:'🇷🇺',ar:'🇸🇦',zh:'🇨🇳',ja:'🇯🇵'};
                        var lh = '';
                        Object.keys(d.by_lang).forEach(function(lang) {
                            var info = d.by_lang[lang];
                            var flag = FLAGS[lang] || '🌐';
                            lh += '<div style="display:grid;grid-template-columns:90px 1fr 55px 45px;align-items:center;gap:6px;margin-bottom:7px">'
                                + '<div style="font-size:13px;font-weight:600">' + flag + ' ' + lang.toUpperCase() + '</div>'
                                + '<div style="height:7px;background:#f0f0f1;border-radius:99px;overflow:hidden">'
                                +   '<div style="height:100%;background:#2271b1;border-radius:99px;width:0%;transition:width .4s" data-pct="' + info.percent + '"></div>'
                                + '</div>'
                                + '<div style="font-size:12px;font-weight:700;color:#2271b1;text-align:right">' + info.percent + '%</div>'
                                + '<div style="font-size:11px;color:#646970;text-align:right">(' + info.vote_count + ')</div>'
                                + '</div>';
                        });
                        $('#wps-lang-bars').html(lh);
                        requestAnimationFrame(function(){ requestAnimationFrame(function(){
                            document.querySelectorAll('#wps-lang-bars [data-pct]').forEach(function(b){ b.style.width = b.dataset.pct + '%'; });
                        }); });
                        $ls.show();
                    } else { $ls.hide(); }

                    /* ── Snapshots ── */
                    renderSnapshots(d.snapshots || []);

                    /* ── Afficher contenu avant charts (canvas doit être visible) ── */
                    $('.wps-modal-loader').hide();
                    $('#wps-modal-content').show();

                    if (typeof Chart === 'undefined') return;

                    /* ── Chart activité 30j (par langue si synchro) ── */
                    var today = new Date();
                    var labels30 = [], datasets30 = [];
                    var hasSyncDay = d.synced && d.by_lang && Object.keys(d.by_lang).length > 1;
                    var COLORS = ['rgba(34,113,177,.8)','rgba(34,177,110,.8)','rgba(177,110,34,.8)','rgba(177,34,110,.8)'];
                    var dayMap = {}; // lang -> {date -> cnt}

                    if (hasSyncDay) {
                        Object.keys(d.by_lang).forEach(function(lang){ dayMap[lang] = {}; });
                        d.by_day.forEach(function(r){ if (r.lang && dayMap[r.lang]) dayMap[r.lang][r.day] = parseInt(r.cnt,10); });
                    }

                    for (var i = 29; i >= 0; i--) {
                        var d2 = new Date(today); d2.setDate(d2.getDate()-i);
                        var key = d2.getFullYear()+'-'+String(d2.getMonth()+1).padStart(2,'0')+'-'+String(d2.getDate()).padStart(2,'0');
                        labels30.push(String(d2.getDate()).padStart(2,'0')+'/'+String(d2.getMonth()+1).padStart(2,'0'));
                        if (!hasSyncDay) {
                            if (!datasets30.length) datasets30.push({ label:'Votes', data:[], backgroundColor:'rgba(34,113,177,.75)', borderRadius:3, borderSkipped:false });
                            var found = d.by_day.find(function(r){ return r.day === key; });
                            datasets30[0].data.push(found ? parseInt(found.cnt,10) : 0);
                        }
                    }
                    if (hasSyncDay) {
                        Object.keys(d.by_lang).forEach(function(lang, idx) {
                            var data = [];
                            for (var i = 29; i >= 0; i--) {
                                var d3 = new Date(today); d3.setDate(d3.getDate()-i);
                                var k = d3.getFullYear()+'-'+String(d3.getMonth()+1).padStart(2,'0')+'-'+String(d3.getDate()).padStart(2,'0');
                                data.push(dayMap[lang][k] || 0);
                            }
                            datasets30.push({ label: lang.toUpperCase(), data: data, backgroundColor: COLORS[idx % COLORS.length], borderRadius:3, borderSkipped:false });
                        });
                    }

                    if (chartDay) chartDay.destroy();
                    chartDay = new Chart(document.getElementById('wps-chart-detail-day'), {
                        type:'bar',
                        data:{ labels:labels30, datasets:datasets30 },
                        options:{ responsive:true, maintainAspectRatio:false, animation:false,
                            plugins:{ legend:{ display: hasSyncDay } },
                            scales:{ y:{beginAtZero:true,ticks:{precision:0,font:{size:10}},grid:{color:'rgba(0,0,0,.04)'}},
                                     x:{ticks:{font:{size:9},maxTicksLimit:10},grid:{display:false}} } }
                    });

                    /* ── Chart distribution horaire ── */
                    var labelsH=[], dataH=[];
                    for (var h=0; h<24; h++) {
                        labelsH.push(h+'h');
                        var fh = d.by_hour.find(function(r){ return parseInt(r.h,10)===h; });
                        dataH.push(fh ? parseInt(fh.cnt,10) : 0);
                    }
                    if (chartHour) chartHour.destroy();
                    chartHour = new Chart(document.getElementById('wps-chart-detail-hour'), {
                        type:'bar',
                        data:{ labels:labelsH, datasets:[{ label:'Votes', data:dataH, backgroundColor:'rgba(34,113,177,.6)', borderRadius:2, borderSkipped:false }] },
                        options:{ responsive:true, maintainAspectRatio:false, animation:false,
                            plugins:{legend:{display:false}},
                            scales:{ y:{beginAtZero:true,ticks:{precision:0,font:{size:10}}}, x:{ticks:{font:{size:9}},grid:{display:false}} } }
                    });
                });
            });

            /* ── Snapshots : rendu + création + suppression + comparaison ── */
            function renderSnapshots(snaps) {
                var $list = $('#wps-snapshots-list');
                if (!snaps || !snaps.length) {
                    $list.html('<p style="color:#646970;font-size:13px;margin:0"><?php _e('Aucun snapshot. Créez-en un pour comparer les tendances dans le temps.','wp-sondage'); ?></p>');
                    return;
                }

                var TRIGGER_LABELS = {
                    manual:      '<?php _e('Manuel','wp-sondage'); ?>',
                    auto_close:  '<?php _e('Clôture auto','wp-sondage'); ?>',
                    date_expire: '<?php _e('Expiration date','wp-sondage'); ?>',
                    reset_before:'<?php _e('Avant reset','wp-sondage'); ?>'
                };

                var html = '<table style="width:100%;font-size:12px;border-collapse:collapse">'
                    + '<thead><tr style="border-bottom:1px solid #e2e4e7">'
                    + '<th style="padding:5px 8px;text-align:left;font-weight:600"><?php _e('Libellé','wp-sondage'); ?></th>'
                    + '<th style="padding:5px 8px;text-align:left;font-weight:600"><?php _e('Date','wp-sondage'); ?></th>'
                    + '<th style="padding:5px 8px;text-align:center;font-weight:600"><?php _e('Votes','wp-sondage'); ?></th>'
                    + '<th style="padding:5px 8px;text-align:center;font-weight:600"><?php _e('Déclencheur','wp-sondage'); ?></th>'
                    + '<th style="padding:5px 8px"></th>'
                    + '</tr></thead><tbody>';

                snaps.forEach(function(s) {
                    var trig = TRIGGER_LABELS[s.trigger] || s.trigger;
                    html += '<tr style="border-bottom:1px solid #f0f0f1" data-snap-id="' + s.id + '">'
                        + '<td style="padding:5px 8px">' + escHtml(s.label) + '</td>'
                        + '<td style="padding:5px 8px;color:#646970">' + s.snapshot_at.substring(0,16).replace('T',' ') + '</td>'
                        + '<td style="padding:5px 8px;text-align:center;font-weight:600">' + s.total + '</td>'
                        + '<td style="padding:5px 8px;text-align:center"><span style="background:#f0f0f1;padding:2px 6px;border-radius:99px;font-size:11px">' + trig + '</span></td>'
                        + '<td style="padding:5px 8px;text-align:right;white-space:nowrap">'
                        + '<button class="button button-small wps-snap-compare" data-snap-id="' + s.id + '" style="margin-right:4px"><?php _e('Comparer','wp-sondage'); ?></button>'
                        + '<button class="button button-small wps-snap-delete" data-snap-id="' + s.id + '" style="color:#d63638"><?php _e('Supprimer','wp-sondage'); ?></button>'
                        + '</td></tr>';
                });
                html += '</tbody></table>';

                $list.html(html);
                $list.data('snaps', snaps);
            }

            /* Créer un snapshot */
            $(document).on('click', '#wps-snap-create', function() {
                var label = $('#wps-snap-label').val().trim();
                var $btn  = $(this).prop('disabled', true).text('…');
                $.post(ajaxurl, { action:'wps_snapshot_create', nonce:statsNonce, poll_id:currentPollId, label:label })
                .done(function(res) {
                    if (res.success) {
                        $('#wps-snap-label').val('');
                        // Recharger les snapshots
                        $.post(ajaxurl, { action:'wps_stats_poll', nonce:statsNonce, poll_id:currentPollId })
                        .done(function(r2){ if(r2.success) renderSnapshots(r2.data.snapshots||[]); });
                    } else { alert(res.data && res.data.message ? res.data.message : 'Erreur'); }
                    $btn.prop('disabled', false).text('+ Snapshot');
                });
            });

            /* Supprimer un snapshot */
            $(document).on('click', '.wps-snap-delete', function() {
                var snapId = $(this).data('snap-id');
                if (!confirm('<?php _e('Supprimer ce snapshot ?','wp-sondage'); ?>')) return;
                $.post(ajaxurl, { action:'wps_snapshot_delete', nonce:statsNonce, snapshot_id:snapId })
                .done(function(res) {
                    if (res.success) $('[data-snap-id="' + snapId + '"]').closest('tr').fadeOut(200, function(){ $(this).remove(); });
                });
            });

            /* Comparer un snapshot avec l'état actuel */
            $(document).on('click', '.wps-snap-compare', function() {
                var snapId  = parseInt($(this).data('snap-id'), 10);
                var snaps   = $('#wps-snapshots-list').data('snaps') || [];
                var snap    = snaps.find(function(s){ return s.id === snapId; });
                if (!snap) return;

                // Récupérer résultats actuels
                $.post(ajaxurl, { action:'wps_stats_poll', nonce:statsNonce, poll_id:currentPollId })
                .done(function(res) {
                    if (!res.success) return;
                    var current = res.data.results;
                    var snapChoices = snap.choices;

                    if (typeof Chart === 'undefined') return;

                    // Construire un chart horizontal côte à côte
                    var labels = current.choices.filter(function(c){ return c.parent_id===0; }).map(function(c){ return c.label; });
                    var dataCurrent = current.choices.filter(function(c){ return c.parent_id===0; }).map(function(c){ return c.percent; });
                    var dataSnap    = (snapChoices||[]).filter(function(c){ return c.parent_id===0; }).map(function(c){ return c.percent; });

                    // Afficher dans une zone dédiée
                    var compHtml = '<div style="margin-top:14px;padding:12px;background:#f8f9fa;border-radius:6px;border:1px solid #e2e4e7">'
                        + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">'
                        + '<strong style="font-size:13px"><?php _e('Comparaison','wp-sondage'); ?> : ' + escHtml(snap.label) + ' vs <?php _e('Actuel','wp-sondage'); ?></strong>'
                        + '<button id="wps-close-compare" class="button button-small">✕</button>'
                        + '</div>'
                        + '<div style="position:relative;height:160px"><canvas id="wps-chart-compare"></canvas></div>'
                        + '</div>';

                    var $existing = $('#wps-compare-area');
                    if ($existing.length) $existing.replaceWith('<div id="wps-compare-area">' + compHtml + '</div>');
                    else $('#wps-snapshots-list').after('<div id="wps-compare-area">' + compHtml + '</div>');

                    if (chartSnap) { chartSnap.destroy(); chartSnap = null; }
                    chartSnap = new Chart(document.getElementById('wps-chart-compare'), {
                        type:'bar',
                        data:{
                            labels: labels,
                            datasets:[
                                { label: escHtml(snap.label), data: dataSnap,    backgroundColor:'rgba(34,113,177,.5)', borderColor:'rgba(34,113,177,.8)', borderWidth:1, borderRadius:3 },
                                { label: '<?php _e('Actuel','wp-sondage'); ?>',  data: dataCurrent, backgroundColor:'rgba(34,177,110,.6)', borderColor:'rgba(34,177,110,.9)', borderWidth:1, borderRadius:3 }
                            ]
                        },
                        options:{ responsive:true, maintainAspectRatio:false, animation:false,
                            plugins:{ legend:{ display:true, position:'bottom', labels:{font:{size:11}} } },
                            scales:{
                                y:{ beginAtZero:true, max:100, ticks:{ callback:function(v){ return v+'%'; }, font:{size:10} }, grid:{color:'rgba(0,0,0,.04)'} },
                                x:{ ticks:{ font:{size:10} }, grid:{display:false} }
                            }
                        }
                    });
                });
            });

            /* Fermer la comparaison */
            $(document).on('click', '#wps-close-compare', function(){
                $('#wps-compare-area').fadeOut(150, function(){ $(this).remove(); });
                if (chartSnap) { chartSnap.destroy(); chartSnap = null; }
            });

            /* ── Fermer la modal ── */
            $(document).on('click', '#wps-modal-close, #wps-modal-overlay', function(e) {
                if ($(e.target).is('#wps-modal-overlay') || $(e.target).is('#wps-modal-close')) closeModal();
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $overlay.is(':visible')) closeModal();
            });

            function escHtml(str) { return $('<div>').text(String(str)).html(); }

        })(jQuery);
        </script>
        <?php
    }
}
