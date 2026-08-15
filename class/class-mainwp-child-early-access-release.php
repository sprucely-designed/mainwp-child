<?php
/**
 * MainWP Early Access release protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Narrow capability boundary for a future verified release installer.
 */
class MainWP_Child_Early_Access_Release {

    /** Maximum artifact size required by the approved contract. */
    const MAX_ARTIFACT_BYTES = 52428800;

    /**
     * Handle the authenticated Child callable.
     */
    public function action() {
        MainWP_Helper::write( $this->release_v2( $this->request_from_post() ) );
    }

    /**
     * Execute protocol negotiation.
     *
     * No installer operation is advertised until signed artifact acquisition,
     * backup, activation, exact-version readback, and rollback are implemented.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function release_v2( $request ) {
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
            'max_artifact_bytes' => self::MAX_ARTIFACT_BYTES,
        );
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
        if ( 4096 < strlen( $raw ) ) {
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
            'operation' => 64 >= strlen( $operation ) ? $operation : 'unknown',
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
