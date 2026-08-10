<?php
/**
 * MainWP Child DB
 *
 * This file handles all of the Child Plugin's DB functions.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Child_DB
 *
 * Handles all of the Child Plugin's DB functions.
 */
class MainWP_Child_DB {

    // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- unprepared SQL ok, accessing the database directly to custom database functions.

    /**
     * Support old & new versions of WordPress (3.9+).
     *
     * @return bool|object Instantiated object of \mysqli.
     */
    public static function use_mysqli() {
        if ( ! function_exists( '\mysqli_connect' ) ) {
            return false;
        }

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        return $wpdb->dbh instanceof \mysqli;
    }

    /**
     * Run a mysqli query & get a result.
     *
     * @param string $query An SQL query.
     * @param string $link A link identifier.
     *
     * @return bool|\mysqli_result|resource For successful SELECT, SHOW, DESCRIBE or EXPLAIN queries, mysqli_query()
     *  will return a mysqli_result object. For other successful queries mysqli_query() will return TRUE.
     *  Returns FALSE on failure.
     */
    public static function to_query( $query, $link ) {
        if ( static::use_mysqli() ) {
            return \mysqli_query( $link, $query );
        } else {
            return \mysql_query( $query, $link );
        }
    }

    /**
     * Fetch an array.
     *
     * @param array $result A result set identifier.
     *
     * @return array|false|null Returns an array of strings that corresponds to the fetched row, or false if there are no more rows.
     */
    public static function fetch_array( $result ) {
        if ( static::use_mysqli() ) {
            return \mysqli_fetch_array( $result, MYSQLI_ASSOC );
        } else {
            return \mysql_fetch_array( $result, MYSQL_ASSOC );
        }
    }

    /**
     * Count the number of rows.
     *
     * @param array $result A result set identifier returned.
     *
     * @return false|int Returns number of rows in the result set.
     */
    public static function num_rows( $result ) {
        if ( static::use_mysqli() ) {
            return \mysqli_num_rows( $result );
        } else {
            return \mysql_num_rows( $result );
        }
    }

    /**
     * Connect to Child Site Database.
     *
     * @param string $host Can be either a host name or an IP address.
     * @param string $user The MySQL user name.
     * @param string $pass The MySQL user password.
     *
     * @return false|\mysqli|resource object which represents the connection to a MySQL Server or false if an error occurred.
     */
    public static function connect( $host, $user, $pass ) {
        if ( static::use_mysqli() ) {
            return \mysqli_connect( $host, $user, $pass );
        } else {
            return \mysql_connect( $host, $user, $pass );
        }
    }

    /**
     * Select Child Site DB.
     *
     * @param string $db Database name.
     *
     * @return bool true on success or false on failure.
     */
    public static function select_db( $db ) {
        if ( static::use_mysqli() ) {

            /**
             * WordPress Database instance.
             *
             * @global object $wpdb
             */
            global $wpdb;

            return \mysqli_select_db( $wpdb->dbh, $db );
        } else {
            return \mysql_select_db( $db );
        }
    }


    /**
     * Fix the autoload flag for the specified option. If the option is currently autoloaded, update it so it is no longer autoloaded.
     *
     * @param  mixed $option_name Option name to fix autoload.
     * @return void
     */
    public static function fix_autoload( $option_name ) {
        if ( self::is_autoload_option( $option_name ) ) {
            $value = get_option( $option_name );
            delete_option( $option_name );
            MainWP_Helper::update_option( $option_name, $value );
        }
    }


    /**
     * Check if the specified option is set to autoload or not.
     *
     * @param  mixed $option_name Option name to check if it is autoload or not.
     * @return` bool True if the option is autoloaded, false otherwise.
     */
    public static function is_autoload_option( $option_name ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Need to query the database directly to check the autoload value of the option.
        $autoload_value = $wpdb->get_var( // NOASONAR - WP compatible.
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                $option_name
            )
        );

