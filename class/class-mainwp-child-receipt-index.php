<?php
/**
 * Bounded index of the per-request receipt rows one protocol has written.
 *
 * @package MainWP\Child
 */

namespace MainWP\Child;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Remember which receipt keys a protocol wrote, so retention is reclaimed by exact key lookup.
 *
 * A request reference is one-shot, so nothing ever comes back to the row it created and the owning
 * protocol only ever reclaims the reference the current request happens to name. The rows nobody
 * returns for are findable two ways: by scanning wp_options for a key prefix, which is unindexed
 * and would sit on the mutation path, or by remembering the keys. This remembers them.
 *
 * The index is advisory in both directions. It never decides that a row may go - the owner's own
 * expiry predicate does, read against the row as it stands now, because the expiry stored here is
 * only a cheap gate for which keys are worth reading at all. And it never refuses: a full index
 * drops its oldest entry, so the row that entry named goes back to leaking exactly as it did
 * before there was an index, rather than a bookkeeping limit travelling back into a mutation that
 * has already reserved its effect.
 */
class MainWP_Child_Receipt_Index {

    /**
     * Option-key prefix every indexed row has to carry.
     *
     * @var string
     */
    private $prefix;

    /**
     * Bind one index to the key namespace of a single protocol.
     *
     * @param string $prefix Prefix the owning protocol builds its receipt option keys from.
     */
    public function __construct( $prefix ) {
        $this->prefix = is_string( $prefix ) ? $prefix : '';
    }

    /**
     * Record one receipt key, dropping the oldest entry when the index is full.
     *
     * @param string $index_option Option holding this protocol's index.
     * @param string $option_key   Receipt option key that was just written.
     * @param mixed  $expires_at   Retention moment stored on that receipt.
     * @param int    $cap          Entries the index holds at once.
     * @return bool Whether the entry was stored.
     */
    public function add( $index_option, $option_key, $expires_at, $cap ) {
        if ( ! $this->own_key( $option_key ) || ! is_int( $expires_at ) || 0 >= $expires_at || ! is_int( $cap ) || 1 > $cap ) {
            return false;
        }
        $entries   = $this->entries( $index_option );
        $entries[] = array(
            'key'        => $option_key,
            'expires_at' => $expires_at,
        );
        $overflow  = count( $entries ) - $cap;
        if ( 0 < $overflow ) {
            $entries = array_slice( $entries, $overflow );
        }
        return $this->store( $index_option, $entries );
    }

    /**
     * Reclaim up to $limit indexed rows the owner judges past retention.
     *
     * @param string   $index_option Option holding this protocol's index.
     * @param int      $limit        Rows one call may read.
     * @param callable $reclaimable  Owner predicate over the stored row; true means the row may go.
     * @return int Rows actually deleted.
     */
    public function sweep( $index_option, $limit, callable $reclaimable ) {
        if ( ! is_int( $limit ) || 1 > $limit ) {
            return 0;
        }
        $entries   = $this->entries( $index_option );
        $kept      = array();
        $read      = 0;
        $reclaimed = 0;
        $now       = time();
        $missing   = '__mainwp_child_receipt_index_missing__';
        foreach ( $entries as $entry ) {
            if ( $read >= $limit || $entry['expires_at'] > $now ) {
                $kept[] = $entry;
                continue;
            }
            ++$read;
            $value = get_option( $entry['key'], $missing );
            if ( $missing === $value ) {
                continue;
            }
            // The owner judges the row as it stands, never the entry: the key may have been written
            // again since it was indexed, and a row nothing can parse is still evidence a resent
            // request needs, which is why a predicate that says no keeps the entry as well as the
            // row. A delete that did not take leaves both standing for the same reason.
            if ( true !== $reclaimable( $value ) || ! delete_option( $entry['key'] ) ) {
                $kept[] = $entry;
                continue;
            }
            ++$reclaimed;
        }
        if ( $kept !== $entries ) {
            $this->store( $index_option, $kept );
        }
        return $reclaimed;
    }

    /**
     * Read the stored index, discarding anything that is not a usable entry.
     *
     * The index is stored input exactly as the receipts are, so an entry that does not name a key
     * in this protocol's own namespace is dropped rather than acted on. Dropping one touches no
     * option row: the row it might have named simply stops being tracked, which is the leak this
     * class exists to reduce and never a deletion. An index that will not read at all is treated
     * as empty and written over, because refusing on behalf of an unreadable one would stall every
     * mutation behind it.
     *
     * @param string $index_option Option holding this protocol's index.
     * @return array Validated entries in insertion order.
     */
    private function entries( $index_option ) {
        $stored = get_option( $index_option, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }
        $entries = array();
        foreach ( $stored as $entry ) {
            if ( is_array( $entry ) && isset( $entry['key'], $entry['expires_at'] ) && $this->own_key( $entry['key'] ) && is_int( $entry['expires_at'] ) && 0 < $entry['expires_at'] ) {
                $entries[] = array(
                    'key'        => $entry['key'],
                    'expires_at' => $entry['expires_at'],
                );
            }
        }
        return $entries;
    }

    /**
     * Whether one key belongs to the namespace this index was bound to.
     *
     * An empty prefix would make every row in the options table look like this protocol's own, so
     * it matches nothing at all.
     *
     * @param mixed $option_key Candidate option key.
     * @return bool
     */
    private function own_key( $option_key ) {
        return '' !== $this->prefix && is_string( $option_key ) && 191 >= strlen( $option_key ) && 0 === strpos( $option_key, $this->prefix );
    }

    /**
     * Persist the index without autoloading it.
     *
     * @param string $index_option Option holding this protocol's index.
     * @param array  $entries      Entries to store.
     * @return bool
     */
    private function store( $index_option, $entries ) {
        return update_option( $index_option, $entries, false );
    }
}
