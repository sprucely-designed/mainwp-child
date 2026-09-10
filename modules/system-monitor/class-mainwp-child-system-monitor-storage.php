<?php
/**
 * MainWP Child System Monitor Storage.
 *
 * @package     MainWP/Child
 */

namespace MainWP\Child\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP Child System Monitor Storage.
 */
class MainWP_Child_System_Monitor_Storage {

    /**
     * Option prefix.
     */
    const OPTION_PREFIX = 'mainwp_child_system_monitor_data_';

    /**
     * Get option name.
     *
     * @param string $monitor Monitor name.
     *
     * @return string
     */
    protected static function get_option_name( $monitor ) {
        return self::OPTION_PREFIX . sanitize_key( $monitor );
    }

    /**
     * Save monitor results.
     *
     * @param string $monitor Monitor name.
     * @param array  $results Monitor issues.
     *
     * @return int Number of saved issues.
     */
    public static function save_results( $monitor, array $results ) {

        $checked_at = time();
        $issues     = array();

        foreach ( $results as $result ) {

            if ( ! is_array( $result ) ) {
                continue;
            }

            if ( empty( $result['issue_code'] ) ) {
                continue;
            }

            $result['checked_at'] = $checked_at;

            if ( empty( $result['payload'] ) || ! is_array( $result['payload'] ) ) {
                $result['payload'] = array();
            }

            $issues[] = $result;
        }

        update_option(
            static::get_option_name( $monitor ),
            $issues,
            false
        );

        return count( $issues );
    }

    /**
     * Get monitor issues.
     *
     * @param string|null $monitor      Optional monitor name.
     * @param bool        $not_fallback Exclude fallback issues.
     *
     * @return array
     */
    public static function get_issues( $monitor, $not_fallback = true ) {

        $results = get_option(
            static::get_option_name( $monitor ),
            array()
        );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        if ( $not_fallback ) {
            $results = array_values(
                array_filter(
                    $results,
                    static function ( $issue ) {
                        return empty( $issue['issue_code'] ) ||
                            MainWP_Child_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK !== $issue['issue_code'];
                    }
                )
            );
        }

        usort(
            $results,
            static function ( $a, $b ) {

                if ( $a['severity'] === $b['severity'] ) {
                    return $b['checked_at'] <=> $a['checked_at'];
                }

                return strcmp(
                    (string) $b['severity'],
                    (string) $a['severity']
                );
            }
        );

        return $results;
    }

    /**
     * Delete monitor results.
     *
     * @param string      $monitor    Monitor name.
     * @param string|null $issue_code Optional issue code.
     *
     * @return void
     */
    public static function delete_results( $monitor, $issue_code = null ) {

        if ( null === $issue_code ) {

            delete_option(
                static::get_option_name( $monitor )
            );

            return;
        }

        $issues = get_option(
            static::get_option_name( $monitor ),
            array()
        );

        $issues = array_values(
            array_filter(
                $issues,
                static function ( $issue ) use ( $issue_code ) {
                    return empty( $issue['issue_code'] ) ||
                        $issue['issue_code'] !== $issue_code;
                }
            )
        );

        update_option(
            static::get_option_name( $monitor ),
            $issues,
            false
        );
    }
}
