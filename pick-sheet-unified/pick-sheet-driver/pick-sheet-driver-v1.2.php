<?php

//

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register custom post type for technicians. This allows managers to store home addresses for drivers to navigate to.
 */
function psd_register_technician_cpt() {
    $labels = array(
        'name'          => 'Technicians',
        'singular_name' => 'Technician',
        'add_new'       => 'Add New',
        'add_new_item'  => 'Add New Technician',
        'edit_item'     => 'Edit Technician',
        'new_item'      => 'New Technician',
        'view_item'     => 'View Technician',
        'search_items'  => 'Search Technicians',
        'not_found'     => 'No technicians found',
        'menu_name'     => 'Technicians',
    );
    $args = array(
        'labels'      => $labels,
        'public'      => false,
        'show_ui'     => true,
        'show_in_menu'=> true,
        'supports'    => array( 'title', 'custom-fields' ),
        'capability_type' => 'post',
    );
    register_post_type( 'technicians', $args );
}
add_action( 'init', 'psd_register_technician_cpt' );

/**
 * Add driver dashboard to admin menu for driver role.
 */
function psd_add_driver_menu() {
    // Only for users with driver role; fallback to administrators.
    if ( current_user_can( 'driver' ) || current_user_can( 'manage_options' ) ) {
        add_menu_page(
            'Driver Dashboard',
            'Driver Dashboard',
            'read',
            'driver-dashboard',
            'psd_render_dashboard',
            'dashicons-car',
            25
        );
    }
}
add_action( 'admin_menu', 'psd_add_driver_menu' );

/**
 * Render the driver dashboard page. Lists assigned pick sheets.
 */
