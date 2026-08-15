<?php
/**
 * MainWP Favorites package protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Exact package-state reads and fail-closed install negotiation.
 */
class MainWP_Child_Favorites {

    /**
     * Handle the package-state callable.
     */
    public function handle_package_state() {
        MainWP_Helper::write( $this->package_state_v2( $this->request_from_post() ) );
    }

    /**
     * Handle the verified-install callable.
     */
    public function handle_install() {
        MainWP_Helper::write( $this->install_verified_v2( $this->request_from_post() ) );
    }

    /**
     * Read one exact plugin or theme package state.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function package_state_v2( $request ) {
        if ( ! $this->valid_state_request( $request ) ) {
            return $this->error( 'package_state', 'invalid_request' );
        }

        $runtime = $this->package_runtime();
        if ( ! $this->valid_runtime( $runtime ) ) {
            return $this->error( 'package_state', 'storage_unavailable' );
        }

        $installed = false;
        $version   = null;
        $active    = null;
        if ( 'plugin' === $request['type'] && isset( $runtime['plugins'][ $request['slug'] ] ) ) {
            $record = $runtime['plugins'][ $request['slug'] ];
            if ( ! is_array( $record ) || ! isset( $record['Version'] ) || ! $this->version_value( $record['Version'] ) ) {
                return $this->error( 'package_state', 'provider_schema_invalid' );
            }
            $installed = true;
            $version   = $record['Version'];
            $active    = in_array( $request['slug'], $runtime['active_plugins'], true );
        } elseif ( 'theme' === $request['type'] && isset( $runtime['themes'][ $request['slug'] ] ) ) {
            $record = $runtime['themes'][ $request['slug'] ];
            if ( ! is_array( $record ) || ! isset( $record['Version'] ) || ! $this->version_value( $record['Version'] ) ) {
                return $this->error( 'package_state', 'provider_schema_invalid' );
            }
            $installed = true;
            $version   = $record['Version'];
        }

        $state = array(
            'type'      => $request['type'],
            'slug'      => $request['slug'],
            'installed' => $installed,
            'version'   => $version,
            'active'    => $active,
        );

        return array(
            'protocol'         => '2',
            'operation'        => 'package_state',
            'ok'               => true,
            'request_ref'      => $request['request_ref'],
            'type'             => $request['type'],
            'slug'             => $request['slug'],
            'installed'        => $installed,
            'version'          => $version,
            'active'           => $active,
            'state_generation' => hash( 'sha256', wp_json_encode( $state ) ),
        );
    }

    /**
     * Negotiate the future digest-bound install operation.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function install_verified_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->error( $operation, 'invalid_request' );
        }
        if ( 'capabilities' !== $operation ) {
            return $this->error( $operation, 'unsupported_operation' );
        }
        if ( array() !== $request['payload'] ) {
            return $this->error( $operation, 'invalid_request' );
        }

        return array(
            'protocol'           => '2',
            'operation'          => 'capabilities',
            'ok'                 => true,
            'operations'         => array(),
            'mutation_supported' => false,
        );
    }

    /**
     * Read installed package state.
     *
     * @return array
     */
    protected function package_runtime() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - WordPress package inventory API.
        }
        $plugins = get_plugins();
        $themes  = wp_get_themes();
        $result  = array();
        foreach ( $themes as $slug => $theme ) {
            if ( is_object( $theme ) && method_exists( $theme, 'get' ) ) {
                $result[ $slug ] = array( 'Version' => $theme->get( 'Version' ) );
            }
        }
        $network_active = get_site_option( 'active_sitewide_plugins', array() );
        $active         = array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), is_array( $network_active ) ? array_keys( $network_active ) : array() ) ) );

        return array(
            'plugins'        => $plugins,
            'active_plugins' => $active,
            'themes'         => $result,
        );
    }

    /**
     * Validate the state request.
     *
     * @param mixed $request Request.
     * @return bool
     */
    private function valid_state_request( $request ) {
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'protocol', 'operation', 'request_ref', 'type', 'slug' ) ) || '2' !== $request['protocol'] || 'package_state' !== $request['operation'] || ! is_string( $request['request_ref'] ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $request['request_ref'] ) || ! in_array( $request['type'], array( 'plugin', 'theme' ), true ) || ! is_string( $request['slug'] ) || 191 < strlen( $request['slug'] ) ) {
            return false;
        }
        if ( 'plugin' === $request['type'] ) {
            return 1 === preg_match( '#^(?:[A-Za-z0-9._-]+/)?[A-Za-z0-9._-]+\.php$#D', $request['slug'] ) && false === strpos( $request['slug'], '..' );
        }
        return 1 === preg_match( '/^[A-Za-z0-9_-]+$/D', $request['slug'] );
    }

    /**
     * Validate runtime arrays.
     *
     * @param mixed $runtime Runtime.
     * @return bool
     */
    private function valid_runtime( $runtime ) {
        if ( ! is_array( $runtime ) || ! $this->exact_keys( $runtime, array( 'plugins', 'active_plugins', 'themes' ) ) || ! is_array( $runtime['plugins'] ) || ! is_array( $runtime['active_plugins'] ) || ! is_array( $runtime['themes'] ) ) {
            return false;
        }
        foreach ( $runtime['active_plugins'] as $plugin ) {
            if ( ! is_string( $plugin ) || 191 < strlen( $plugin ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate a bounded version string.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private function version_value( $value ) {
        return is_string( $value ) && '' !== $value && 128 >= strlen( $value ) && wp_check_invalid_utf8( $value, true ) === $value && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
    }

    /**
     * Read a bounded JSON request from the authenticated callable.
     *
     * @return array|null
     */
    private function request_from_post() {
        // phpcs:disable WordPress.Security.NonceVerification -- MainWP signature authentication occurs before callable dispatch.
        if ( ! isset( $_POST['request'] ) || ! is_string( $_POST['request'] ) ) {
            return null;
        }
        $raw = wp_unslash( $_POST['request'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict JSON validation follows.
        // phpcs:enable WordPress.Security.NonceVerification
        if ( 16384 < strlen( $raw ) ) {
            return null;
        }
        $request = json_decode( $raw, true );
        return is_array( $request ) ? $request : null;
    }

    /**
     * Build a stable error.
     *
     * @param string $operation Operation.
     * @param string $code      Error code.
     * @return array
     */
    private function error( $operation, $code ) {
        return array(
            'protocol'  => '2',
            'operation' => is_string( $operation ) && 64 >= strlen( $operation ) ? $operation : 'unknown',
            'ok'        => false,
            'code'      => $code,
        );
    }

    /**
     * Validate an exact key set without requiring object order.
     *
     * @param mixed $value Value.
     * @param array $keys  Keys.
     * @return bool
     */
    private function exact_keys( $value, $keys ) {
        if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
            return false;
        }
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $value ) ) {
                return false;
            }
        }
        return true;
    }
}
