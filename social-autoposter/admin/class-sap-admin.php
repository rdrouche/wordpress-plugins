<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SAP_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
        add_action( 'add_meta_boxes',        [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post',             [ __CLASS__, 'save_meta_box' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_sap_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_sap_delete_item',     [ __CLASS__, 'ajax_delete_item' ] );
    }

    public static function register_menu(): void {
        add_menu_page( 'Social AutoPoster', 'Social AutoPoster', 'manage_options', 'social-autoposter', [ __CLASS__, 'page_queue' ], 'dashicons-share', 30 );
        add_submenu_page( 'social-autoposter', 'File attente', 'File attente', 'manage_options', 'social-autoposter', [ __CLASS__, 'page_queue' ] );
        add_submenu_page( 'social-autoposter', 'Reglages',     'Reglages',     'manage_options', 'sap-settings',      [ __CLASS__, 'page_settings' ] );
    }

    public static function register_settings(): void {
        register_setting( 'sap_linkedin_group',   'sap_linkedin',  [ 'sanitize_callback' => [ __CLASS__, 'sanitize_linkedin'  ] ] );
        register_setting( 'sap_facebook_group',   'sap_facebook',  [ 'sanitize_callback' => [ __CLASS__, 'sanitize_facebook'  ] ] );
        register_setting( 'sap_templates_group',  'sap_templates', [ 'sanitize_callback' => [ __CLASS__, 'sanitize_templates' ] ] );

        add_settings_section( 'sap_linkedin_section',  'LinkedIn',           '__return_false', 'sap-settings' );
        add_settings_section( 'sap_facebook_section',  'Facebook',           '__return_false', 'sap-settings' );
        add_settings_section( 'sap_templates_section', 'Messages par defaut','__return_false', 'sap-settings' );

        add_settings_field( 'li_mode',       'Mode',             [ __CLASS__, 'field_li_mode'       ], 'sap-settings', 'sap_linkedin_section' );
        add_settings_field( 'li_direct',     'API directe',      [ __CLASS__, 'field_li_direct'     ], 'sap-settings', 'sap_linkedin_section' );
        add_settings_field( 'li_zapier',     'Zapier',           [ __CLASS__, 'field_li_zapier'     ], 'sap-settings', 'sap_linkedin_section' );
        add_settings_field( 'li_buffer',     'Buffer',           [ __CLASS__, 'field_li_buffer'     ], 'sap-settings', 'sap_linkedin_section' );
        add_settings_field( 'fb_page_token', 'Page Access Token',[ __CLASS__, 'field_fb_page_token' ], 'sap-settings', 'sap_facebook_section' );
        add_settings_field( 'fb_page_id',    'Page ID',          [ __CLASS__, 'field_fb_page_id'   ], 'sap-settings', 'sap_facebook_section' );
        add_settings_field( 'tpl_linkedin',  'Template LinkedIn',[ __CLASS__, 'field_tpl_linkedin'  ], 'sap-settings', 'sap_templates_section' );
        add_settings_field( 'tpl_facebook',  'Template Facebook',[ __CLASS__, 'field_tpl_facebook'  ], 'sap-settings', 'sap_templates_section' );
    }

    public static function sanitize_linkedin( $i ): array {
        return [
            'mode'              => sanitize_text_field( $i['mode']              ?? 'direct' ),
            'access_token'      => sanitize_text_field( $i['access_token']      ?? '' ),
            'org_urn'           => sanitize_text_field( $i['org_urn']           ?? '' ),
            'zapier_webhook'    => esc_url_raw(          $i['zapier_webhook']    ?? '' ),
            'buffer_token'      => sanitize_text_field( $i['buffer_token']      ?? '' ),
            'buffer_profile_id' => sanitize_text_field( $i['buffer_profile_id'] ?? '' ),
        ];
    }
    public static function sanitize_facebook( $i ): array {
        return [
            'page_token' => sanitize_text_field( $i['page_token'] ?? '' ),
            'page_id'    => sanitize_text_field( $i['page_id']    ?? '' ),
        ];
    }
    public static function sanitize_templates( $i ): array {
        return [
            'linkedin' => wp_kses_post( $i['linkedin'] ?? '' ),
            'facebook' => wp_kses_post( $i['facebook'] ?? '' ),
        ];
    }

    private static function li_opts(): array { return (array) get_option( 'sap_linkedin', [] ); }

    public static function field_li_mode(): void {
        $opts = self::li_opts();
        $mode = $opts['mode'] ?? 'direct';
        $items = [
            'direct' => 'API LinkedIn directe (requiert approbation)',
            'zapier' => 'Zapier Webhook (sans approbation - gratuit)',
            'buffer' => 'Buffer API (sans approbation - gratuit)',
        ];
        foreach ( $items as $val => $label ) {
            $checked = checked( $mode, $val, false );
            $v = esc_attr( $val );
            $l = esc_html( $label );
            echo "<label style=\"display:block;margin-bottom:8px\"><input type=\"radio\" name=\"sap_linkedin[mode]\" value=\"{$v}\" {$checked} onchange=\"document.querySelectorAll('.sap-mode-section').forEach(function(s){s.classList.remove('sap-mode-active')});document.getElementById('sap-li-{$v}').classList.add('sap-mode-active')\"> {$l}</label>\n";
        }
    }

    public static function field_li_direct(): void {
        $opts  = self::li_opts();
        $mode  = $opts['mode'] ?? 'direct';
        $cls   = $mode === 'direct' ? 'sap-mode-section sap-mode-active' : 'sap-mode-section';
        $tok   = esc_attr( $opts['access_token'] ?? '' );
        $urn   = esc_attr( $opts['org_urn']      ?? '' );
        echo "<div id=\"sap-li-direct\" class=\"{$cls}\">\n";
        echo "<table class=\"form-table\" style=\"margin:0\"><tbody>\n";
        echo "<tr><th>Access Token</th><td><input type=\"password\" name=\"sap_linkedin[access_token]\" value=\"{$tok}\" class=\"regular-text\" autocomplete=\"new-password\"></td></tr>\n";
        echo "<tr><th>URN ou ID page</th><td><input type=\"text\" name=\"sap_linkedin[org_urn]\" value=\"{$urn}\" class=\"regular-text\" placeholder=\"urn:li:organization:XXXXXX\"><p class=\"description\">ID numerique visible dans l'URL admin de votre page LinkedIn.</p></td></tr>\n";
        echo "</tbody></table>\n";
        echo "<p class=\"description\" style=\"margin-top:8px\">En attente approbation LinkedIn ? Utilisez le mode Zapier ou Buffer en attendant.</p>\n";
        echo "</div>\n";
    }

    public static function field_li_zapier(): void {
        $opts  = self::li_opts();
        $mode  = $opts['mode'] ?? 'direct';
        $cls   = $mode === 'zapier' ? 'sap-mode-section sap-mode-active' : 'sap-mode-section';
        $wh    = esc_attr( $opts['zapier_webhook'] ?? '' );
        echo "<div id=\"sap-li-zapier\" class=\"{$cls}\">\n";
        echo "<table class=\"form-table\" style=\"margin:0\"><tbody>\n";
        echo "<tr><th>Webhook URL</th><td><input type=\"text\" name=\"sap_linkedin[zapier_webhook]\" value=\"{$wh}\" class=\"large-text\" placeholder=\"https://hooks.zapier.com/hooks/catch/XXXXXXX/XXXXXXX/\"></td></tr>\n";
        echo "</tbody></table>\n";
        echo "<div class=\"notice notice-info inline\" style=\"margin:8px 0\"><p><strong>Setup Zapier :</strong><br>\n";
        echo "1. zapier.com > Create Zap<br>\n";
        echo "2. Trigger : Webhooks by Zapier > Catch Hook > copier l'URL ci-dessus<br>\n";
        echo "3. Action : LinkedIn > Create Company Update<br>\n";
        echo "4. Mapper le champ <code>message</code> du webhook vers le texte du post<br>\n";
        echo "5. Activer le Zap</p></div>\n";
        echo "</div>\n";
    }

    public static function field_li_buffer(): void {
        $opts  = self::li_opts();
        $mode  = $opts['mode'] ?? 'direct';
        $cls   = $mode === 'buffer' ? 'sap-mode-section sap-mode-active' : 'sap-mode-section';
        $tok   = esc_attr( $opts['buffer_token']      ?? '' );
        $pid   = esc_attr( $opts['buffer_profile_id'] ?? '' );
        echo "<div id=\"sap-li-buffer\" class=\"{$cls}\">\n";
        echo "<table class=\"form-table\" style=\"margin:0\"><tbody>\n";
        echo "<tr><th>Buffer Access Token</th><td><input type=\"password\" name=\"sap_linkedin[buffer_token]\" value=\"{$tok}\" class=\"regular-text\" autocomplete=\"new-password\"><p class=\"description\">buffer.com/developers/api > Create Access Token</p></td></tr>\n";
        echo "<tr><th>Buffer Profile ID</th><td><input type=\"text\" name=\"sap_linkedin[buffer_profile_id]\" value=\"{$pid}\" class=\"regular-text\" placeholder=\"XXXXXXXXXXXXXXXXX\"><p class=\"description\">GET https://api.bufferapp.com/1/profiles.json avec votre token</p></td></tr>\n";
        echo "</tbody></table>\n";
        echo "</div>\n";
    }

    public static function field_li_access_token(): void {}
    public static function field_li_org_urn(): void {}

    public static function field_fb_page_token(): void {
        $v = esc_attr( ( (array) get_option( 'sap_facebook' ) )['page_token'] ?? '' );
        echo "<input type=\"password\" name=\"sap_facebook[page_token]\" value=\"{$v}\" class=\"regular-text\" autocomplete=\"new-password\">\n";
        echo "<p class=\"description\">Page Access Token long-lived via Graph API Explorer.</p>\n";
    }
    public static function field_fb_page_id(): void {
        $v = esc_attr( ( (array) get_option( 'sap_facebook' ) )['page_id'] ?? '' );
        echo "<input type=\"text\" name=\"sap_facebook[page_id]\" value=\"{$v}\" class=\"regular-text\" placeholder=\"123456789012345\">\n";
    }

    public static function field_tpl_linkedin(): void {
        $tpls = (array) get_option( 'sap_templates', [] );
        $v    = esc_textarea( $tpls['linkedin'] ?? "Nouvel article !\n\n{{TITRE}}\n\n{{EXTRAIT}}\n\n{{URL_POST}}\n\n#WordPress #Blog" );
        $help = self::tpl_help();
        echo "<textarea name=\"sap_templates[linkedin]\" rows=\"8\" class=\"large-text\" data-network=\"linkedin\">{$v}</textarea>{$help}\n";
    }
    public static function field_tpl_facebook(): void {
        $tpls = (array) get_option( 'sap_templates', [] );
        $v    = esc_textarea( $tpls['facebook'] ?? "Nouvel article !\n\n{{TITRE}}\n\n{{EXTRAIT}}\n\n{{URL_POST}}\n\n#WordPress #Blog" );
        echo "<textarea name=\"sap_templates[facebook]\" rows=\"8\" class=\"large-text\" data-network=\"facebook\">{$v}</textarea>\n";
    }
    private static function tpl_help(): string {
        return "<p class=\"description\"><strong>Variables :</strong> <code>{{URL_POST}}</code> <code>{{TITRE}}</code> <code>{{EXTRAIT}}</code></p>";
    }

    public static function page_settings(): void { ?>
        <div class="wrap sap-wrap">
            <h1><span class="dashicons dashicons-share"></span> Social AutoPoster &mdash; Reglages</h1>
            <div class="sap-tabs">
                <a href="#tab-linkedin" class="sap-tab active">LinkedIn</a>
                <a href="#tab-facebook" class="sap-tab">Facebook</a>
                <a href="#tab-templates" class="sap-tab">Templates</a>
            </div>
            <div id="tab-linkedin" class="sap-tab-content active">
                <form method="post" action="options.php">
                    <?php settings_fields( 'sap_linkedin_group' ); ?>
                    <table class="form-table">
                        <tr><th>Mode</th><td><?php self::field_li_mode(); ?></td></tr>
                        <tr><th></th><td>
                            <?php self::field_li_direct(); ?>
                            <?php self::field_li_zapier(); ?>
                            <?php self::field_li_buffer(); ?>
                        </td></tr>
                    </table>
                    <p>
                        <button type="button" class="button sap-test-btn" data-network="linkedin">Tester la connexion</button>
                        <span class="sap-test-result" id="sap-test-linkedin"></span>
                    </p>
                    <?php submit_button( 'Enregistrer LinkedIn' ); ?>
                </form>
            </div>
            <div id="tab-facebook" class="sap-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( 'sap_facebook_group' ); ?>
                    <table class="form-table">
                        <tr><th>Page Access Token</th><td><?php self::field_fb_page_token(); ?></td></tr>
                        <tr><th>Page ID</th><td><?php self::field_fb_page_id(); ?></td></tr>
                    </table>
                    <p>
                        <button type="button" class="button sap-test-btn" data-network="facebook">Tester la connexion</button>
                        <span class="sap-test-result" id="sap-test-facebook"></span>
                    </p>
                    <?php submit_button( 'Enregistrer Facebook' ); ?>
                </form>
            </div>
            <div id="tab-templates" class="sap-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields( 'sap_templates_group' ); ?>
                    <h2>Template LinkedIn</h2><?php self::field_tpl_linkedin(); ?>
                    <h2>Template Facebook</h2><?php self::field_tpl_facebook(); ?>
                    <?php submit_button( 'Enregistrer les templates' ); ?>
                </form>
            </div>
        </div>
    <?php }

    public static function page_queue(): void {
        $items = SAP_DB::get_all( [ 'limit' => 100 ] );
        ?>
        <div class="wrap sap-wrap">
            <h1><span class="dashicons dashicons-share"></span> Social AutoPoster &mdash; File d'attente</h1>
            <?php if ( empty( $items ) ) : ?>
                <div class="notice notice-info"><p>Aucune publication planifiee. Ouvrez un article pour programmer vos posts.</p></div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped sap-queue-table">
                <thead><tr>
                    <th style="width:20%">Article</th>
                    <th style="width:9%">Reseau</th>
                    <th>Message</th>
                    <th style="width:13%">Planifie le</th>
                    <th style="width:12%">Statut</th>
                    <th style="width:16%">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $items as $item ) :
                    $badges = [
                        'pending'   => '<span class="sap-badge sap-badge-pending">En attente</span>',
                        'published' => '<span class="sap-badge sap-badge-published">Publie</span>',
                        'failed'    => '<span class="sap-badge sap-badge-failed">Echec</span>',
                    ];
                    $icons = [ 'linkedin' => 'LI', 'facebook' => 'FB' ];
                    $badge   = $badges[ $item->status ] ?? esc_html( $item->status );
                    $icon    = $icons[ $item->network ] ?? '?';
                    $excerpt = esc_html( mb_strimwidth( $item->message, 0, 90, '...' ) );
                    $date    = wp_date( 'd/m/Y H:i', strtotime( $item->scheduled_at ) );
                    $fmsg    = esc_attr( $item->message );
                    $ftitle  = esc_attr( $item->post_title ?: '#' . $item->post_id );
                    $fnet    = esc_attr( $item->network );
                    $row_id  = (int) $item->id;
                    $err     = '';
                    if ( $item->status === 'failed' && $item->response ) {
                        $dec = json_decode( $item->response, true );
                        $err = esc_html( mb_strimwidth( $dec['message'] ?? $item->response, 0, 60, '...' ) );
                    }
                    ?>
                    <tr id="sap-row-<?php echo $row_id; ?>">
                        <td><a href="<?php echo esc_url( get_edit_post_link( $item->post_id ) ); ?>"><?php echo esc_html( $item->post_title ?: '#' . $item->post_id ); ?></a></td>
                        <td><?php echo esc_html( $icon . ' ' . ucfirst( $item->network ) ); ?></td>
                        <td><?php echo $excerpt; ?></td>
                        <td><?php echo esc_html( $date ); ?></td>
                        <td><?php echo $badge; ?><?php if ( $err ) echo "<br><small title=\"" . esc_attr( $item->response ) . "\">{$err}</small>"; ?></td>
                        <td>
                            <button type="button" class="button button-small sap-view-msg-btn"
                                data-message="<?php echo $fmsg; ?>"
                                data-network="<?php echo $fnet; ?>"
                                data-post="<?php echo $ftitle; ?>">Message</button>
                            <button type="button" class="button button-small sap-delete-btn" data-id="<?php echo $row_id; ?>" style="margin-top:4px">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div id="sap-modal-overlay" style="display:none">
            <div id="sap-modal">
                <div id="sap-modal-header">
                    <div><span id="sap-modal-network-icon"></span> <strong id="sap-modal-title"></strong></div>
                    <button type="button" id="sap-modal-close">X</button>
                </div>
                <pre id="sap-modal-body"></pre>
                <div id="sap-modal-footer">
                    <button type="button" id="sap-modal-copy" class="button">Copier</button>
                    <button type="button" id="sap-modal-close2" class="button button-primary">Fermer</button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function add_meta_box(): void {
        add_meta_box( 'sap_meta_box', 'Social AutoPoster', [ __CLASS__, 'render_meta_box' ], 'post', 'side', 'default' );
    }

    public static function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'sap_meta_box', 'sap_nonce' );
        $tpls     = (array) get_option( 'sap_templates', [] );
        $tpl_li   = esc_attr( $tpls['linkedin'] ?? '' );
        $tpl_fb   = esc_attr( $tpls['facebook'] ?? '' );
        $tpl_show = esc_textarea( $tpls['linkedin'] ?? '' );
        $post_id  = (int) $post->ID;
        $nonce    = wp_create_nonce( 'sap_schedule_' . $post_id );
        $default_dt = esc_attr( date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ) );
        $history  = SAP_DB::get_by_post( $post_id );
        $emojis   = ['&#x2728;','&#x1F525;','&#x1F4A1;','&#x1F4E2;','&#x1F680;','&#x1F389;','&#x1F449;','&#x1F447;','&#x1F4CC;','&#x2B50;','&#x1F4AC;','&#x1F195;','&#x2705;','&#x274C;','&#x1F537;','&#x1F535;'];
        $emoji_html = '';
        foreach ( $emojis as $e ) $emoji_html .= "<span class=\"sap-emoji\">{$e}</span>";
        ?>
        <div class="sap-metabox">
            <div class="sap-metabox-section">
                <label class="sap-label"><strong>Reseau</strong></label>
                <div class="sap-network-btns">
                    <button type="button" class="sap-net-btn active" data-net="linkedin">LinkedIn</button>
                    <button type="button" class="sap-net-btn" data-net="facebook">Facebook</button>
                </div>
                <input type="hidden" name="sap_network" id="sap_network" value="linkedin">
            </div>
            <div class="sap-metabox-section">
                <label class="sap-label"><strong>Message</strong></label>
                <div class="sap-toolbar">
                    <button type="button" class="sap-fmt-btn" data-fmt="bold"><b>B</b></button>
                    <button type="button" class="sap-fmt-btn" data-fmt="italic"><i>I</i></button>
                    <button type="button" class="sap-fmt-btn" data-fmt="url">URL</button>
                    <button type="button" class="sap-fmt-btn" data-fmt="titre">Titre</button>
                    <button type="button" class="sap-fmt-btn" data-fmt="extrait">Extrait</button>
                    <span class="sap-emoji-trigger" title="Emojis">:-)</span>
                    <div class="sap-emoji-picker" style="display:none"><?php echo $emoji_html; ?></div>
                </div>
                <textarea id="sap_message" name="sap_message" rows="10" placeholder="Votre message..." class="widefat"
                    data-tpllinkedin="<?php echo $tpl_li; ?>"
                    data-tblfacebook="<?php echo $tpl_fb; ?>"><?php echo $tpl_show; ?></textarea>
                <p class="sap-hint">Variables : <code>{{URL_POST}}</code> <code>{{TITRE}}</code> <code>{{EXTRAIT}}</code></p>
            </div>
            <div class="sap-metabox-section">
                <label class="sap-label"><strong>Date de publication</strong></label>
                <input type="datetime-local" id="sap_scheduled_at" name="sap_scheduled_at" class="widefat" value="<?php echo $default_dt; ?>">
            </div>
            <div class="sap-metabox-section">
                <button type="button" id="sap_schedule_btn" class="button button-primary widefat">Programmer la publication</button>
                <div id="sap_feedback" style="margin-top:8px;font-style:italic;font-size:12px;color:#2271b1"></div>
            </div>
            <?php if ( ! empty( $history ) ) : ?>
            <div class="sap-metabox-section sap-history">
                <strong>Historique</strong>
                <?php foreach ( array_slice( $history, 0, 5 ) as $h ) :
                    $st   = $h->status === 'published' ? 'OK' : ( $h->status === 'failed' ? 'ERR' : '...' );
                    $net  = strtoupper( substr( $h->network, 0, 2 ) );
                    $hdate = wp_date( 'd/m H:i', strtotime( $h->scheduled_at ) );
                    echo "<div class=\"sap-history-item\">{$st} {$net} {$hdate}</div>";
                endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var postId = <?php echo $post_id; ?>;
            var nonce  = "<?php echo $nonce; ?>";

            document.querySelectorAll('.sap-net-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.sap-net-btn').forEach(function(b){ b.classList.remove('active'); });
                    this.classList.add('active');
                    var net = this.dataset.net;
                    document.getElementById('sap_network').value = net;
                    var ta = document.getElementById('sap_message');
                    var key = net === 'linkedin' ? 'tpllinkedin' : 'tblfacebook';
                    ta.value = ta.dataset[key] || '';
                });
            });

            document.querySelectorAll('.sap-fmt-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var ta  = document.getElementById('sap_message');
                    var fmt = this.dataset.fmt;
                    var s = ta.selectionStart, e = ta.selectionEnd;
                    var sel = ta.value.substring(s, e);
                    var ins = '';
                    if      (fmt === 'bold')    ins = sel || '**texte gras**';
                    else if (fmt === 'italic')  ins = sel ? '_' + sel + '_' : '_texte italique_';
                    else if (fmt === 'url')     ins = '{{URL_POST}}';
                    else if (fmt === 'titre')   ins = '{{TITRE}}';
                    else if (fmt === 'extrait') ins = '{{EXTRAIT}}';
                    ta.setRangeText(ins, s, e, 'end');
                    ta.focus();
                });
            });

            document.querySelector('.sap-emoji-trigger').addEventListener('click', function(){
                var p = document.querySelector('.sap-emoji-picker');
                p.style.display = p.style.display === 'none' ? 'flex' : 'none';
            });
            document.querySelectorAll('.sap-emoji').forEach(function(em){
                em.addEventListener('click', function(){
                    var ta = document.getElementById('sap_message');
                    var pos = ta.selectionStart;
                    ta.setRangeText(this.textContent, pos, pos, 'end');
                    document.querySelector('.sap-emoji-picker').style.display = 'none';
                    ta.focus();
                });
            });

            document.getElementById('sap_schedule_btn').addEventListener('click', function(){
                var fb = document.getElementById('sap_feedback');
                fb.textContent = 'Planification en cours...';
                var data = new FormData();
                data.append('action',       'sap_schedule_post');
                data.append('nonce',        nonce);
                data.append('post_id',      postId);
                data.append('network',      document.getElementById('sap_network').value);
                data.append('message',      document.getElementById('sap_message').value);
                data.append('scheduled_at', document.getElementById('sap_scheduled_at').value);
                fetch(ajaxurl, { method:'POST', body: data })
                    .then(function(r){ return r.json(); })
                    .then(function(res){ fb.textContent = res.success ? 'OK : ' + res.data : 'Erreur : ' + (res.data || '?'); })
                    .catch(function(){ fb.textContent = 'Erreur reseau'; });
            });
        })();
        </script>
        <?php
    }

    public static function save_meta_box( int $post_id, WP_Post $post ): void {}

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'sap_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $network = sanitize_text_field( $_POST['network'] ?? '' );
        $result  = $network === 'linkedin' ? SAP_LinkedIn::test_connection() : ( $network === 'facebook' ? SAP_Facebook::test_connection() : new WP_Error( 'sap', 'Reseau inconnu' ) );
        is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( 'Connecte : ' . $result );
    }

    public static function ajax_delete_item(): void {
        check_ajax_referer( 'sap_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( $id ) SAP_DB::delete( $id );
        wp_send_json_success();
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'toplevel_page_social-autoposter', 'social-autoposter_page_sap-settings' ], true ) ) return;
        wp_enqueue_style(  'sap-admin', SAP_PLUGIN_URL . 'assets/css/admin.css', [], SAP_VERSION );
        wp_enqueue_script( 'sap-admin', SAP_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], SAP_VERSION, true );
        wp_localize_script( 'sap-admin', 'sapData', [ 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sap_admin_nonce' ) ] );
    }
}

