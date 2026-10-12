<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RDR_Collector {

    public static function init() {
        /* Sert le Service Worker depuis une URL WordPress */
        add_action('init', array(__CLASS__,'maybe_serve_sw'));
    }

    /* ------------------------------------------------------------------ */
    /* Service Worker — servi via ?rdr_cyber_sw=1                          */
    /* ------------------------------------------------------------------ */
    public static function maybe_serve_sw() : void {
        if ( empty($_GET['rdr_cyber_sw']) ) return;
        $file = RDR_CYBER_PLUGIN_DIR . 'assets/js/sw.js';
        if ( ! file_exists($file) ) wp_die('SW not found',404);
        $source = file_get_contents($file);
        $source = str_replace(
            array('__SITE_URL__',       '__AJAX_URL__',              '__PLUGIN_URL__'),
            array(home_url('/'), admin_url('admin-ajax.php'), RDR_CYBER_PLUGIN_URL),
            $source
        );
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Service-Worker-Allowed: /');
        echo $source;
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Définition complète de toutes les sources disponibles               */
    /* ------------------------------------------------------------------ */
    public static function get_all_sources() : array {
        return array(
            /* ---- CERT-FR ---- */
            array( 'id'=>'certfr_alerte',   'group'=>'CERT-FR', 'label'=>'CERT-FR — Alertes',            'url'=>'https://www.cert.ssi.gouv.fr/alerte/feed/',    'source'=>'CERT-FR',        'type'=>'alerte',        'severity'=>'high',     'default'=>true,  'timeout'=>30 ),
            array( 'id'=>'certfr_avis',     'group'=>'CERT-FR', 'label'=>'CERT-FR — Avis',               'url'=>'https://www.cert.ssi.gouv.fr/avis/feed/',      'source'=>'CERT-FR',        'type'=>'avis',          'severity'=>'medium',   'default'=>true,  'timeout'=>30 ),
            array( 'id'=>'certfr_ioc',      'group'=>'CERT-FR', 'label'=>'CERT-FR — IOC',                'url'=>'https://www.cert.ssi.gouv.fr/ioc/feed/',       'source'=>'CERT-FR',        'type'=>'ioc',           'severity'=>'high',     'default'=>true,  'timeout'=>30 ),
            array( 'id'=>'certfr_actu',     'group'=>'CERT-FR', 'label'=>'CERT-FR — Actualités',         'url'=>'https://www.cert.ssi.gouv.fr/actualite/feed/', 'source'=>'CERT-FR',        'type'=>'actualite',     'severity'=>'info',     'default'=>true,  'timeout'=>30 ),
            array( 'id'=>'certfr_dur',      'group'=>'CERT-FR', 'label'=>'CERT-FR — Durée critique',     'url'=>'https://www.cert.ssi.gouv.fr/dur/feed/',       'source'=>'CERT-FR',        'type'=>'dur',           'severity'=>'critical', 'default'=>true,  'timeout'=>30 ),
            /* ---- NVD ---- */
            array( 'id'=>'nvd_cve',         'group'=>'NVD',     'label'=>'NVD — CVE récents (API v2)',   'url'=>'nvd_api',                                      'source'=>'NVD',            'type'=>'vulnerability', 'severity'=>'',         'default'=>true,  'timeout'=>30 ),
            /* ---- SANS Internet Storm Center ---- */
            array( 'id'=>'sans_isc',        'group'=>'SANS',    'label'=>'SANS ISC — Diary',             'url'=>'https://isc.sans.edu/rssfeed_full.xml',         'source'=>'SANS ISC',       'type'=>'actualite',     'severity'=>'info',     'default'=>false, 'timeout'=>30 ),
            /* ---- Bleeping Computer ---- */
            array( 'id'=>'bleepingcomp',    'group'=>'Media',   'label'=>'Bleeping Computer — Security', 'url'=>'https://www.bleepingcomputer.com/feed/',        'source'=>'BleepingComputer','type'=>'actualite',    'severity'=>'info',     'default'=>false, 'timeout'=>30 ),
            /* ---- SecurityWeek ---- */
            array( 'id'=>'securityweek',    'group'=>'Media',   'label'=>'SecurityWeek',                 'url'=>'https://feeds.feedburner.com/securityweek',     'source'=>'SecurityWeek',   'type'=>'actualite',     'severity'=>'info',     'default'=>false, 'timeout'=>30 ),
            /* ---- The Hacker News ---- */
            array( 'id'=>'hackernews',      'group'=>'Media',   'label'=>'The Hacker News',              'url'=>'https://feeds.feedburner.com/TheHackersNews',   'source'=>'HackerNews',     'type'=>'actualite',     'severity'=>'info',     'default'=>false, 'timeout'=>30 ),
            /* ---- CISA — timeout élevé car serveur lent ---- */
            array( 'id'=>'cisa_all',        'group'=>'CISA',    'label'=>'CISA — All Advisories',        'url'=>'https://www.cisa.gov/cybersecurity-advisories/all.xml',         'source'=>'CISA',     'type'=>'alerte',        'severity'=>'high',     'default'=>false, 'timeout'=>45 ),
            array( 'id'=>'cisa_ics',        'group'=>'CISA',    'label'=>'CISA — ICS Advisories',        'url'=>'https://www.cisa.gov/cybersecurity-advisories/ics-advisories.xml','source'=>'CISA-ICS', 'type'=>'avis',          'severity'=>'medium',   'default'=>false, 'timeout'=>45 ),
            /* ---- Full Disclosure ---- */
            array( 'id'=>'full_disclosure', 'group'=>'Listes',  'label'=>'Full Disclosure (Openwall)',   'url'=>'https://seclists.org/rss/fulldisclosure.rss',   'source'=>'Full Disclosure','type'=>'vulnerability', 'severity'=>'medium',   'default'=>false, 'timeout'=>30 ),
        );
    }

    /* Retourne les sources actuellement activées */
    public static function get_active_sources() : array {
        $all     = self::get_all_sources();
        $enabled = get_option( 'rdr_cyber_sources_enabled', null );
        if ( $enabled === null ) return array_filter( $all, fn($s) => $s['default'] );
        return array_filter( $all, fn($s) => ! empty( $enabled[ $s['id'] ] ) );
    }

    /* Retourne un map id → définition */
    public static function get_sources_map() : array {
        $map = array();
        foreach (self::get_all_sources() as $s) $map[$s['id']] = $s;
        return $map;
    }

    /* ------------------------------------------------------------------ */
    /* Point d'entrée principal                                             */
    /* ------------------------------------------------------------------ */
    public static function collect_all() : int {
        $inserted = 0;
        foreach ( self::get_active_sources() as $src ) {
            if ( $src['url'] === 'nvd_api' ) $inserted += self::collect_nvd();
            else                             $inserted += self::collect_feed( $src );
        }
        update_option( 'rdr_cyber_last_collected', current_time('mysql') );

        /* Déclenche les notifications push si de nouveaux items ont été collectés */
        if ( $inserted > 0 && RDR_Push::is_available() ) {
            $recent = RDR_Database::get_recent_items(1,'critical');
            if ($recent) {
                $item = $recent[0];
                RDR_Push::send_all(array(
                    'title' => 'Nouvelle alerte — '.$item['source'],
                    'body'  => mb_substr(wp_strip_all_tags($item['title']),0,80),
                    'url'   => $item['link'] ?: home_url('/'),
                ));
            }
        }
        return $inserted;
    }

    /* ------------------------------------------------------------------ */
    /* Collecte générique RSS/Atom                                          */
    /* ------------------------------------------------------------------ */
    private static function collect_feed( array $feed_def ) : int {
        $timeout = (int)($feed_def['timeout'] ?? 30);
        $args = array(
            'timeout'    => $timeout,
            'user-agent' => 'Mozilla/5.0 (compatible; RDR-IT Cyber Dashboard/' . RDR_CYBER_VERSION . '; WordPress/' . get_bloginfo('version') . '; +https://rdr-it.com)',
            'headers'    => array(
                'Accept'          => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
                'Accept-Language' => 'fr,en;q=0.9',
                'Cache-Control'   => 'no-cache',
            ),
            'sslverify' => true,
        );

        $response = wp_remote_get( $feed_def['url'], $args );
        if ( is_wp_error( $response ) ) {
            error_log( '[RDR Cyber] Feed error ' . $feed_def['url'] . ': ' . $response->get_error_message() );
            return 0;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( '[RDR Cyber] Feed HTTP ' . $code . ' for ' . $feed_def['url'] );
            return 0;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) return 0;

        /* Nettoyage BOM et encoding */
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        if ( preg_match('/encoding=["\']([^"\']+)["\']/i', $body, $m) && strtolower($m[1]) !== 'utf-8' ) {
            $body = mb_convert_encoding($body, 'UTF-8', $m[1]);
            $body = preg_replace('/encoding="[^"]+"/i', 'encoding="UTF-8"', $body, 1);
        }

        $prev = libxml_use_internal_errors( true );
        $xml  = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $xml ) {
            error_log( '[RDR Cyber] XML parse fail for ' . $feed_def['url'] );
            return 0;
        }

        $items = array();
        if ( isset( $xml->channel->item ) ) {
            $items = $xml->channel->item;
        } elseif ( isset( $xml->entry ) ) {
            $items = $xml->entry;
        } elseif ( isset( $xml->channel ) ) {
            $items = $xml->channel->children()->item ?? array();
        }

        $inserted = 0;
        foreach ( $items as $item ) {
            $parsed = self::parse_item( $item, $feed_def );
            if ( $parsed && RDR_Database::insert( $parsed ) ) $inserted++;
        }
        return $inserted;
    }

    private static function parse_item( $item, array $feed_def ) : ?array {
        $ns_dc = $item->children('http://purl.org/dc/elements/1.1/');

        if ( isset( $item->id ) ) {
            $guid     = (string)$item->id;
            $title    = (string)$item->title;
            $desc     = (string)($item->summary ?: $item->content);
            $link_el  = $item->link;
            $link     = '';
            if ( $link_el ) {
                $href = (string)($link_el['href'] ?? '');
                $link = $href ?: (string)$link_el;
            }
            $date_str = (string)($item->published ?: $item->updated);
        } else {
            $guid     = (string)($item->guid ?: $item->link);
            $title    = (string)$item->title;
            $desc     = (string)($item->description ?: ($ns_dc->description ?? ''));
            $link_raw = (string)$item->link;
            $link     = trim($link_raw) ?: '';
            $date_str = (string)($item->pubDate ?: ($item->date ?? ($ns_dc->date ?? '')));
        }

        if ( ! $guid || ! $title ) return null;

        $title = html_entity_decode( wp_strip_all_tags($title), ENT_QUOTES, 'UTF-8' );
        $desc  = html_entity_decode( wp_strip_all_tags($desc),  ENT_QUOTES, 'UTF-8' );
        $pub_date = $date_str ? gmdate('Y-m-d H:i:s', strtotime($date_str)) : current_time('mysql');

        $cve_id = null;
        if ( preg_match('/CVE-\d{4}-\d{4,7}/i', $title . ' ' . $desc, $m) ) $cve_id = strtoupper($m[0]);

        $severity = $feed_def['severity'];
        $lc = strtolower($title . ' ' . $desc);
        if ( $severity !== 'critical' && (strpos($lc,'critique')!==false||strpos($lc,'critical')!==false||strpos($lc,'0-day')!==false) ) $severity='critical';
        elseif ( in_array($severity,array('info','medium'),true) && (strpos($lc,'élevé')!==false||strpos($lc,'high severity')!==false) ) $severity='high';

        $tags = array();
        if ( isset($item->category) ) foreach ($item->category as $c) { $v = trim((string)$c); if($v) $tags[] = $v; }

        return array(
            'guid'=>$guid,'title'=>$title,'description'=>$desc,'link'=>$link,
            'source'=>$feed_def['source'],'item_type'=>$feed_def['type'],
            'severity'=>$severity,'cve_id'=>$cve_id,
            'tags'=>implode(',', array_unique(array_filter($tags))),
            'pub_date'=>$pub_date,
        );
    }

    /* ------------------------------------------------------------------ */
    /* NVD CVE API v2                                                       */
    /* ------------------------------------------------------------------ */
    private static function collect_nvd() : int {
        $weekAgo  = gmdate('Y-m-d\TH:i:s.000', strtotime('-7 days'));
        $now      = gmdate('Y-m-d\TH:i:s.000');
        $url      = add_query_arg(array('pubStartDate'=>$weekAgo,'pubEndDate'=>$now,'resultsPerPage'=>50),
                        'https://services.nvd.nist.gov/rest/json/cves/2.0');
        $args = array(
            'timeout'    => 30,
            'user-agent' => 'RDR-IT Cyber Dashboard/'.RDR_CYBER_VERSION,
            'headers'    => array(),
        );
        $key = get_option('rdr_cyber_nvd_key','');
        if ($key) $args['headers']['apiKey'] = $key;

        $response = wp_remote_get($url, $args);
        if ( is_wp_error($response) ) { error_log('[RDR Cyber] NVD: '.$response->get_error_message()); return 0; }
        if ( wp_remote_retrieve_response_code($response) !== 200 ) return 0;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ( empty($data['vulnerabilities']) ) return 0;

        $inserted = 0;
        foreach ( $data['vulnerabilities'] as $vuln ) {
            $cve    = $vuln['cve'] ?? array();
            $cve_id = $cve['id'] ?? '';
            if (!$cve_id) continue;
            $desc = '';
            foreach ($cve['descriptions']??array() as $d) if ($d['lang']==='en'){ $desc=$d['value']; break; }
            $cvss_score=null; $severity='info';
            foreach (array('cvssMetricV31','cvssMetricV30','cvssMetricV2') as $mk) {
                if (!empty($cve['metrics'][$mk][0])){
                    $m = $cve['metrics'][$mk][0];
                    $cvss_score = floatval($m['cvssData']['baseScore']??0);
                    $severity   = strtolower($m['cvssData']['baseSeverity']??'info');
                    break;
                }
            }
            $tags=array();
            foreach ($cve['weaknesses']??array() as $w) foreach ($w['description']??array() as $wd) if($wd['lang']==='en') $tags[]=$wd['value'];
            if ( RDR_Database::insert(array(
                'guid'=>'nvd:'.$cve_id,'title'=>$cve_id.($desc?' — '.mb_substr($desc,0,100).'…':''),
                'description'=>$desc,'link'=>'https://nvd.nist.gov/vuln/detail/'.$cve_id,
                'source'=>'NVD','item_type'=>'vulnerability','severity'=>$severity?:'info',
                'cvss_score'=>$cvss_score,'cve_id'=>$cve_id,
                'tags'=>implode(',',array_unique($tags)),
                'pub_date'=>isset($cve['published'])?gmdate('Y-m-d H:i:s',strtotime($cve['published'])):current_time('mysql'),
            )) ) $inserted++;
        }
        return $inserted;
    }
}
