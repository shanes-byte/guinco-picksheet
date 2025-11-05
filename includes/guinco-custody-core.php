<?php
/**
 * Guinco Custody Core Module
 * 
 * Shared custody logging functions for warehouse shelf → driver truck → technician truck stock chain.
 * This module must be included in both picker and driver plugins.
 * 
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GUINCO_CUSTODY_CORE_VERSION', '1.0.0' );

/**
 * Resolve tech_id from technician name or post ID.
 * 
 * @param int|string $tech_identifier Technician name or post ID.
 * @return int Tech ID (0 if not found).
 */
function guinco_resolve_tech_id( $tech_identifier ): int {
    if ( is_numeric( $tech_identifier ) ) {
        $tech_id = absint( $tech_identifier );
        if ( $tech_id > 0 && get_post_type( $tech_id ) === 'technicians' ) {
            return $tech_id;
        }
    }
    
    // Try to find by name
    if ( is_string( $tech_identifier ) && ! empty( $tech_identifier ) ) {
        $tech_post = get_page_by_title( $tech_identifier, OBJECT, 'technicians' );
        if ( $tech_post ) {
            return absint( $tech_post->ID );
        }
    }
    
    return 0;
}

/**
 * Generate a UUID v4.
 * 
 * @return string UUID.
 */
function guinco_uuid() {
    $data = random_bytes( 16 );
    $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Version 4
    $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Variant bits
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
}

/**
 * Require fields in data array.
 * 
 * @param array $data Data array to validate.
 * @param array $required Required field names.
 * @return void Throws WP_Error if missing.
 */
function guinco_require_fields( array $data, array $required ): void {
    $missing = array();
    foreach ( $required as $field ) {
        if ( ! isset( $data[ $field ] ) || $data[ $field ] === '' || $data[ $field ] === null ) {
            $missing[] = $field;
        }
    }
    if ( ! empty( $missing ) ) {
        $error = new WP_Error( 'missing_fields', 'Missing required fields: ' . implode( ', ', $missing ) );
        error_log( '[GUINCO] Validation failed: missing fields: ' . implode( ', ', $missing ) );
        throw $error;
    }
}

/**
 * Install custody events table schema.
 * 
 * @return void
 */
