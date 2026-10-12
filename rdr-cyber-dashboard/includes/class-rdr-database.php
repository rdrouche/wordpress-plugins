<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RDR_Database {

    /* ------------------------------------------------------------------ */
    /* Installation / migration                                             */
    /* ------------------------------------------------------------------ */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* Table principale — items */
        $table = $wpdb->prefix . RDR_CYBER_TABLE;
        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guid        VARCHAR(512)        NOT NULL DEFAULT '',
            title       TEXT                NOT NULL,
            description LONGTEXT,
            link        VARCHAR(1024)       NOT NULL DEFAULT '',
            source      VARCHAR(128)        NOT NULL DEFAULT '',
            item_type   VARCHAR(64)         NOT NULL DEFAULT 'info',
            severity    VARCHAR(32)         NOT NULL DEFAULT 'info',
            cvss_score  FLOAT               DEFAULT NULL,
            cve_id      VARCHAR(32)         DEFAULT NULL,
            tags        TEXT,
            pub_date    DATETIME            DEFAULT NULL,
            collected   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE  KEY uk_guid   (guid(200)),
            KEY     idx_source    (source),
            KEY     idx_type      (item_type),
            KEY     idx_severity  (severity),
            KEY     idx_pub_date  (pub_date)
        ) ENGINE=InnoDB {$charset};" );

        /* Table abonnés email (+ préférences personnelles) */
        $subs = $wpdb->prefix . RDR_CYBER_SUBS_TABLE;
        dbDelta( "CREATE TABLE IF NOT EXISTS {$subs} (
            id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email             VARCHAR(255)        NOT NULL,
            name              VARCHAR(255)        NOT NULL DEFAULT '',
            frequency         VARCHAR(16)         NOT NULL DEFAULT 'weekly',
            pref_sources      TEXT                DEFAULT NULL,
            pref_min_severity VARCHAR(32)         NOT NULL DEFAULT '',
            confirm_token     VARCHAR(64)         NOT NULL DEFAULT '',
            unsubscribe_token VARCHAR(64)         NOT NULL DEFAULT '',
            pref_token        VARCHAR(64)         NOT NULL DEFAULT '',
            confirmed         TINYINT(1)          NOT NULL DEFAULT 0,
            created           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_sent         DATETIME            DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_email      (email),
            UNIQUE KEY uk_pref_token (pref_token)
        ) ENGINE=InnoDB {$charset};" );

        /* File d'attente email (scalable) */
        $queue = $wpdb->prefix . RDR_CYBER_QUEUE_TABLE;
        dbDelta( "CREATE TABLE IF NOT EXISTS {$queue} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT(20) UNSIGNED NOT NULL,
            to_email      VARCHAR(255)        NOT NULL,
            subject       VARCHAR(500)        NOT NULL,
            body          LONGTEXT            NOT NULL,
            status        VARCHAR(16)         NOT NULL DEFAULT 'pending',
            attempts      TINYINT             NOT NULL DEFAULT 0,
            created       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent          DATETIME            DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status  (status),
            KEY idx_created (created)
        ) ENGINE=InnoDB {$charset};" );

        /* Abonnés Web Push (VAPID) */
        $push = $wpdb->prefix . RDR_CYBER_PUSH_TABLE;
        dbDelta( "CREATE TABLE IF NOT EXISTS {$push} (
            id       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint TEXT                NOT NULL,
            p256dh   TEXT                NOT NULL,
            auth     VARCHAR(128)        NOT NULL,
            created  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_endpoint (endpoint(200))
        ) ENGINE=InnoDB {$charset};" );

        update_option( 'rdr_cyber_db_version', RDR_CYBER_VERSION );
    }

    /* ------------------------------------------------------------------ */
    /* Items — insertion                                                    */
    /* ------------------------------------------------------------------ */
    public static function insert( array $item ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . RDR_CYBER_TABLE;
        $guid  = sanitize_text_field( $item['guid'] ?? '' );
        if ( ! $guid ) return false;
        if ( $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE guid = %s LIMIT 1", $guid) ) ) return false;

        $wpdb->insert( $table, array(
            'guid'=>$guid,'title'=>wp_strip_all_tags($item['title']??''),
            'description'=>wp_kses_post($item['description']??''),
            'link'=>esc_url_raw($item['link']??''),'source'=>sanitize_text_field($item['source']??''),
            'item_type'=>sanitize_text_field($item['item_type']??'info'),'severity'=>sanitize_text_field($item['severity']??'info'),
            'cvss_score'=>isset($item['cvss_score'])&&$item['cvss_score']!==null?floatval($item['cvss_score']):null,
            'cve_id'=>sanitize_text_field($item['cve_id']??'')?:null,
            'tags'=>sanitize_text_field($item['tags']??''),
            'pub_date'=>$item['pub_date']??current_time('mysql'),'collected'=>current_time('mysql'),
        ) );
        return (bool) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------ */
    /* Items — requêtes                                                     */
    /* ------------------------------------------------------------------ */
    public static function query_items( string $search='', string $source='', string $severity='', string $type='', int $page=1, int $per_page=12 ) : array {
        global $wpdb;
        $table  = $wpdb->prefix . RDR_CYBER_TABLE;
        $offset = ($page-1)*$per_page;
        $where  = self::build_where($search,$source,$severity,$type);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY pub_date DESC LIMIT %d OFFSET %d",$per_page,$offset),ARRAY_A)?:array();
    }

    public static function count_items( string $search='', string $source='', string $severity='', string $type='' ) : int {
        global $wpdb;
        $table = $wpdb->prefix . RDR_CYBER_TABLE;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} ".self::build_where($search,$source,$severity,$type));
    }

    public static function get_recent_items( int $limit=20, string $min_severity='' ) : array {
        global $wpdb;
        $table = $wpdb->prefix . RDR_CYBER_TABLE;
        $where = '';
        if ($min_severity) {
            $map = array('critical'=>array('critical'),'high'=>array('critical','high'),'medium'=>array('critical','high','medium'));
            $sevs = $map[$min_severity]??array();
            if ($sevs) {
                $ph = implode(',',array_fill(0,count($sevs),'%s'));
                $where = $wpdb->prepare("WHERE severity IN ({$ph})",...$sevs);
            }
        }
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY pub_date DESC LIMIT %d",$limit),ARRAY_A)?:array();
    }

    public static function get_items_since( string $since_date, string $min_severity='', array $sources=array() ) : array {
        global $wpdb;
        $table = $wpdb->prefix . RDR_CYBER_TABLE;
        $conds = array($wpdb->prepare('pub_date >= %s',$since_date));
        if ($min_severity) {
            $map = array('critical'=>array('critical'),'high'=>array('critical','high'),'medium'=>array('critical','high','medium'));
            $sevs = $map[$min_severity]??array();
            if ($sevs) { $ph=implode(',',array_fill(0,count($sevs),'%s')); $conds[]=$wpdb->prepare("severity IN ({$ph})",...$sevs); }
        }
        if ($sources) {
            $ph = implode(',',array_fill(0,count($sources),'%s'));
            $conds[] = $wpdb->prepare("source IN ({$ph})",...$sources);
        }
        $where = 'WHERE '.implode(' AND ',$conds);
        return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY pub_date DESC LIMIT 50",ARRAY_A)?:array();
    }

    private static function build_where( string $search, string $source, string $severity, string $type ) : string {
        global $wpdb;
        $conds = array();
        if ($search) { $like='%'.$wpdb->esc_like($search).'%'; $conds[]=$wpdb->prepare('(title LIKE %s OR description LIKE %s OR cve_id LIKE %s)',$like,$like,$like); }
        if ($source)   $conds[] = $wpdb->prepare('source = %s',   $source);
        if ($severity) $conds[] = $wpdb->prepare('severity = %s', $severity);
        if ($type)     $conds[] = $wpdb->prepare('item_type = %s',$type);
        return $conds ? 'WHERE '.implode(' AND ',$conds) : '';
    }

    public static function get_stats() : array {
        global $wpdb; $t = $wpdb->prefix.RDR_CYBER_TABLE;
        return array(
            'total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'this_week'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE pub_date >= DATE_SUB(NOW(),INTERVAL 7 DAY)"),
            'today'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE DATE(pub_date)=CURDATE()"),
            'critical'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE severity IN ('critical','high')"),
            'by_source'=>$wpdb->get_results("SELECT source,COUNT(*) as cnt FROM {$t} GROUP BY source ORDER BY cnt DESC",ARRAY_A)?:array(),
            'by_severity'=>$wpdb->get_results("SELECT severity,COUNT(*) as cnt FROM {$t} GROUP BY severity ORDER BY FIELD(severity,'critical','high','medium','low','info')",ARRAY_A)?:array(),
            'by_type'=>$wpdb->get_results("SELECT item_type,COUNT(*) as cnt FROM {$t} GROUP BY item_type",ARRAY_A)?:array(),
            'last_collected'=>get_option('rdr_cyber_last_collected',''),
        );
    }

    public static function get_sources() : array {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_TABLE;
        return $wpdb->get_col("SELECT DISTINCT source FROM {$table} ORDER BY source")?:array();
    }

    public static function purge( int $days=90 ) : int {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_TABLE;
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE pub_date < DATE_SUB(NOW(),INTERVAL %d DAY)",$days));
        return (int)$wpdb->rows_affected;
    }

    /* ------------------------------------------------------------------ */
    /* Abonnés email                                                        */
    /* ------------------------------------------------------------------ */
    public static function add_subscriber( array $data ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . RDR_CYBER_SUBS_TABLE;
        return (bool) $wpdb->replace($table, array(
            'email'             => sanitize_email($data['email']),
            'name'              => sanitize_text_field($data['name']??''),
            'frequency'         => in_array($data['frequency']??'',array('daily','weekly'))?$data['frequency']:'weekly',
            'pref_sources'      => null,
            'pref_min_severity' => '',
            'confirm_token'     => $data['confirm_token'],
            'unsubscribe_token' => $data['unsubscribe_token'],
            'pref_token'        => $data['pref_token'],
            'confirmed'         => 0,'created'=>current_time('mysql'),
        ));
    }

    public static function confirm_subscriber( string $token ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        return (bool)$wpdb->update($table,array('confirmed'=>1),array('confirm_token'=>$token,'confirmed'=>0));
    }

    public static function delete_by_token( string $token ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        return (bool)$wpdb->delete($table,array('unsubscribe_token'=>$token));
    }

    public static function delete_subscriber( int $id ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        return (bool)$wpdb->delete($table,array('id'=>$id));
    }

    public static function get_subscribers( string $frequency='', bool $confirmed_only=false ) : array {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        $conds = array();
        if ($confirmed_only) $conds[] = 'confirmed = 1';
        if ($frequency)      $conds[] = $wpdb->prepare('frequency = %s',$frequency);
        $where = $conds ? 'WHERE '.implode(' AND ',$conds) : '';
        return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY created DESC",ARRAY_A)?:array();
    }

    public static function get_subscriber_by_field( string $field, string $value ) : ?array {
        global $wpdb;
        $allowed = array('confirm_token','unsubscribe_token','pref_token','email','id');
        if (!in_array($field,$allowed,true)) return null;
        $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$field} = %s LIMIT 1",$value),ARRAY_A)?:null;
    }

    public static function update_subscriber_prefs( string $pref_token, array $data ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_SUBS_TABLE;
        $update = array();
        if (isset($data['frequency']) && in_array($data['frequency'],array('daily','weekly'),true)) $update['frequency'] = $data['frequency'];
        if (isset($data['pref_min_severity'])) $update['pref_min_severity'] = sanitize_text_field($data['pref_min_severity']);
        if (isset($data['pref_sources']))      $update['pref_sources'] = is_array($data['pref_sources']) ? json_encode($data['pref_sources']) : null;
        if (!$update) return false;
        return (bool)$wpdb->update($table,$update,array('pref_token'=>$pref_token));
    }

    public static function update_last_sent( int $id ) : void {
        global $wpdb;
        $wpdb->update($wpdb->prefix.RDR_CYBER_SUBS_TABLE,array('last_sent'=>current_time('mysql')),array('id'=>$id));
    }

    /* ------------------------------------------------------------------ */
    /* File d'attente email                                                 */
    /* ------------------------------------------------------------------ */
    public static function queue_email( int $subscriber_id, string $to_email, string $subject, string $body ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_QUEUE_TABLE;
        return (bool)$wpdb->insert($table,array(
            'subscriber_id'=>$subscriber_id,'to_email'=>$to_email,
            'subject'=>$subject,'body'=>$body,'status'=>'pending','created'=>current_time('mysql'),
        ));
    }

    public static function pop_queue( int $limit=25 ) : array {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_QUEUE_TABLE;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE status='pending' AND attempts < 3 ORDER BY id ASC LIMIT %d",$limit));
        if (!$ids) return array();
        $ph = implode(',',array_fill(0,count($ids),'%d'));
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='sending', attempts=attempts+1 WHERE id IN ({$ph})",...$ids));
        return $wpdb->get_results("SELECT * FROM {$table} WHERE id IN (".implode(',',$ids).")",ARRAY_A)?:array();
    }

    public static function mark_queue_sent( int $id ) : void {
        global $wpdb;
        $wpdb->update($wpdb->prefix.RDR_CYBER_QUEUE_TABLE,array('status'=>'sent','sent'=>current_time('mysql')),array('id'=>$id));
    }

    public static function mark_queue_failed( int $id ) : void {
        global $wpdb;
        $wpdb->update($wpdb->prefix.RDR_CYBER_QUEUE_TABLE,array('status'=>'failed'),array('id'=>$id));
    }

    public static function get_queue_stats() : array {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_QUEUE_TABLE;
        return array(
            'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'"),
            'sending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='sending'"),
            'sent'   =>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='sent'"),
            'failed' =>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='failed'"),
        );
    }

    public static function purge_queue() : void {
        global $wpdb;
        $wpdb->query("DELETE FROM ".$wpdb->prefix.RDR_CYBER_QUEUE_TABLE." WHERE status='sent' AND sent < DATE_SUB(NOW(),INTERVAL 7 DAY)");
    }

    /* ------------------------------------------------------------------ */
    /* Abonnés Web Push                                                     */
    /* ------------------------------------------------------------------ */
    public static function save_push_subscription( string $endpoint, string $p256dh, string $auth ) : bool {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_PUSH_TABLE;
        return (bool)$wpdb->replace($table,array(
            'endpoint'=>$endpoint,'p256dh'=>$p256dh,'auth'=>$auth,'created'=>current_time('mysql'),
        ));
    }

    public static function delete_push_subscription( string $endpoint ) : void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix.RDR_CYBER_PUSH_TABLE,array('endpoint'=>$endpoint));
    }

    public static function get_push_subscriptions() : array {
        global $wpdb; $table = $wpdb->prefix.RDR_CYBER_PUSH_TABLE;
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC",ARRAY_A)?:array();
    }

    public static function count_push_subscriptions() : int {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM ".$wpdb->prefix.RDR_CYBER_PUSH_TABLE);
    }

    public static function delete_push_subscription_by_id( int $id ) : void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix.RDR_CYBER_PUSH_TABLE,array('id'=>$id));
    }
}
