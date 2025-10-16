<?php

//

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add manager menu.
 */
function pscm_add_menu() {
    if ( current_user_can( 'manage_options' ) ) {
        add_menu_page( 'Pick Sheet Manager', 'Pick Sheet Manager', 'manage_options', 'pick-sheet-manager', 'pscm_render_page', 'dashicons-clipboard', 26 );
    }
}
add_action( 'admin_menu', 'pscm_add_menu' );

/**
 * Render manager dashboard with assignment controls.
 */
function pscm_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<p>Insufficient permissions.</p>';
        return;
    }
    // Handle form submission to save assignments
    if ( isset( $_POST['pscm_save_assignments'] ) && check_admin_referer( 'pscm_save_assignments_action', 'pscm_nonce_field' ) ) {
        if ( isset( $_POST['assignments'] ) && is_array( $_POST['assignments'] ) ) {
            foreach ( $_POST['assignments'] as $post_id => $assign ) {
                $post_id = intval( $post_id );
                if ( isset( $assign['picker'] ) ) {
                    update_post_meta( $post_id, 'assigned_picker', intval( $assign['picker'] ) );
                }
                if ( isset( $assign['driver'] ) ) {
                    update_post_meta( $post_id, 'assigned_driver', intval( $assign['driver'] ) );
                }
            }
        }
        echo '<div class="updated notice"><p>Assignments saved.</p></div>';
    }
    // Query pick sheets
    $args = array(
        'post_type'      => 'pick_sheets',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    $query = new WP_Query( $args );
    $pickers = get_users( array( 'role' => 'picker' ) );
    $drivers = get_users( array( 'role' => 'driver' ) );
    echo '<div class="wrap"><h1>Pick Sheet Manager</h1>';
    echo '<form method="post">';
    wp_nonce_field( 'pscm_save_assignments_action', 'pscm_nonce_field' );
    echo '<table class="widefat fixed striped"><thead><tr><th>Sheet UID</th><th>Technician</th><th>Date</th><th>Picker</th><th>Driver</th><th>Status</th><th>Missing</th><th>Logs</th><th>View</th></tr></thead><tbody>';
    while ( $query->have_posts() ) {
        $query->the_post();
        $post_id = get_the_ID();
        $sheet_uid = get_post_meta( $post_id, 'sheet_uid', true );
        $tech = get_post_meta( $post_id, 'technician_name', true );
        $date = get_the_date();
        $assigned_picker = intval( get_post_meta( $post_id, 'assigned_picker', true ) );
        $assigned_driver = intval( get_post_meta( $post_id, 'assigned_driver', true ) );
        $picker_options = '<option value="0">--Unassigned--</option>';
        foreach ( $pickers as $user ) {
            $picker_options .= '<option value="' . esc_attr( $user->ID ) . '"' . selected( $assigned_picker, $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
        }
        $driver_options = '<option value="0">--Unassigned--</option>';
        foreach ( $drivers as $user ) {
            $driver_options .= '<option value="' . esc_attr( $user->ID ) . '"' . selected( $assigned_driver, $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
        }
        $picker_status = get_post_meta( $post_id, 'completed_time', true ) ? 'Picked' : 'Open';
        $driver_status = get_post_meta( $post_id, 'driver_verified_time', true ) ? 'Verified' : 'Pending';
        $status = $picker_status . '/' . $driver_status;
        $missing = maybe_unserialize( get_post_meta( $post_id, 'driver_missing', true ) );
        $missing_count = is_array( $missing ) ? count( $missing ) : 0;
        // Logs
        $picker_log = get_post_meta( $post_id, 'completion_file', true );
        $driver_log = get_post_meta( $post_id, 'driver_completion_file', true );
        $logs_html = '';
        if ( $picker_log ) {
            $logs_html .= '<a href="' . esc_url( $picker_log ) . '" target="_blank">Picker log</a>'; }
        if ( $driver_log ) {
            $logs_html .= ( $logs_html ? ' / ' : '' ) . '<a href="' . esc_url( $driver_log ) . '" target="_blank">Driver log</a>'; }
        if ( ! $logs_html ) {
            $logs_html = '-';
        }
        $view_url = get_permalink( $post_id );
        echo '<tr>';
        echo '<td>' . esc_html( $sheet_uid ) . '</td>';
        echo '<td>' . esc_html( $tech ) . '</td>';
        echo '<td>' . esc_html( $date ) . '</td>';
        echo '<td><select name="assignments[' . esc_attr( $post_id ) . '][picker]">' . $picker_options . '</select></td>';
        echo '<td><select name="assignments[' . esc_attr( $post_id ) . '][driver]">' . $driver_options . '</select></td>';
        echo '<td>' . esc_html( $status ) . '</td>';
        echo '<td>' . esc_html( $missing_count ) . '</td>';
        echo '<td>' . $logs_html . '</td>';
        echo '<td><a href="' . esc_url( $view_url ) . '" class="button" target="_blank">View</a></td>';
        echo '</tr>';
    }
    wp_reset_postdata();
    echo '</tbody></table>';
    echo '<p><input type="submit" name="pscm_save_assignments" class="button button-primary" value="Save Assignments"></p>';
    echo '</form></div>';
}

/**
 * Add driver verification mode field to user profile.
 */
function pscm_driver_preferences_fields( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $role = $user->roles[0] ?? '';
    if ( $role !== 'driver' ) {
        return;
    }
    $mode = get_user_meta( $user->ID, 'driver_verification_mode', true );
    if ( ! $mode ) {
        $mode = 'simple';
    }
    echo '<h3>Driver Verification Mode</h3><table class="form-table"><tr><th><label for="driver_verification_mode">Verification Mode</label></th><td>';
    echo '<select name="driver_verification_mode" id="driver_verification_mode">';
    echo '<option value="simple"' . selected( $mode, 'simple', false ) . '>Simple (Bin/Truck)</option>';
    echo '<option value="strict"' . selected( $mode, 'strict', false ) . '>Strict (Part/Bin/Truck)</option>';
    echo '</select><p class="description">Choose how the driver must scan items.</p></td></tr></table>';
}
add_action( 'show_user_profile', 'pscm_driver_preferences_fields' );
add_action( 'edit_user_profile', 'pscm_driver_preferences_fields' );

/**
 * Save driver verification mode on profile update.
 */
function pscm_save_driver_preferences( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_POST['driver_verification_mode'] ) ) {
        update_user_meta( $user_id, 'driver_verification_mode', sanitize_text_field( $_POST['driver_verification_mode'] ) );
    }
}
add_action( 'personal_options_update', 'pscm_save_driver_preferences' );
add_action( 'edit_user_profile_update', 'pscm_save_driver_preferences' );