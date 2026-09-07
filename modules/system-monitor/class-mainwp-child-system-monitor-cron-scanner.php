<?php
/**
 * MainWP Child System Monitor Cron Scanner.
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
 * MainWP Child System Monitor Cron Scanner.
 *
 * Collects raw system information without determining whether it is healthy.
 *
 * Returns raw data for validators.
 */
class MainWP_Child_System_Monitor_Cron_Scanner {

    /**
     * Scan WP-Cron.
     *
     * @return array
     */
    public function scan() {

        return array(
            'current_time'     => time(),
            'last_cron_run'    => MainWP_Child_System_Monitor_Runner::get_last_cron_run(),
            'next_run'         => wp_next_scheduled(
                MainWP_Child_System_Monitor::CRON_HOOK
            ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        );
    }
}
