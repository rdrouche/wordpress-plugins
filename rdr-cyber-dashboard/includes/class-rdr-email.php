<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RDR_Email {

    const BATCH_SIZE = 30;

    public static function init() {
        add_action('init', array(__CLASS__,'handle_confirm'));
        add_action('init', array(__CLASS__,'handle_unsubscribe'));
        add_action('init', array(__CLASS__,'handle_prefs_page'));
    }

    /* ------------------------------------------------------------------ */
    /* Abonnement — double opt-in                                           */
    /* ------------------------------------------------------------------ */
    public static function subscribe( string $email, string $name, string $frequency ) : true|WP_Error {
        if (!is_email($email)) return new WP_Error('invalid_email','Adresse email invalide.');
        global $wpdb;
        $table    = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s LIMIT 1",$email),ARRAY_A);
        if ($existing && $existing['confirmed']) return new WP_Error('already_subscribed','Cette adresse est déjà abonnée. Utilisez votre lien de préférences pour modifier les réglages.');

        $confirm_token     = wp_generate_password(32,false);
        $unsubscribe_token = wp_generate_password(32,false);
        $pref_token        = wp_generate_password(40,false);

        $ok = RDR_Database::add_subscriber(compact('email','name','frequency','confirm_token','unsubscribe_token','pref_token'));
        if (!$ok) return new WP_Error('db_error',"Erreur lors de l'enregistrement.");
        self::send_confirmation_email($email,$name,$confirm_token);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Email de confirmation                                                */
    /* ------------------------------------------------------------------ */
    private static function send_confirmation_email( string $email, string $name, string $token ) : void {
        $url   = add_query_arg(array('rdr_cyber_confirm'=>$token),home_url('/'));
        $blog  = get_bloginfo('name');
        $body  = self::wrap_email(
            'Confirmez votre abonnement',
            '<p>Bonjour'.($name?' '.esc_html($name):'').',</p>
            <p>Vous avez demandé à recevoir les alertes cybersécurité de <strong>'.esc_html($blog).'</strong>.</p>
            <p>Cliquez sur le bouton ci-dessous pour confirmer votre abonnement :</p>',
            $url, 'Confirmer mon abonnement',
            '<p style="color:#8b949e;font-size:12px">Si vous n\'avez pas demandé cet abonnement, ignorez simplement cet email.</p>'
        );
        self::raw_mail($email,'['.esc_html($blog).'] Confirmez votre abonnement aux alertes cyber',$body);
    }

    /* ------------------------------------------------------------------ */
    /* Traitement confirmation                                              */
    /* ------------------------------------------------------------------ */
    public static function handle_confirm() : void {
        if (empty($_GET['rdr_cyber_confirm'])) return;
        $token = sanitize_text_field($_GET['rdr_cyber_confirm']);
        $ok    = RDR_Database::confirm_subscriber($token);
        $sub   = RDR_Database::get_subscriber_by_field('confirm_token',$token);
        $pref_url = $sub ? add_query_arg(array('rdr_cyber_prefs'=>$sub['pref_token']),home_url('/')) : '';
        $msg   = $ok ? 'Votre abonnement a été confirmé. Merci !' : 'Lien invalide ou déjà utilisé.';
        wp_die('<div style="font-family:sans-serif;max-width:480px;margin:60px auto;text-align:center">
            <h2>Cyber Alertes</h2><p>'.esc_html($msg).'</p>'
            .($ok && $pref_url ? '<p><a href="'.esc_url($pref_url).'" style="color:#2ea8ff">Personnaliser mes préférences →</a></p>' : '')
            .'<a href="'.esc_url(home_url('/')).'">Retour au site</a></div>',
            'Abonnement confirmé', array('response'=>200));
    }

    /* ------------------------------------------------------------------ */
    /* Désabonnement                                                        */
    /* ------------------------------------------------------------------ */
    public static function handle_unsubscribe() : void {
        if (empty($_GET['rdr_cyber_unsub'])) return;
        $token = sanitize_text_field($_GET['rdr_cyber_unsub']);
        $ok    = RDR_Database::delete_by_token($token);
        $msg   = $ok ? 'Vous avez été désabonné avec succès.' : 'Lien invalide ou déjà utilisé.';
        wp_die('<div style="font-family:sans-serif;max-width:480px;margin:60px auto;text-align:center">
            <h2>Désabonnement</h2><p>'.esc_html($msg).'</p>
            <a href="'.esc_url(home_url('/')).'">Retour au site</a></div>',
            'Désabonnement', array('response'=>200));
    }

    /* ------------------------------------------------------------------ */
    /* Page de préférences (magic link)                                    */
    /* ------------------------------------------------------------------ */
    public static function handle_prefs_page() : void {
        if (empty($_GET['rdr_cyber_prefs'])) return;
        $token = sanitize_text_field($_GET['rdr_cyber_prefs']);
        $sub   = RDR_Database::get_subscriber_by_field('pref_token',$token);
        if (!$sub) {
            wp_die('<div style="font-family:sans-serif;max-width:480px;margin:60px auto;text-align:center"><h2>Lien invalide</h2><p>Ce lien de préférences est invalide ou a expiré.</p><a href="'.esc_url(home_url('/')).'">Retour au site</a></div>', 'Préférences invalides', array('response'=>404));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rdr_prefs_nonce']) && wp_verify_nonce($_POST['rdr_prefs_nonce'],'rdr_prefs_'.$token)) {
            $frequency    = sanitize_text_field($_POST['frequency']??'weekly');
            $min_severity = sanitize_text_field($_POST['min_severity']??'');
            $sources_raw  = $_POST['pref_sources']??array();
            $pref_sources = is_array($sources_raw) ? array_map('sanitize_text_field',$sources_raw) : null;
            RDR_Database::update_subscriber_prefs($token, array(
                'frequency'=>$frequency,'pref_min_severity'=>$min_severity,'pref_sources'=>$pref_sources?:null,
            ));
            $sub = RDR_Database::get_subscriber_by_field('pref_token',$token);
            $saved = true;
        }

        $all_active_sources = RDR_Collector::get_active_sources();
        $sub_sources = $sub['pref_sources'] ? json_decode($sub['pref_sources'],true) : null;
        include RDR_CYBER_PLUGIN_DIR . 'templates/preferences-form.php';
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Mise en file — Digest QUOTIDIEN                                      */
    /* ------------------------------------------------------------------ */
    public static function queue_daily_digest() : void {
        $since       = date('Y-m-d H:i:s',strtotime('-24 hours'));
        $subscribers = RDR_Database::get_subscribers('daily',true);
        if (!$subscribers) return;

        $blog    = get_bloginfo('name');
        $subject = '['.esc_html($blog).'] Alertes cyber du '.date_i18n('d/m/Y');

        foreach ($subscribers as $sub) {
            $sources  = $sub['pref_sources'] ? json_decode($sub['pref_sources'],true) : array();
            $min_sev  = $sub['pref_min_severity']?:'medium';
            $items    = RDR_Database::get_items_since($since,$min_sev,$sources);
            if (!$items) continue;
            $body = self::build_digest_body($items,$sub,'Récapitulatif quotidien — '.date_i18n('d/m/Y'));
            RDR_Database::queue_email((int)$sub['id'],$sub['email'],$subject,$body);
        }
        wp_schedule_single_event(time()+5,RDR_Cron::HOOK_QUEUE);
    }

    /* ------------------------------------------------------------------ */
    /* Mise en file — Digest HEBDOMADAIRE                                   */
    /* ------------------------------------------------------------------ */
    public static function queue_weekly_digest() : void {
        $since       = date('Y-m-d H:i:s',strtotime('-7 days'));
        $subscribers = RDR_Database::get_subscribers('weekly',true);
        if (!$subscribers) return;

        $blog    = get_bloginfo('name');
        $subject = '['.esc_html($blog).'] Bulletin cyber hebdo — semaine du '.date_i18n('d/m/Y',strtotime('last monday'));

        foreach ($subscribers as $sub) {
            $sources = $sub['pref_sources'] ? json_decode($sub['pref_sources'],true) : array();
            $min_sev = $sub['pref_min_severity']?:'medium';
            $items   = RDR_Database::get_items_since($since,$min_sev,$sources);
            if (!$items) continue;
            $body = self::build_digest_body($items,$sub,'Bulletin hebdomadaire cybersécurité');
            RDR_Database::queue_email((int)$sub['id'],$sub['email'],$subject,$body);
        }
        wp_schedule_single_event(time()+5,RDR_Cron::HOOK_QUEUE);
    }

    /* ------------------------------------------------------------------ */
    /* Traitement de la file (cron toutes les 5 min)                        */
    /* ------------------------------------------------------------------ */
    public static function process_queue() : void {
        $batch = RDR_Database::pop_queue(self::BATCH_SIZE);
        if (!$batch) { RDR_Database::purge_queue(); return; }

        foreach ($batch as $job) {
            $ok = self::raw_mail($job['to_email'],$job['subject'],$job['body']);
            if ($ok) {
                RDR_Database::mark_queue_sent((int)$job['id']);
                RDR_Database::update_last_sent((int)$job['subscriber_id']);
            } else {
                RDR_Database::mark_queue_failed((int)$job['id']);
            }
        }
        RDR_Database::purge_queue();
    }

    /* ------------------------------------------------------------------ */
    /* Email de test (admin) — digest factice avec les 5 derniers items    */
    /* ------------------------------------------------------------------ */
    public static function build_test_digest() : string {
        $items = RDR_Database::get_recent_items(5,'');
        $sub_fake = array(
            'unsubscribe_token' => 'test',
            'pref_token'        => 'test',
            'name'              => 'Administrateur',
        );
        if (!$items) {
            return self::wrap_email(
                'Email de test',
                '<p>Administrateur,</p><p>Ceci est un email de test. Aucun item n\'est encore collecté.</p>',
                home_url('/'), 'Voir le dashboard',
                '<p style="color:#484f58;font-size:11px;text-align:center">[Email de test — RDR Cyber Dashboard v'.RDR_CYBER_VERSION.']</p>'
            );
        }
        return self::build_digest_body($items,$sub_fake,'[TEST] Digest cybersécurité');
    }

    /* ------------------------------------------------------------------ */
    /* Construction HTML du digest                                          */
    /* ------------------------------------------------------------------ */
    private static function build_digest_body( array $items, array $sub, string $heading ) : string {
        $unsub_url = $sub['unsubscribe_token'] !== 'test'
            ? add_query_arg(array('rdr_cyber_unsub'=>$sub['unsubscribe_token']),home_url('/'))
            : home_url('/');
        $prefs_url = $sub['pref_token'] !== 'test'
            ? add_query_arg(array('rdr_cyber_prefs'=>$sub['pref_token']),home_url('/'))
            : home_url('/');
        $blog      = get_bloginfo('name');
        $sev_colors= array('critical'=>'#ff4757','high'=>'#ff6b35','medium'=>'#f5a623','low'=>'#2ecc71','info'=>'#2ea8ff');
        $sev_labels= array('critical'=>'Critique','high'=>'Haute','medium'=>'Moyenne','low'=>'Faible','info'=>'Info');

        $cards = '';
        foreach (array_slice($items,0,20) as $item) {
            $sev   = $item['severity']??'info';
            $color = $sev_colors[$sev]??'#2ea8ff';
            $label = $sev_labels[$sev]??ucfirst($sev);
            $link  = esc_url($item['link']?:home_url('/'));
            $title = esc_html($item['title']);
            $desc  = esc_html(mb_substr(wp_strip_all_tags($item['description']??''),0,160)).'…';
            $date  = $item['pub_date']?date_i18n('d/m/Y H:i',strtotime($item['pub_date'])):'';
            $cve   = $item['cve_id']?'<span style="font-family:monospace;font-size:11px;background:#0d1117;color:#00e5c0;padding:1px 6px;border-radius:3px">'.esc_html($item['cve_id']).'</span> ':'';
            $cards.='<div style="background:#1c2128;border:1px solid rgba(255,255,255,0.07);border-left:3px solid '.$color.';border-radius:8px;padding:16px;margin-bottom:12px">
                <div style="margin-bottom:8px">'.$cve.'<span style="background:'.$color.'22;color:'.$color.';border:1px solid '.$color.'44;border-radius:4px;padding:1px 7px;font-size:10px;font-weight:700;text-transform:uppercase">'.$label.'</span> <span style="color:#484f58;font-size:11px;margin-left:8px">'.esc_html($item['source']).' — '.$date.'</span></div>
                <a href="'.$link.'" style="color:#e6edf3;font-weight:700;font-size:14px;text-decoration:none">'.$title.'</a>
                <p style="color:#8b949e;font-size:12px;margin:6px 0 8px">'.$desc.'</p>
                <a href="'.$link.'" style="color:#2ea8ff;font-size:12px;font-weight:600;text-decoration:none">Voir le détail →</a>
            </div>';
        }
        $count    = count($items);
        $greeting = ($sub['name']??'') ? 'Bonjour '.esc_html($sub['name']).',' : 'Bonjour,';

        return self::wrap_email(
            $heading,
            '<p>'.$greeting.'</p><p>Voici votre récapitulatif : <strong>'.$count.' alerte'.($count>1?'s':'').'</strong> détectée'.($count>1?'s':'').'.</p>'.$cards,
            home_url('/'), 'Voir toutes les alertes',
            '<p style="color:#484f58;font-size:11px;text-align:center;margin-top:20px">
                Vous recevez cet email car vous êtes abonné aux alertes cyber de '.esc_html($blog).'.<br>
                <a href="'.esc_url($prefs_url).'" style="color:#8b949e">Personnaliser mes préférences</a> &nbsp;|&nbsp;
                <a href="'.esc_url($unsub_url).'" style="color:#8b949e">Se désabonner</a>
            </p>'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Template email HTML                                                  */
    /* ------------------------------------------------------------------ */
    public static function wrap_email( string $title, string $content, string $cta_url, string $cta_label, string $footer ) : string {
        $blog = get_bloginfo('name');
        $site = home_url('/');
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0d1117;font-family:Arial,sans-serif">
<div style="max-width:640px;margin:0 auto;padding:20px">
    <div style="text-align:center;padding:24px 0 16px">
        <a href="'.esc_url($site).'" style="text-decoration:none;font-size:22px;font-weight:800;color:#ffffff">'.esc_html($blog).' <span style="color:#2ea8ff">⬡</span></a>
    </div>
    <div style="background:#161b22;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:28px 32px">
        <h2 style="color:#e6edf3;font-size:20px;margin:0 0 16px">'.esc_html($title).'</h2>
        <div style="color:#8b949e;font-size:14px;line-height:1.7">'.$content.'</div>
        <div style="text-align:center;margin:24px 0 8px">
            <a href="'.esc_url($cta_url).'" style="display:inline-block;background:#2ea8ff;color:#0d1117;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none">'.esc_html($cta_label).'</a>
        </div>
    </div>
    '.$footer.'
</div></body></html>';
    }

    /* ------------------------------------------------------------------ */
    /* Envoi direct wp_mail                                                 */
    /* ------------------------------------------------------------------ */
    public static function raw_mail( string $to, string $subject, string $body ) : bool {
        $from_email = get_option('rdr_cyber_from_email',get_option('admin_email'));
        $from_name  = get_option('rdr_cyber_from_name',get_bloginfo('name'));
        add_filter('wp_mail_content_type',fn()=>'text/html');
        $headers = array('Content-Type: text/html; charset=UTF-8','From: '.sanitize_text_field($from_name).' <'.sanitize_email($from_email).'>');
        $result  = wp_mail($to,$subject,$body,$headers);
        remove_filter('wp_mail_content_type',fn()=>'text/html');
        return $result;
    }
}