add_action( 'wp_ajax_sap_schedule_post', function() {
    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    check_ajax_referer( 'sap_schedule_' . $post_id, 'nonce' );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'Permission refusee.' );

    $network      = sanitize_text_field( $_POST['network'] ?? '' );
    $message      = sanitize_textarea_field( $_POST['message'] ?? '' );
    $scheduled_at = sanitize_text_field( $_POST['scheduled_at'] ?? '' );

    if ( empty( $network ) || empty( $message ) || empty( $scheduled_at ) ) {
        wp_send_json_error( 'Tous les champs sont obligatoires.' );
    }

    $dt = new DateTime( $scheduled_at, new DateTimeZone( wp_timezone_string() ) );
    $dt->setTimezone( new DateTimeZone( 'UTC' ) );

    $id = SAP_DB::insert( [
        'post_id'      => $post_id,
        'network'      => $network,
        'message'      => $message,
        'scheduled_at' => $dt->format( 'Y-m-d H:i:s' ),
    ] );

    if ( $id ) {
        $label = $network === 'linkedin' ? 'LinkedIn' : 'Facebook';
        wp_send_json_success( "Publication {$label} planifiee pour le " . date( 'd/m/Y a H:i', strtotime( $scheduled_at ) ) . '.' );
    } else {
        wp_send_json_error( 'Erreur enregistrement.' );
    }
} );