function guinco_install_schema(): void {
    global $wpdb;
    $table_name = $wpdb->prefix . 'guinco_custody_events';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_uuid` CHAR(36) NOT NULL,
        `pick_sheet_id` BIGINT UNSIGNED NOT NULL,
        `tech_id` BIGINT UNSIGNED NOT NULL,
        `part_number` VARCHAR(64) NOT NULL,
        `qty` INT NOT NULL,
        `action` ENUM('PICKED','LOADED','DROPPED_OFF','STOCKED') NOT NULL,
        `from_loc` ENUM('WAREHOUSE_SHELF','DRIVER_TRUCK','TECH_TRUCK','N/A') NOT NULL,
        `to_loc` ENUM('WAREHOUSE_SHELF','DRIVER_TRUCK','TECH_TRUCK','N/A') NOT NULL,
        `actor_role` ENUM('PICKER','DRIVER','TECH','SYSTEM') NOT NULL,
        `actor_user_id` BIGINT UNSIGNED NOT NULL,
        `device_id` VARCHAR(64) NOT NULL,
        `ts_utc` DATETIME NOT NULL,
        `source` VARCHAR(32) NOT NULL,
        `extra_json` JSON NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_event_uuid` (`event_uuid`),
        KEY `idx_sheet_part` (`pick_sheet_id`, `part_number`),
        KEY `idx_tech` (`tech_id`),
        KEY `idx_ts` (`ts_utc`)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Log a custody event to database.
 * 
 * @param array $e Event data with required fields.
 * @return string Event UUID on success.
 * @throws WP_Error On validation failure.
 */
function guinco_log_custody_event( array $e ): string {
    global $wpdb;
    
    // Ensure table exists
    $table_name = $wpdb->prefix . 'guinco_custody_events';
    $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
    if ( ! $table_exists ) {
        guinco_install_schema();
    }
    
    // Required fields
    $required = array(
        'pick_sheet_id',
        'tech_id',
        'part_number',
        'qty',
        'action',
        'from_loc',
        'to_loc',
        'actor_role',
        'actor_user_id',
        'device_id',
        'source',
    );
    
    guinco_require_fields( $e, $required );
    
    // Generate UUID
    $event_uuid = guinco_uuid();
    
    // Normalize and validate
    $pick_sheet_id = absint( $e['pick_sheet_id'] );
    $tech_id = absint( $e['tech_id'] );
    $part_number = sanitize_text_field( $e['part_number'] );
    $qty = absint( $e['qty'] );
    if ( $qty <= 0 ) {
        throw new WP_Error( 'invalid_qty', 'Quantity must be greater than 0' );
    }
    
    $action = in_array( $e['action'], array( 'PICKED', 'LOADED', 'DROPPED_OFF', 'STOCKED' ), true ) 
        ? $e['action'] 
        : 'PICKED';
    
    $from_loc = in_array( $e['from_loc'], array( 'WAREHOUSE_SHELF', 'DRIVER_TRUCK', 'TECH_TRUCK', 'N/A' ), true )
        ? $e['from_loc']
        : 'N/A';
    
    $to_loc = in_array( $e['to_loc'], array( 'WAREHOUSE_SHELF', 'DRIVER_TRUCK', 'TECH_TRUCK', 'N/A' ), true )
        ? $e['to_loc']
        : 'N/A';
    
    $actor_role = in_array( $e['actor_role'], array( 'PICKER', 'DRIVER', 'TECH', 'SYSTEM' ), true )
        ? $e['actor_role']
        : 'SYSTEM';
    
    $actor_user_id = absint( $e['actor_user_id'] );
    $device_id = sanitize_text_field( substr( $e['device_id'], 0, 64 ) );
    $source = sanitize_text_field( substr( $e['source'], 0, 32 ) );
    
    $extra_json = null;
    if ( isset( $e['extra_json'] ) && ! empty( $e['extra_json'] ) ) {
        $extra_json = wp_json_encode( $e['extra_json'] );
    }
    
    $ts_utc = isset( $e['ts_utc'] ) ? sanitize_text_field( $e['ts_utc'] ) : current_time( 'mysql', true );
    
    // Log input
    error_log( sprintf(
        '[GUINCO] %s_scan_in: pick_sheet_id=%d, part=%s, qty=%d, action=%s, actor=%s',
        strtolower( $source ),
        $pick_sheet_id,
        $part_number,
        $qty,
        $action,
        $actor_role
    ) );
    
    // Insert
    $result = $wpdb->insert(
        $table_name,
        array(
            'event_uuid'    => $event_uuid,
            'pick_sheet_id' => $pick_sheet_id,
            'tech_id'       => $tech_id,
            'part_number'   => $part_number,
            'qty'           => $qty,
            'action'        => $action,
            'from_loc'      => $from_loc,
            'to_loc'        => $to_loc,
            'actor_role'    => $actor_role,
            'actor_user_id' => $actor_user_id,
            'device_id'     => $device_id,
            'ts_utc'        => $ts_utc,
            'source'        => $source,
            'extra_json'    => $extra_json,
        ),
        array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
    );
    
    if ( $result === false ) {
        error_log( '[GUINCO] DB insert failed: ' . $wpdb->last_error );
        throw new WP_Error( 'db_error', 'Failed to insert custody event: ' . $wpdb->last_error );
    }
    
    // Append to CSV
    guinco_append_csv_event( $event_uuid );
    
    // Log success
    error_log( sprintf(
        '[GUINCO] %s_scan_logged: event_uuid=%s, pick_sheet_id=%d, part=%s',
        strtolower( $source ),
        $event_uuid,
        $pick_sheet_id,
        $part_number
    ) );
    
    return $event_uuid;
}

/**
 * Append event to CSV file.
 * 
 * @param string $event_uuid Event UUID to append.
 * @return void
 */
function guinco_append_csv_event( string $event_uuid ): void {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'guinco_custody_events';
    
    // Fetch event
    $event = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM `{$table_name}` WHERE `event_uuid` = %s",
        $event_uuid
    ), ARRAY_A );
    
    if ( ! $event ) {
        error_log( '[GUINCO] CSV append failed: event not found: ' . $event_uuid );
        return;
    }
    
    // Ensure directory exists
    $upload_dir = wp_upload_dir();
    $dir = trailingslashit( $upload_dir['basedir'] ) . 'pick_sheet_logs/';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    
    // Filename: custody_events_YYYY_MM.csv
    $filename = 'custody_events_' . date( 'Y_m', strtotime( $event['ts_utc'] ) ) . '.csv';
    $filepath = $dir . $filename;
    
    // Check if file exists to determine if we need header
    $file_exists = file_exists( $filepath );
    $fh = fopen( $filepath, 'a' );
    
    if ( ! $fh ) {
        error_log( '[GUINCO] CSV append failed: cannot open file: ' . $filepath );
        return;
    }
    
    // Write header if new file
    if ( ! $file_exists ) {
        $header = array(
            'event_uuid',
            'pick_sheet_id',
            'tech_id',
            'part_number',
            'qty',
            'action',
            'from_loc',
            'to_loc',
            'actor_role',
            'actor_user_id',
            'device_id',
            'ts_utc',
            'source',
            'extra_json',
        );
        fputcsv( $fh, $header );
    }
    
    // Normalize values for CSV
    $row = array(
        $event['event_uuid'],
        $event['pick_sheet_id'],
        $event['tech_id'],
        $event['part_number'],
        $event['qty'],
        $event['action'],
        $event['from_loc'],
        $event['to_loc'],
        $event['actor_role'],
        $event['actor_user_id'],
        $event['device_id'],
        $event['ts_utc'],
        $event['source'],
        $event['extra_json'] ? $event['extra_json'] : 'NULL',
    );
    
    fputcsv( $fh, $row );
    fclose( $fh );
}

