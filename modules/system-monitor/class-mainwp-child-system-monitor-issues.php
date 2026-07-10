<?php
/**
 * MainWP Child System Monitor Issues.
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
 * MainWP Child System Monitor Issues.
 *
 * Builds standardized issue objects from validator failures.
 *
 * Responsibilities:
 *
 * - Normalize issue format.
 * - Assign severity.
 * - Assign category.
 * - Generate title.
 * - Generate description.
 * - Generate recommendation.
 *
 * Issues MUST NOT:
 *
 * - Scan the system.
 * - Validate data.
 * - Save issues.
 */
class MainWP_Child_System_Monitor_Issues {

    /**
     * Severity consts.
     */
    const SEVERITY_INFO    = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_ERROR   = 'error';

    private const HELP_BASE_URL = 'https://docs.mainwp.com/';


    /**
     * Method get issue message.
     *
     * @param string $code Issue code.
     * @param array  $payload Issue payload.
     *
     * @return string message.
     */
    public static function get_message( $code, array $payload = array() ) {

        switch ( $code ) {
            case MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_STALE:
                $delay = isset( $payload['delay'] ) ? (int) $payload['delay'] : 0;
                $human = human_time_diff(
                    time() - $delay,
                    time()
                );
                if ( ! empty( $payload['wp_cron_disabled'] ) ) {
                    return sprintf(
                        __( 'Scheduled tasks has not run for %s. WP-Cron is disabled, so verify that your external cron job is running correctly.', 'mainwp' ),
                        $human
                    );
                }
                return sprintf(
                    __( 'Scheduled tasks has not run for %s. Verify that WP-Cron is functioning correctly.', 'mainwp' ),
                    $human
                );
            case MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK:
                return __(
                    'The scheduled System Monitor task was overdue, so it was executed during a page request. If this happens frequently, verify that WP-Cron or an external cron job is working correctly.',
                    'mainwp'
                );
            default:
                // Nothing.
                break;
        }

        return '';
    }


    /**
     * Get title.
     *
     * @param string $code Issue code.
     *
     * @return string
     */
    public static function get_title( $code ) {
        $definition = self::get_definition( $code );
        return empty( $definition['title'] ) ? '' : $definition['title'];
    }

    /**
     * Get help URL.
     *
     * @param string $code Issue code.
     *
     * @return string
     */
    public static function get_help_url( $code ) {

        $definition = self::get_definition( $code );

        if ( empty( $definition['help'] ) ) {
            return '';
        }

        return self::HELP_BASE_URL . $definition['help'] . '/';
    }


    /**
     * Get severity.
     *
     * @param string $code Issue code.
     *
     * @return string severity.
     */
    public static function get_severity( $code ) {
        $definition = self::get_definition( $code );
        return empty( $definition['severity'] ) ? '' : $definition['severity'];
    }

    /**
     * Method get issue definitions.
     *
     * @param string $code Issue code.
     *
     * @return array definition.
     */
    public static function get_definition( $code ) {
        $definitions = array(
            MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_STALE          => array(
                'code'        => MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_STALE,
                'severity'    => self::SEVERITY_WARNING, // default severity.
                'title'       => __( 'Scheduled tasks have not run recently.', 'mainwp' ),
                'description' => __( 'The System Monitor cron has not executed within the expected interval.', 'mainwp' ),
                'help'        => 'customization/delayed-wp-cron',
            ),
            MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK => array(
                'code'        => MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK,
                'severity'    => self::SEVERITY_WARNING,
                'title'       => __( 'System Monitor fallback execution was used.', 'mainwp' ),
                'description' => __( 'The scheduled System Monitor task was executed during a page request because it did not run on schedule. Verify that WP-Cron or your external cron job is working correctly.', 'mainwp' ),
                'help'        => 'customization/delayed-wp-cron',
            ),
        );

        return $definitions[ $code ] ?? array();
    }
}
