<?php
/**
 * MainWP Favorites package protocol.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Private protocol helpers keep their contracts in the surrounding method descriptions.

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
     * Run or reconcile one digest-bound install operation.
     *
     * @param mixed $request Request payload.
     * @return array
     */
    public function install_verified_v2( $request ) {
        $operation = is_array( $request ) && isset( $request['operation'] ) && is_string( $request['operation'] ) ? $request['operation'] : 'unknown';
        if ( ! is_array( $request ) || ! $this->exact_keys( $request, array( 'protocol', 'operation', 'payload' ) ) || '2' !== $request['protocol'] || ! is_array( $request['payload'] ) ) {
            return $this->error( $operation, 'invalid_request' );
        }
        if ( 'capabilities' === $operation ) {
            if ( array() !== $request['payload'] ) {
                return $this->error( $operation, 'invalid_request' );
            }
            return array(
                'protocol'           => '2',
                'operation'          => 'capabilities',
                'ok'                 => true,
                'operations'         => array( 'install', 'status' ),
                'mutation_supported' => true,
            );
        }
        if ( 'status' === $operation ) {
            return $this->install_status( $request['payload'] );
        }
        if ( 'install' !== $operation ) {
            return $this->error( $operation, 'unsupported_operation' );
        }
        if ( ! $this->valid_install_payload( $request['payload'] ) ) {
            return $this->error( $operation, 'invalid_request' );
        }

        $payload  = $request['payload'];
        $existing = $this->load_install_receipt( $payload['request_ref'] );
        if ( false === $existing ) {
            return $this->error( $operation, 'storage_unavailable' );
        }
        // Only this mutation path evicts: the read paths must never write.
        if ( is_array( $existing ) && $this->install_receipt_expired( $existing ) ) {
            $this->delete_install_receipt( $payload['request_ref'] );
            // An eviction that did not take leaves the replay defense standing, and reading the
            // receipt back is what separates "the receipt is gone" from "the store could not drop
            // it". Assuming the delete worked is how a resent request installs the package twice.
            $existing = $this->load_install_receipt( $payload['request_ref'] );
            if ( false === $existing ) {
                return $this->error( $operation, 'storage_unavailable' );
            }
        }
        $effect_hash = $this->install_effect_hash( $payload );
        if ( is_array( $existing ) ) {
            return $this->replay_install_receipt( $existing, $effect_hash );
        }

        $before = $this->package_state_v2(
            array(
                'protocol'    => '2',
                'operation'   => 'package_state',
                'request_ref' => $payload['request_ref'],
                'type'        => $payload['type'],
                'slug'        => $payload['slug'],
            )
        );
        if ( true !== $before['ok'] || ! hash_equals( $before['state_generation'], $payload['state_generation'] ) ) {
            return $this->error( $operation, true !== $before['ok'] ? 'storage_unavailable' : 'state_changed' );
        }

        $dispatching = $this->dispatching_receipt( $payload, $effect_hash, $before );
        if ( ! $this->create_install_receipt( $payload['request_ref'], $dispatching ) ) {
            $existing = $this->load_install_receipt( $payload['request_ref'] );
            return is_array( $existing ) ? $this->replay_install_receipt( $existing, $effect_hash ) : $this->error( $operation, 'storage_unavailable' );
        }
        if ( $before['installed'] && ! $payload['overwrite'] ) {
            return $this->settle_install( $dispatching, $this->result_from_state( $before, 'failed', 'already_installed', $dispatching ) );
        }

        $path = $this->download_package( $payload['download_url'] );
        if ( is_wp_error( $path ) || ! is_string( $path ) || '' === $path ) {
            return $this->settle_install( $dispatching, $this->result_from_state( $before, 'failed', 'download_failed', $dispatching ) );
        }

        try {
            $digest = $this->package_digest( $path );
            if ( ! is_string( $digest ) || ! hash_equals( $payload['expected_sha256'], $digest ) ) {
                return $this->settle_install( $dispatching, $this->result_from_state( $before, 'failed', 'digest_mismatch', $dispatching ) );
            }
            if ( true !== $this->inspect_package( $path, $payload['type'], $payload['slug'], $payload['expected_version'] ) ) {
                return $this->settle_install( $dispatching, $this->result_from_state( $before, 'failed', 'package_mismatch', $dispatching ) );
            }
            $fresh = $this->package_state_v2(
                array(
                    'protocol'    => '2',
                    'operation'   => 'package_state',
                    'request_ref' => $payload['request_ref'],
                    'type'        => $payload['type'],
                    'slug'        => $payload['slug'],
                )
            );
            if ( true !== $fresh['ok'] || ! hash_equals( $before['state_generation'], $fresh['state_generation'] ) ) {
                return $this->settle_install( $dispatching, $this->result_from_state( $fresh, 'failed', 'state_changed', $dispatching ) );
            }

            $dispatched = $this->dispatch_install( $path, $payload );
            $this->refresh_package_cache( $payload['type'] );
            $after = $this->package_state_v2(
                array(
                    'protocol'    => '2',
                    'operation'   => 'package_state',
                    'request_ref' => $payload['request_ref'],
                    'type'        => $payload['type'],
                    'slug'        => $payload['slug'],
                )
            );
            if ( true === $after['ok'] && $after['installed'] && $payload['expected_version'] === $after['version'] && ( 'theme' === $payload['type'] || ! $payload['activate'] || true === $after['active'] ) ) {
                return $this->settle_install( $dispatching, $this->result_from_state( $after, 'completed', null, $dispatching ) );
            }
            $code = is_wp_error( $dispatched ) || false === $dispatched ? 'install_outcome_unknown' : 'readback_mismatch';
            return $this->settle_install( $dispatching, $this->result_from_state( $after, 'unknown', $code, $dispatching ) );
        } finally {
            $this->cleanup_package( $path );
        }
    }

    /** Return one stored install result without dispatching. */
    private function install_status( $payload ) {
        if ( ! is_array( $payload ) || ! $this->exact_keys( $payload, array( 'request_ref' ) ) || ! $this->request_ref( $payload['request_ref'] ) ) {
            return $this->error( 'status', 'invalid_request' );
        }
        $receipt = $this->load_install_receipt( $payload['request_ref'] );
        if ( false === $receipt ) {
            return $this->error( 'status', 'storage_unavailable' );
        }
        if ( ! is_array( $receipt ) || ! $this->valid_install_receipt( $receipt ) || $this->install_receipt_expired( $receipt ) ) {
            return $this->error( 'status', 'not_found' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Validate one exact install effect. */
    private function valid_install_payload( $payload ) {
        if ( ! is_array( $payload ) || ! $this->exact_keys( $payload, array( 'request_ref', 'type', 'slug', 'expected_version', 'expected_sha256', 'download_url', 'overwrite', 'activate', 'state_generation' ) ) || ! $this->request_ref( $payload['request_ref'] ) || ! in_array( $payload['type'], array( 'plugin', 'theme' ), true ) || ! $this->valid_slug( $payload['type'], $payload['slug'] ) || ! $this->version_value( $payload['expected_version'] ) || ! is_string( $payload['expected_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['expected_sha256'] ) || ! is_bool( $payload['overwrite'] ) || ! is_bool( $payload['activate'] ) || ( 'theme' === $payload['type'] && $payload['activate'] ) || ! is_string( $payload['state_generation'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['state_generation'] ) ) {
            return false;
        }
        return $this->valid_download_url( $payload['download_url'] );
    }

    /** Derive the immutable effect digest without persisting the private URL. */
    protected function install_effect_hash( $payload ) {
        $effect = array(
            'request_ref'      => $payload['request_ref'],
            'type'             => $payload['type'],
            'slug'             => $payload['slug'],
            'expected_version' => $payload['expected_version'],
            'expected_sha256'  => $payload['expected_sha256'],
            'overwrite'        => $payload['overwrite'],
            'activate'         => $payload['activate'],
            'state_generation' => $payload['state_generation'],
        );
        return hash( 'sha256', wp_json_encode( $effect, JSON_UNESCAPED_SLASHES ) );
    }

    /** Build the durable pre-effect receipt. */
    private function dispatching_receipt( $payload, $effect_hash, $before ) {
        return array(
            'effect_hash'      => $effect_hash,
            'state'            => 'dispatching',
            'request_ref'      => $payload['request_ref'],
            'type'             => $payload['type'],
            'slug'             => $payload['slug'],
            'expected_version' => $payload['expected_version'],
            'activate'         => $payload['activate'],
            'installed'        => $before['installed'],
            'previous_version' => $before['version'],
            'previous_active'  => $before['active'],
            'result'           => null,
            'updated_at'       => time(),
            'expires_at'       => time() + 7 * DAY_IN_SECONDS,
        );
    }

    /** Replay a matching receipt or reject changed intent. */
    private function replay_install_receipt( $receipt, $effect_hash ) {
        if ( ! $this->valid_install_receipt( $receipt ) ) {
            return $this->error( 'install', 'storage_unavailable' );
        }
        if ( ! hash_equals( $receipt['effect_hash'], $effect_hash ) ) {
            return $this->error( 'install', 'request_conflict' );
        }
        return 'dispatching' === $receipt['state'] ? $this->unknown_result( $receipt ) : $receipt['result'];
    }

    /** Settle one reserved effect and reconcile a lost write acknowledgement. */
    private function settle_install( $dispatching, $result ) {
        $settled               = $dispatching;
        $settled['state']      = 'settled';
        $settled['result']     = $result;
        $settled['updated_at'] = time();
        if ( $this->settle_install_receipt( $result['request_ref'], $dispatching, $settled ) ) {
            return $result;
        }
        $stored = $this->load_install_receipt( $result['request_ref'] );
        if ( is_array( $stored ) && $stored === $settled ) {
            return $result;
        }
        return $this->unknown_result( $dispatching );
    }

    /** Project current state into the closed install result. */
    private function result_from_state( $state, $status, $code, $receipt ) {
        $installed = is_array( $state ) && array_key_exists( 'installed', $state ) ? true === $state['installed'] : $receipt['installed'];
        return array(
            'protocol'    => '2',
            'operation'   => 'install',
            'ok'          => 'completed' === $status,
            'request_ref' => is_array( $state ) && isset( $state['request_ref'] ) ? $state['request_ref'] : $receipt['request_ref'],
            'status'      => $status,
            'installed'   => $installed,
            'type'        => is_array( $state ) && isset( $state['type'] ) ? $state['type'] : $receipt['type'],
            'slug'        => is_array( $state ) && isset( $state['slug'] ) ? $state['slug'] : $receipt['slug'],
            'version'     => $installed && isset( $state['version'] ) ? $state['version'] : ( $receipt['installed'] ? $receipt['previous_version'] : null ),
            'active'      => is_array( $state ) && array_key_exists( 'active', $state ) ? $state['active'] : $receipt['previous_active'],
            'code'        => $code,
        );
    }

    /** Return the only truthful result for an interrupted reserved effect. */
    private function unknown_result( $receipt ) {
        return array(
            'protocol'    => '2',
            'operation'   => 'install',
            'ok'          => false,
            'request_ref' => isset( $receipt['result']['request_ref'] ) ? $receipt['result']['request_ref'] : $this->request_ref_from_receipt( $receipt ),
            'status'      => 'unknown',
            'installed'   => $receipt['installed'],
            'type'        => $receipt['type'],
            'slug'        => $receipt['slug'],
            'version'     => $receipt['previous_version'],
            'active'      => $receipt['previous_active'],
            'code'        => 'outcome_unknown',
        );
    }

    /** Recover the request reference from the receipt option key binding. */
    private function request_ref_from_receipt( $receipt ) {
        return isset( $receipt['request_ref'] ) && $this->request_ref( $receipt['request_ref'] ) ? $receipt['request_ref'] : '00000000-0000-4000-8000-000000000000';
    }

    /** Validate one closed private receipt. */
    private function valid_install_receipt( $receipt ) {
        if ( ! is_array( $receipt ) || ! $this->exact_keys( $receipt, array( 'effect_hash', 'state', 'request_ref', 'type', 'slug', 'expected_version', 'activate', 'installed', 'previous_version', 'previous_active', 'result', 'updated_at', 'expires_at' ) ) || ! is_string( $receipt['effect_hash'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $receipt['effect_hash'] ) || ! in_array( $receipt['state'], array( 'dispatching', 'settled' ), true ) || ! $this->request_ref( $receipt['request_ref'] ) || ! in_array( $receipt['type'], array( 'plugin', 'theme' ), true ) || ! $this->valid_slug( $receipt['type'], $receipt['slug'] ) || ! $this->version_value( $receipt['expected_version'] ) || ! is_bool( $receipt['activate'] ) || ! is_bool( $receipt['installed'] ) || ( null !== $receipt['previous_version'] && ! $this->version_value( $receipt['previous_version'] ) ) || ( null !== $receipt['previous_active'] && ! is_bool( $receipt['previous_active'] ) ) || ( ! $receipt['installed'] && ( null !== $receipt['previous_version'] || null !== $receipt['previous_active'] ) ) || ! is_int( $receipt['updated_at'] ) || 1 > $receipt['updated_at'] || ! is_int( $receipt['expires_at'] ) || $receipt['expires_at'] < $receipt['updated_at'] ) {
            return false;
        }
        if ( 'dispatching' === $receipt['state'] ) {
            return null === $receipt['result'];
        }
        return $this->valid_install_result( $receipt['result'] ) && $receipt['request_ref'] === $receipt['result']['request_ref'] && $receipt['type'] === $receipt['result']['type'] && $receipt['slug'] === $receipt['result']['slug'];
    }

    /** Validate one closed public install result. */
    private function valid_install_result( $result ) {
        if ( ! is_array( $result ) || ! $this->exact_keys( $result, array( 'protocol', 'operation', 'ok', 'request_ref', 'status', 'installed', 'type', 'slug', 'version', 'active', 'code' ) ) || '2' !== $result['protocol'] || 'install' !== $result['operation'] || ! is_bool( $result['ok'] ) || ! $this->request_ref( $result['request_ref'] ) || ! in_array( $result['status'], array( 'completed', 'failed', 'unknown' ), true ) || ! is_bool( $result['installed'] ) || ! in_array( $result['type'], array( 'plugin', 'theme' ), true ) || ! $this->valid_slug( $result['type'], $result['slug'] ) || ( null !== $result['version'] && ! $this->version_value( $result['version'] ) ) || ( null !== $result['active'] && ! is_bool( $result['active'] ) ) || ( null !== $result['code'] && ( ! is_string( $result['code'] ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/D', $result['code'] ) ) ) ) {
            return false;
        }
        return ( 'completed' === $result['status'] ) === $result['ok'] && ( 'completed' === $result['status'] ? ( $result['installed'] && null !== $result['version'] && null === $result['code'] ) : null !== $result['code'] );
    }

    /** Load one bounded private receipt. */
    protected function load_install_receipt( $request_ref ) {
        $missing = '__mainwp_favorites_missing__';
        $receipt = get_option( $this->receipt_key( $request_ref ), $missing );
        if ( $missing === $receipt ) {
            return null;
        }
        return $this->valid_install_receipt( $receipt ) ? $receipt : false;
    }

    /** Report whether a settled receipt is past its retention window. */
    private function install_receipt_expired( $receipt ) {
        // A dispatch marker never expires into silence: its effect is still unresolved, so dropping
        // it is exactly what would let a resent request install the package a second time.
        return is_array( $receipt ) && isset( $receipt['state'], $receipt['expires_at'] ) && 'settled' === $receipt['state'] && is_int( $receipt['expires_at'] ) && $receipt['expires_at'] <= time();
    }

    /** Drop one receipt a mutation has proven past retention. */
    protected function delete_install_receipt( $request_ref ) {
        return delete_option( $this->receipt_key( $request_ref ) );
    }

    /** Reserve one effect before package acquisition or installation. */
    protected function create_install_receipt( $request_ref, $receipt ) {
        return $this->request_ref( $request_ref ) && is_array( $receipt ) && isset( $receipt['request_ref'] ) && $receipt['request_ref'] === $request_ref && $this->valid_install_receipt( $receipt ) && add_option( $this->receipt_key( $request_ref ), $receipt, '', false );
    }

    /** Replace one exact dispatch marker with its terminal result. */
    protected function settle_install_receipt( $request_ref, $expected, $receipt ) {
        $current = $this->load_install_receipt( $request_ref );
        if ( ! is_array( $current ) || $current !== $expected || ! $this->valid_install_receipt( $receipt ) ) {
            return false;
        }
        update_option( $this->receipt_key( $request_ref ), $receipt, false );
        return $receipt === $this->load_install_receipt( $request_ref );
    }

    /** Derive the non-secret option key for one UUID request reference. */
    private function receipt_key( $request_ref ) {
        return 'mainwp_child_favorites_receipt_' . hash( 'sha256', $request_ref );
    }

    /** Download a private one-use package through WordPress safe HTTP. */
    protected function download_package( $url ) {
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR - WordPress package download API.
        }
        return download_url( $url, 30 );
    }

    /** Validate a public HTTPS URL, with private hosts allowed only in local mode. */
    private function valid_download_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || 2048 < strlen( $url ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'], $parts['pass'], $parts['fragment'] ) ) {
            return false;
        }
        if ( function_exists( 'wp_http_validate_url' ) && false !== wp_http_validate_url( $url ) ) {
            return true;
        }
        return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() && 1 === preg_match( '/(?:^|\.)local$/iD', $parts['host'] );
    }

    /** Compute one lowercase package digest. */
    protected function package_digest( $path ) {
        return is_file( $path ) && is_readable( $path ) ? hash_file( 'sha256', $path ) : false;
    }

    /** Validate archive containment plus the exact package header before install. */
    protected function inspect_package( $path, $type, $slug, $version ) {
        if ( ! class_exists( '\\ZipArchive' ) ) {
            return false;
        }
        $zip = new \ZipArchive();
        if ( true !== $zip->open( $path ) || 1 > $zip->numFiles || 5000 < $zip->numFiles ) {
            return false;
        }
        $root      = 'plugin' === $type ? ( false === strpos( $slug, '/' ) ? '' : substr( $slug, 0, strpos( $slug, '/' ) + 1 ) ) : $slug . '/';
        $target    = 'plugin' === $type ? $slug : $slug . '/style.css';
        $header    = null;
        $total     = 0;
        $validated = true;
        for ( $index = 0; $index < $zip->numFiles; ++$index ) {
            $stat = $zip->statIndex( $index );
            if ( ! is_array( $stat ) || ! isset( $stat['name'], $stat['size'] ) || ! is_string( $stat['name'] ) || 512 < strlen( $stat['name'] ) || false !== strpos( $stat['name'], "\0" ) || false !== strpos( $stat['name'], '\\' ) || 0 === strpos( $stat['name'], '/' ) || ! $this->contained_entry( $stat['name'], $root ) ) {
                $validated = false;
                break;
            }
            $segments = explode( '/', trim( $stat['name'], '/' ) );
            if ( in_array( '..', $segments, true ) || in_array( '.', $segments, true ) || ! is_numeric( $stat['size'] ) || 0 > (int) $stat['size'] ) {
                $validated = false;
                break;
            }
            $operations = 0;
            $attributes = 0;
            if ( $zip->getExternalAttributesIndex( $index, $operations, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) {
                $validated = false;
                break;
            }
            $total += (int) $stat['size'];
            if ( 209715200 < $total ) {
                $validated = false;
                break;
            }
            if ( $target === $stat['name'] ) {
                $header = $zip->getFromIndex( $index, 262144 );
            }
        }
        $zip->close();
        if ( ! $validated || ! is_string( $header ) || '' === $header ) {
            return false;
        }
        $name_pattern    = 'plugin' === $type ? '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi' : '/^[ \t\/*#@]*Theme Name:\s*(.+)$/mi';
        $version_pattern = '/^[ \t\/*#@]*Version:\s*(.+)$/mi';
        return 1 === preg_match( $name_pattern, $header, $name_match ) && '' !== trim( $name_match[1] ) && 1 === preg_match( $version_pattern, $header, $version_match ) && trim( $version_match[1] ) === $version;
    }

    /**
     * Hold containment for one archive entry.
     *
     * A single-file plugin slug ("hello.php") has no directory to anchor on, so the
     * archive is only contained when every entry sits at the archive root.
     */
    private function contained_entry( $name, $root ) {
        return '' === $root ? false === strpos( $name, '/' ) : 0 === strpos( $name, $root );
    }

    /** Install the verified local package and optionally activate the exact plugin. */
    protected function dispatch_install( $path, $payload ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; // NOSONAR - WordPress package installer.
        require_once ABSPATH . 'wp-admin/includes/file.php'; // NOSONAR - WordPress filesystem API.
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // NOSONAR - Exact activation/readback API.
        $skin      = new \Automatic_Upgrader_Skin();
        $installer = new \WP_Upgrader( $skin );
        $result    = $installer->run(
            array(
                'package'           => $path,
                'destination'       => 'plugin' === $payload['type'] ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/themes',
                'clear_destination' => $payload['overwrite'],
                'clear_working'     => true,
                'hook_extra'        => array(),
            )
        );
        if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['destination_name'] ) ) {
            return is_wp_error( $result ) ? $result : false;
        }
        if ( 'plugin' === $payload['type'] && $payload['activate'] ) {
            $activated = activate_plugin( $payload['slug'], '', false, true );
            if ( is_wp_error( $activated ) ) {
                return $activated;
            }
        }
        return true;
    }

    /** Refresh the exact WordPress package inventory before terminal readback. */
    protected function refresh_package_cache( $type ) {
        if ( 'plugin' === $type && function_exists( 'wp_clean_plugins_cache' ) ) {
            wp_clean_plugins_cache( true );
        } elseif ( 'theme' === $type && function_exists( 'wp_clean_themes_cache' ) ) {
            wp_clean_themes_cache( true );
        }
    }

    /** Remove only the request-local downloaded package. */
    protected function cleanup_package( $path ) {
        if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
            wp_delete_file( $path );
        }
    }

    /** Validate an MCP-safe UUID reference. */
    private function request_ref( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
    }

    /** Validate an exact package slug by type. */
    private function valid_slug( $type, $slug ) {
        if ( ! is_string( $slug ) || '' === $slug || 191 < strlen( $slug ) || false !== strpos( $slug, '..' ) ) {
            return false;
        }
        return 'plugin' === $type ? 1 === preg_match( '#^(?:[A-Za-z0-9._-]+/)?[A-Za-z0-9._-]+\.php$#D', $slug ) : 1 === preg_match( '/^[A-Za-z0-9_-]+$/D', $slug );
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