/**
 * Check if tech stop can be completed (all required events exist).
 * 
 * @param int   $pick_sheet_id Pick sheet ID.
 * @param int   $tech_id Technician ID.
 * @param array $parts Array of arrays with 'part_number' and 'qty' keys.
 * @return bool True if chain is complete.
 */
function guinco_can_complete_tech_stop( int $pick_sheet_id, int $tech_id, array $parts ): bool {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'guinco_custody_events';
    
    // For each part, verify:
    // 1. Sum of LOADED events (from WAREHOUSE_SHELF to DRIVER_TRUCK) >= required qty
    // 2. Sum of DROPPED_OFF events (from DRIVER_TRUCK to TECH_TRUCK) >= required qty
    
    foreach ( $parts as $part ) {
        $part_number = sanitize_text_field( $part['part_number'] );
        $required_qty = absint( $part['qty'] );
        
        if ( $required_qty <= 0 ) {
            continue;
        }
        
        // Check LOADED events
        $loaded_qty = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(`qty`), 0) FROM `{$table_name}`
            WHERE `pick_sheet_id` = %d
            AND `tech_id` = %d
            AND `part_number` = %s
            AND `action` = 'LOADED'
            AND `from_loc` = 'WAREHOUSE_SHELF'
            AND `to_loc` = 'DRIVER_TRUCK'",
            $pick_sheet_id,
            $tech_id,
            $part_number
        ) );
        
        // Check DROPPED_OFF events
        $dropped_qty = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(`qty`), 0) FROM `{$table_name}`
            WHERE `pick_sheet_id` = %d
            AND `tech_id` = %d
            AND `part_number` = %s
            AND `action` = 'DROPPED_OFF'
            AND `from_loc` = 'DRIVER_TRUCK'
            AND `to_loc` = 'TECH_TRUCK'",
            $pick_sheet_id,
            $tech_id,
            $part_number
        ) );
        
        if ( $loaded_qty < $required_qty || $dropped_qty < $required_qty ) {
            error_log( sprintf(
                '[GUINCO] Chain incomplete: pick_sheet_id=%d, part=%s, required=%d, loaded=%d, dropped=%d',
                $pick_sheet_id,
                $part_number,
                $required_qty,
                $loaded_qty,
                $dropped_qty
            ) );
            return false;
        }
    }
    
    return true;
}

/**
 * Complete tech stop with validation.
 * 
 * @param int   $pick_sheet_id Pick sheet ID.
 * @param int   $tech_id Technician ID.
 * @param array $parts Array of arrays with 'part_number' and 'qty' keys.
 * @param int   $actor_user_id User ID completing the action.
 * @param string $device_id Device identifier.
 * @param string $source Source identifier ('picker' or 'driver').
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function guinco_complete_tech_stop( int $pick_sheet_id, int $tech_id, array $parts, int $actor_user_id, string $device_id, string $source ) {
    global $wpdb;
    
    $wpdb->query( 'START TRANSACTION' );
    
    try {
        // Validate chain
        if ( ! guinco_can_complete_tech_stop( $pick_sheet_id, $tech_id, $parts ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'incomplete_chain', 'Missing custody events; cannot complete.' );
        }
        
        // Optionally log a closing SYSTEM event
        $parts_list = array();
        foreach ( $parts as $part ) {
            $parts_list[] = array(
                'part_number' => sanitize_text_field( $part['part_number'] ),
                'qty' => absint( $part['qty'] ),
            );
        }
        
        try {
            guinco_log_custody_event( array(
                'pick_sheet_id' => $pick_sheet_id,
                'tech_id' => $tech_id,
                'part_number' => 'N/A',
                'qty' => 1,
                'action' => 'STOCKED',
                'from_loc' => 'TECH_TRUCK',
                'to_loc' => 'N/A',
                'actor_role' => 'SYSTEM',
                'actor_user_id' => $actor_user_id,
                'device_id' => $device_id,
                'source' => $source,
                'extra_json' => array(
                    'completed_at' => current_time( 'mysql', true ),
                    'parts' => $parts_list,
                ),
            ) );
        } catch ( Exception $e ) {
            // Log but don't fail completion if system event fails
            error_log( '[GUINCO] System event log failed: ' . $e->getMessage() );
        }
        
        $wpdb->query( 'COMMIT' );
        return true;
        
    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        error_log( '[GUINCO] Complete tech stop failed: ' . $e->getMessage() );
        return new WP_Error( 'completion_error', $e->getMessage() );
    }
}