function psd_render_dashboard() {
    if ( ! current_user_can( 'read' ) ) {
        echo '<p>Insufficient permissions.</p>';
        return;
    }
    $user_id = get_current_user_id();
    $args = array(
        'post_type'   => 'pick_sheets',
        'numberposts' => -1,
        'meta_key'    => 'assigned_driver',
        'meta_value'  => $user_id,
    );
    $posts = get_posts( $args );
    echo '<div class="wrap"><h1>Driver Dashboard</h1>';
    if ( empty( $posts ) ) {
        echo '<p>No pick sheets assigned to you.</p></div>';
        return;
    }
    echo '<table class="widefat"><thead><tr><th>Sheet</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    foreach ( $posts as $ps ) {
        $completed = get_post_meta( $ps->ID, 'driver_verified_time', true );
        $status    = $completed ? 'Verified' : 'Pending';
        $url       = add_query_arg( array( 'driver' => 1 ), get_permalink( $ps ) );
        echo '<tr><td>' . esc_html( $ps->post_title ) . '</td><td>' . esc_html( $status ) . '</td><td><a href="' . esc_url( $url ) . '" class="button">Open</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * Shortcode to display driver pick sheet table on the front end. Should be used on pick sheet posts with ?driver=1 query.
 */
function psd_driver_shortcode( $atts ) {
    if ( ! is_singular( 'pick_sheets' ) || ! isset( $_GET['driver'] ) ) {
        return '';
    }
    // Ensure user is driver or admin
    if ( ! current_user_can( 'driver' ) && ! current_user_can( 'manage_options' ) ) {
        return '<p>You do not have access to this driver view.</p>';
    }
    global $post;
    $post_id = $post->ID;
    // Check assignment
    $assigned_driver = get_post_meta( $post_id, 'assigned_driver', true );
    if ( $assigned_driver && intval( $assigned_driver ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
        return '<p>This pick sheet is not assigned to you.</p>';
    }
    // Load data
    $items   = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header  = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $picked_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );
    if ( ! $items || ! $header ) {
        return '<p>No data.</p>';
    }
    if ( ! is_array( $picked_details ) ) {
        $picked_details = array();
    }
    $driver_loaded = maybe_unserialize( get_post_meta( $post_id, 'driver_loaded', true ) );
    $driver_missing = maybe_unserialize( get_post_meta( $post_id, 'driver_missing', true ) );
    if ( ! is_array( $driver_loaded ) ) {
        $driver_loaded = array();
    }
    if ( ! is_array( $driver_missing ) ) {
        $driver_missing = array();
    }
    $driver_mode = get_user_meta( get_current_user_id(), 'driver_verification_mode', true );
    if ( ! $driver_mode ) {
        $driver_mode = 'simple';
    }
    $completed_time = get_post_meta( $post_id, 'driver_verified_time', true );
    $last_saved     = get_post_meta( $post_id, 'last_saved_at_driver', true );
    $delivered_parts = maybe_unserialize( get_post_meta( $post_id, 'driver_delivered', true ) );
    if ( ! is_array( $delivered_parts ) ) {
        $delivered_parts = array();
    }
    if ( empty( $delivered_parts ) ) {
        foreach ( $items as $row ) {
            $row_data = array();
            foreach ( $header as $index => $col ) {
                $row_data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
            }
            $part_number = $row_data['PartNumber'] ?? '';
            if ( $part_number && get_post_meta( $post_id, 'driver_delivered_' . $part_number, true ) ) {
                $delivered_parts[] = $part_number;
            }
        }
        $delivered_parts = array_values( array_unique( $delivered_parts ) );
    }
    $delivered_parts = array_values( array_unique( array_map( 'sanitize_text_field', $delivered_parts ) ) );
    ob_start();
    // Completion notice
    if ( $completed_time ) {
        echo '<div class="psd-complete-message">Driver verified on ' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', intval( $completed_time ) ) ) . '</div>';
    }
    if ( $last_saved ) {
        echo '<div class="psd-last-saved">Last saved: ' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', intval( $last_saved ) ) ) . '</div>';
    }
    // Table
    echo '<input id="psd-search" type="text" placeholder="Scan code..." style="margin-bottom:10px;width:200px;" />';
    echo '<table class="psd-table widefat fixed striped"><thead><tr>';
    foreach ( $header as $col ) {
        echo '<th>' . esc_html( $col ) . '</th>';
    }
    echo '<th>Loaded</th><th>Delivered</th><th>Missing</th></tr></thead><tbody>';
    foreach ( $items as $idx => $row ) {
        $data = array();
        foreach ( $header as $index => $col ) {
            $data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
        }
        $part = $data['PartNumber'] ?? '';
        $detail      = isset( $picked_details[ $idx ] ) ? $picked_details[ $idx ] : array();
        $picked_bin  = isset( $detail['bin'] ) ? sanitize_text_field( $detail['bin'] ) : '';
        $picked_shelf = isset( $detail['shelf'] ) ? sanitize_text_field( $detail['shelf'] ) : '';
        $picked_time = isset( $detail['time'] ) ? sanitize_text_field( $detail['time'] ) : '';
        $loaded    = in_array( $part, $driver_loaded, true );
        $missing   = in_array( $part, $driver_missing, true );
        $delivered = in_array( $part, $delivered_parts, true );
        echo '<tr data-part="' . esc_attr( $part ) . '" data-bin="' . esc_attr( $data['BinLoc'] ?? '' ) . '" data-shelf="' . esc_attr( $picked_shelf ) . '">';
        foreach ( $header as $col ) {
            $val = isset( $data[ $col ] ) ? $data[ $col ] : '';
            if ( 'Picked Bin' === $col && '' !== $picked_bin ) {
                $val = $picked_bin;
            } elseif ( 'Picked Shelf' === $col && '' !== $picked_shelf ) {
                $val = $picked_shelf;
            } elseif ( 'Pick Time' === $col && '' !== $picked_time ) {
                $val = $picked_time;
            }
            echo '<td>' . esc_html( $val ) . '</td>';
        }
        // Loaded checkbox
        if ( $completed_time ) {
            $loaded_html = $loaded ? '<span style="color:green;">Loaded</span>' : '<span style="color:red;">Not Loaded</span>';
            $delivered_html = $delivered ? '<span style="color:green;">Delivered</span>' : '<span style="color:red;">Not Delivered</span>';
            $missing_html = $missing ? '<span style="color:orange;">Missing</span>' : '';
            echo '<td>' . $loaded_html . '</td><td>' . $delivered_html . '</td><td>' . $missing_html . '</td>';
        } else {
            $checked_load = $loaded ? ' checked' : '';
            $checked_del  = $delivered ? ' checked' : '';
            $checked_miss = $missing ? ' checked' : '';
            echo '<td><input type="checkbox" class="psd-load" value="' . esc_attr( $part ) . '"' . $checked_load . '></td>';
            echo '<td><input type="checkbox" class="psd-deliver" value="' . esc_attr( $part ) . '"' . $checked_del . '></td>';
            echo '<td><input type="checkbox" class="psd-missing" value="' . esc_attr( $part ) . '"' . $checked_miss . '></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    // Buttons
    if ( ! $completed_time ) {
        echo '<button id="psd-save-progress" class="button button-secondary" style="margin-top:10px;">Save Progress</button> ';
        echo '<button id="psd-complete-sheet" class="button button-primary" style="margin-top:10px;">Complete Driver Sheet</button>';
    }
    // Navigate button
    // Show navigation to technician home address if available
    $tech_name = get_post_meta( $post_id, 'technician_name', true );
    if ( $tech_name ) {
        // Try to find technician post by title
        $tech_post = get_page_by_title( $tech_name, OBJECT, 'technicians' );
        $address   = '';
        if ( $tech_post ) {
            $address = get_post_meta( $tech_post->ID, 'home_address', true );
        }
        if ( $address ) {
            $maps_link = 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $address );
            echo '<div style="margin-top:10px;"><a href="' . esc_url( $maps_link ) . '" target="_blank" class="button">Navigate to Tech</a></div>';
        }
    }
    echo '<div id="psd-success" style="margin-top:10px;"></div>';
    $output = ob_get_clean();
    // Enqueue scripts
    psd_enqueue_scripts();
    // Localize data
    $data = array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'post_id'        => $post_id,
        'driver_loaded'  => $driver_loaded,
        'driver_missing' => $driver_missing,
        'driver_mode'      => $driver_mode,
        'completed'        => (bool) $completed_time,
        'nonce'            => wp_create_nonce( 'psd_nonce' ),
        'driver_delivered' => $delivered_parts,
    );
    wp_localize_script( 'psd-script', 'psdData', $data );
    return $output;
}
add_shortcode( 'driver_pick_sheet_table', 'psd_driver_shortcode' );

/**
 * Enqueue scripts and styles for driver table.
 */
function psd_enqueue_scripts() {
    if ( ! is_singular( 'pick_sheets' ) || ! isset( $_GET['driver'] ) ) {
        return;
    }
    wp_enqueue_style( 'psd-style', plugins_url( 'psd-style.css', __FILE__ ), array(), '1.2' );
    wp_enqueue_script( 'psd-script', plugins_url( 'psd-script.js', __FILE__ ), array( 'jquery' ), '1.2', true );
}

/**
 * AJAX save for driver progress.
 */
function psd_ajax_save_progress() {
    check_ajax_referer( 'psd_nonce', 'nonce' );
    $post_id  = intval( $_POST['post_id'] );
    $loaded   = isset( $_POST['driver_loaded'] ) ? (array) $_POST['driver_loaded'] : array();
    $missing  = isset( $_POST['driver_missing'] ) ? (array) $_POST['driver_missing'] : array();
    $delivered = isset( $_POST['driver_delivered'] ) ? (array) $_POST['driver_delivered'] : array();

    $loaded    = array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $loaded ) ) ) );
    $missing   = array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $missing ) ) ) );
    $delivered = array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $delivered ) ) ) );

    update_post_meta( $post_id, 'driver_loaded', maybe_serialize( $loaded ) );
    update_post_meta( $post_id, 'driver_missing', maybe_serialize( $missing ) );

    $previous_delivered = maybe_unserialize( get_post_meta( $post_id, 'driver_delivered', true ) );
    if ( ! is_array( $previous_delivered ) ) {
        $previous_delivered = array();
    }
    update_post_meta( $post_id, 'driver_delivered', maybe_serialize( $delivered ) );
    $to_remove = array_diff( $previous_delivered, $delivered );
    foreach ( $to_remove as $part ) {
        delete_post_meta( $post_id, 'driver_delivered_' . $part );
    }
    foreach ( $delivered as $part ) {
        update_post_meta( $post_id, 'driver_delivered_' . $part, 'yes' );
    }

    update_post_meta( $post_id, 'last_saved_at_driver', time() );
    wp_send_json_success( array( 'message' => 'Saved' ) );
}
add_action( 'wp_ajax_psd_save_progress', 'psd_ajax_save_progress' );

