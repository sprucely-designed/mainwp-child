<?php
/**
 * MainWP Child System Monitor.
 *
 * @since 6.1.4
 *
 * @package     MainWP/Child
 */

namespace MainWP\Child\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * MainWP Child System Monitor.
 */
class MainWP_Child_System_Monitor {

    /**
     * Cron hook.
     *
     * @var string
     */
    const CRON_HOOK = 'mainwp_child_system_monitor_cron';


    /**
     * Init system.
     */
    public static function init() {
        add_action( 'init', array( self::class, 'init_schedule_cron' ) );
        MainWP_Child_System_Monitor_Runner::init();
        MainWP_Child_System_Monitor_UI::init();
    }

    /**
     * Ensure cron is scheduled.
     */
    public static function init_schedule_cron() {

        $recurrence = 'minute';
        $cron_hook  = self::CRON_HOOK;

        /**
         * Filter mainwp_child_is_enable_schedule_job.
         *
         * @since 6.1.4
         */
        $enabled_job = apply_filters( 'mainwp_child_is_enable_schedule_job', true, $cron_hook, $recurrence );

        $sched = wp_next_scheduled( $cron_hook );

        if ( false === $sched ) {
            if ( $enabled_job ) {
                wp_schedule_event( time(), $recurrence, $cron_hook );
            }
        } elseif ( ! $enabled_job ) {
            wp_unschedule_event( $sched, $cron_hook );
        }
    }


    /**
     * Activation hook.
     */
    public static function activate() {

        self::init_schedule_cron();

        // Generate an initial baseline immediately.
        MainWP_Child_System_Monitor_Runner::run_manual();
    }

    /**
     * Deactivation hook.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