        // Use the core-provided list of truthy autoload values (introduced in 6.4+).
        if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
            return in_array( $autoload_value, wp_autoload_values_to_autoload(), true );
        }

        // Fallback for older WP versions.
        return in_array( $autoload_value, array( 'yes', 'on', 'auto-on', 'auto' ), true );
    }

    /**
     * Get any mysqli errors.
     *
     * @return string the error text from the last MySQL function, or '' (empty string) if no error occurred.
     */
    public static function error() {
        if ( static::use_mysqli() ) {

            /**
             * WordPress Database instance.
             *
             * @global object $wpdb
             */
            global $wpdb;

            return \mysqli_error( $wpdb->dbh );
        } else {
            return \mysql_error();
        }
    }

    /**
     * Escape a given string.
     *
     * @param string $value The string to be escaped. Characters encoded are NUL (ASCII 0), \n, \r, \, ', ", and Control-Z.
     *
     * @return false|string the escaped string, or false on error.
     */
    public static function real_escape_string( $value ) {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        if ( static::use_mysqli() ) {
            return \mysqli_real_escape_string( $wpdb->dbh, $value );
        } else {
            return \mysql_real_escape_string( $value, $wpdb->dbh );
        }
    }

    /**
     * Check if $result is an Instantiated object of \mysqli.
     *
     * @param resource $result Instantiated object of \mysqli.
     *
     * @return resource|bool Instantiated object of \mysqli, true if var is a resource, false otherwise.
     */
    public static function is_result( $result ) {
        if ( static::use_mysqli() ) {
            return $result instanceof \mysqli_result;
        } else {
            return is_resource( $result );
        }
    }

    /**
     * Get the size of the DB.
     *
     * @return int|mixed Size of the DB or false on failure.
     */
    public static function get_size() {

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        $rows = static::to_query( 'SHOW table STATUS', $wpdb->dbh );
        $size = 0;
        while ( $row = static::fetch_array( $rows ) ) {
            $size += $row['Data_length'];
        }

        return $size;
    }

    /**
     * Maybe clean up request ID options.
     *
     * Performs the cleanup at most once every hour to avoid scanning
     * the options table on every authentication request.
     *
     * @return void
     */
    private static function maybe_cleanup_advanced_request_ids() {
        $last_cleanup = (int) get_option( 'mainwp_child_advanced_request_ids_last_cleanup', 0 );
        if ( time() - $last_cleanup > HOUR_IN_SECONDS ) {
            static::cleanup_advanced_request_ids();
            update_option( 'mainwp_child_advanced_request_ids_last_cleanup', time(), false );
        }
    }

    /**
     * Method cleanup_advanced_request_ids()
     *
     * Daily checks to clear the dashboard request ids.
     */
    public static function cleanup_advanced_request_ids() {

        global $wpdb;

        $threshold = time() - ( 10 * MINUTE_IN_SECONDS );

        $options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OK.
            $wpdb->prepare(
                "SELECT option_name
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND CAST(option_value AS UNSIGNED) < %d",
                $wpdb->esc_like( 'mainwp_child_advanced_request_id_' ) . '%',
                $threshold
            )
        );

        foreach ( $options as $option_name ) {
            delete_option( $option_name );
        }
    }

    /**
     * Maybe clean up legacy request ID options.
     *
     * Performs the cleanup at most once every 24 hours to avoid scanning
     * the options table on every authentication request.
     *
     * @return void
     */
    public static function maybe_cleanup_request_ids() {

        static::maybe_cleanup_advanced_request_ids();

        $option_name  = 'mainwp_child_request_ids_cleanup';
        $last_cleanup = (int) get_option( $option_name, 0 );

        if ( $last_cleanup > time() - DAY_IN_SECONDS ) {
            return;
        }

        update_option( $option_name, time(), false );

        static::cleanup_request_ids();
    }

    /**
     * Clean up legacy request ID options.
     *
     * Removes expired 12-month request IDs and randomly removes excess
     * request IDs when the retention pools exceed their limits:
     *
     * - Level 1: Maximum 200 records.
     * - Level 2: Maximum 100 records.
     * - Level 3: Removed when the 12-month retention period expires.
     *
     * @return void
     */
    private static function cleanup_request_ids() { // phpcs:ignore -- NOSONAR -complex

        global $wpdb;

        $prefix = 'mainwp_child_request_id_';
        $now    = time();

        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        if ( empty( $options ) ) {
            return;
        }

        $level_1 = array();
        $level_2 = array();

        foreach ( $options as $option ) {

            $value = maybe_unserialize( $option->option_value );

            if ( ! is_array( $value ) ) {
                continue;
            }

            $level   = isset( $value['level'] ) ? (int) $value['level'] : 0;
            $expires = isset( $value['expires'] ) ? (int) $value['expires'] : 0;

            /*
             * Level 3:
             * Delete after 12 months.
             */
            if ( 3 === $level ) {
                if ( $expires > 0 && $expires <= $now ) {
                    delete_option( $option->option_name );
                }

                continue;
            }

            if ( 1 === $level ) {
                $level_1[] = $option->option_name;
            } elseif ( 2 === $level ) {
                $level_2[] = $option->option_name;
            }
        }

        /*
        * Level 1: maximum 200.
        */
        if ( count( $level_1 ) > 200 ) {

            shuffle( $level_1 );

            foreach ( array_slice( $level_1, 200 ) as $option_name ) {
                delete_option( $option_name );
            }
        }

        /*
        * Level 2: maximum 100.
        */
        if ( count( $level_2 ) > 100 ) {

            shuffle( $level_2 );

            foreach ( array_slice( $level_2, 100 ) as $option_name ) {
                delete_option( $option_name );
            }
        }
    }
}