/**
 * AJAX complete driver sheet.
 */
function psd_ajax_complete_sheet() {
    check_ajax_referer( 'psd_nonce', 'nonce' );
    $post_id  = intval( $_POST['post_id'] );
    $loaded   = maybe_unserialize( get_post_meta( $post_id, 'driver_loaded', true ) );
    $items    = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header   = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $missing  = maybe_unserialize( get_post_meta( $post_id, 'driver_missing', true ) );
    $delivered = maybe_unserialize( get_post_meta( $post_id, 'driver_delivered', true ) );
    $picked_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );

    $loaded    = is_array( $loaded ) ? $loaded : array();
    $missing   = is_array( $missing ) ? $missing : array();
    $delivered = is_array( $delivered ) ? $delivered : array();
    $picked_details = is_array( $picked_details ) ? $picked_details : array();

    $loaded    = array_values( array_unique( array_map( 'sanitize_text_field', $loaded ) ) );
    $missing   = array_values( array_unique( array_map( 'sanitize_text_field', $missing ) ) );
    $delivered = array_values( array_unique( array_map( 'sanitize_text_field', $delivered ) ) );

    if ( empty( $delivered ) && is_array( $items ) ) {
        foreach ( $items as $row ) {
            $row_data = array();
            foreach ( $header as $index => $col ) {
                $row_data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
            }
            $part_number = $row_data['PartNumber'] ?? '';
            if ( $part_number && get_post_meta( $post_id, 'driver_delivered_' . $part_number, true ) ) {
                $delivered[] = $part_number;
            }
        }
        $delivered = array_values( array_unique( $delivered ) );
    }

    if ( ! is_array( $items ) ) {
        wp_send_json_error( 'No items' );
    }
    // Create CSV log
    $upload_dir = wp_upload_dir();
    $dir        = trailingslashit( $upload_dir['basedir'] ) . 'pick_sheet_logs/';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $filename   = 'driver_sheet_' . $post_id . '_' . time() . '.csv';
    $filepath   = $dir . $filename;
    $fh         = fopen( $filepath, 'w' );
    $extended_header = $header;
    $extended_header[] = 'Picked Bin';
    $extended_header[] = 'Picked Shelf';
    $extended_header[] = 'Pick Time';
    $extended_header[] = 'Loaded';
    $extended_header[] = 'Delivered';
    $extended_header[] = 'Missing';
    fputcsv( $fh, $extended_header );
    foreach ( $items as $idx => $row ) {
        $data = array();
        foreach ( $header as $index => $col ) {
            $data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
        }
        $part = $data['PartNumber'] ?? '';
        $detail = array();
        if ( is_array( $picked_details ) && isset( $picked_details[ $idx ] ) && is_array( $picked_details[ $idx ] ) ) {
            $detail = $picked_details[ $idx ];
        }
        $picked_bin   = isset( $detail['bin'] ) ? sanitize_text_field( $detail['bin'] ) : '';
        $picked_shelf = isset( $detail['shelf'] ) ? sanitize_text_field( $detail['shelf'] ) : '';
        $picked_time  = isset( $detail['time'] ) ? sanitize_text_field( $detail['time'] ) : '';
        $is_loaded     = in_array( $part, $loaded, true ) ? 'yes' : 'no';
        $is_delivered  = in_array( $part, $delivered, true ) ? 'yes' : 'no';
        $is_missing    = in_array( $part, $missing, true ) ? 'yes' : 'no';
        $extended_row  = $row;
        $extended_row[] = $picked_bin;
        $extended_row[] = $picked_shelf;
        $extended_row[] = $picked_time;
        $extended_row[] = $is_loaded;
        $extended_row[] = $is_delivered;
        $extended_row[] = $is_missing;
        fputcsv( $fh, $extended_row );
    }
    fclose( $fh );
    $file_url = trailingslashit( $upload_dir['baseurl'] ) . 'pick_sheet_logs/' . $filename;
    update_post_meta( $post_id, 'driver_completion_file', $file_url );
    update_post_meta( $post_id, 'driver_verified_time', time() );
    wp_send_json_success( array( 'file_url' => $file_url ) );
}
add_action( 'wp_ajax_psd_complete_sheet', 'psd_ajax_complete_sheet' );

