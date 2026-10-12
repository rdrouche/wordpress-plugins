<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RDR_Cron {

    const HOOK_COLLECT = 'rdr_cyber_collect_cron';
    const HOOK_DAILY   = 'rdr_cyber_email_daily';
    const HOOK_WEEKLY  = 'rdr_cyber_email_weekly';
    const HOOK_QUEUE   = 'rdr_cyber_send_queue';

    /* Appelé très tôt — doit être sur add_filter, hors plugins_loaded */
    public static function add_schedules( array $schedules ) : array {
        $schedules['rdr_hourly'] = array(
            'interval' => HOUR_IN_SECONDS,
            'display'  => 'Toutes les heures',
        );
        $schedules['rdr_4hours'] = array(
            'interval' => 4 * HOUR_IN_SECONDS,
            'display'  => 'Toutes les 4 heures',
        );
        $schedules['rdr_5min'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Toutes les 5 minutes (envoi email)',
        );
        return $schedules;
    }

    /* Appelé sur plugins_loaded — enregistre les actions cron */
    public static function init() {
        add_action( self::HOOK_COLLECT, array( 'RDR_Collector', 'collect_all' ) );
        add_action( self::HOOK_DAILY,   array( 'RDR_Email',     'queue_daily_digest' ) );
        add_action( self::HOOK_WEEKLY,  array( 'RDR_Email',     'queue_weekly_digest' ) );
        add_action( self::HOOK_QUEUE,   array( 'RDR_Email',     'process_queue' ) );
    }

    /* Planification initiale */
    public static function schedule() {
        $interval = get_option('rdr_cyber_interval','twicedaily');
        if ( !wp_next_scheduled(self::HOOK_COLLECT) )
            wp_schedule_event(time(),$interval,self::HOOK_COLLECT);

        /* Email quotidien 8h */
        if ( !wp_next_scheduled(self::HOOK_DAILY) ) {
            $tomorrow_8 = mktime(8,0,0,(int)date('n'),(int)date('j')+1);
            wp_schedule_event($tomorrow_8,'daily',self::HOOK_DAILY);
        }
        /* Email hebdo prochain jeudi 8h */
        if ( !wp_next_scheduled(self::HOOK_WEEKLY) )
            wp_schedule_event(self::next_thursday(),'weekly',self::HOOK_WEEKLY);

        /* Traitement de la file toutes les 5 min */
        if ( !wp_next_scheduled(self::HOOK_QUEUE) )
            wp_schedule_event(time(),'rdr_5min',self::HOOK_QUEUE);
    }

    public static function unschedule_all() {
        foreach (array(self::HOOK_COLLECT,self::HOOK_DAILY,self::HOOK_WEEKLY,self::HOOK_QUEUE) as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts) wp_unschedule_event($ts,$hook);
        }
    }

    public static function reschedule_collect( string $interval ) {
        $ts = wp_next_scheduled(self::HOOK_COLLECT);
        if ($ts) wp_unschedule_event($ts,self::HOOK_COLLECT);
        wp_schedule_event(time(),$interval,self::HOOK_COLLECT);
    }

    private static function next_thursday() : int {
        $now  = time();
        $dow  = (int)date('N',$now);
        $days = (4-$dow+7)%7;
        if ($days===0) $days=7;
        return mktime(8,0,0,(int)date('n',$now),(int)date('j',$now)+$days);
    }
}