/**
 * AJAX update handler for delivered state.
 */
function psd_ajax_mark_delivered() {
    check_ajax_referer( 'psd_nonce', 'nonce' );
    $post_id = intval( $_POST['post_id'] );
    $part    = isset( $_POST['part'] ) ? sanitize_text_field( wp_unslash( $_POST['part'] ) ) : '';
    $status  = isset( $_POST['delivered'] ) ? sanitize_text_field( wp_unslash( $_POST['delivered'] ) ) : 'yes';
    if ( ! $post_id || ! $part ) {
        wp_send_json_error( 'Invalid' );
    }
    $status            = strtolower( $status ) === 'no' ? 'no' : 'yes';
    $delivered_parts   = maybe_unserialize( get_post_meta( $post_id, 'driver_delivered', true ) );
    $delivered_parts   = is_array( $delivered_parts ) ? $delivered_parts : array();
    if ( 'yes' === $status ) {
        update_post_meta( $post_id, 'driver_delivered_' . $part, 'yes' );
        if ( ! in_array( $part, $delivered_parts, true ) ) {
            $delivered_parts[] = $part;
        }
    } else {
        delete_post_meta( $post_id, 'driver_delivered_' . $part );
        $delivered_parts = array_diff( $delivered_parts, array( $part ) );
    }
    $delivered_parts = array_values( array_unique( array_map( 'sanitize_text_field', $delivered_parts ) ) );
    update_post_meta( $post_id, 'driver_delivered', maybe_serialize( $delivered_parts ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_psd_mark_delivered', 'psd_ajax_mark_delivered' );