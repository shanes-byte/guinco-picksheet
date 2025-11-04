<?php
/**
 * Plugin Name: Pick Sheet Unified
 * Description: Combines Pick Sheet Manager, Importer, Driver, and fix plugins into a single plugin.
 * Version: 1.0.33
 * Author: ChatGPT Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/ps-chain-of-custody.php';

/**
 * One-time function to add shortcodes to existing pick sheets that don't have them.
 * Run this once after upgrading to v1.0.29+
 */
function guinco_picksheet_add_missing_shortcodes() {
    $args = array(
        'post_type'      => 'pick_sheets',
        'posts_per_page' => -1,
        'post_status'    => 'any',
    );
    $pick_sheets = get_posts( $args );
    $updated = 0;
    
    foreach ( $pick_sheets as $sheet ) {
        $content = $sheet->post_content;
        
        // Check if shortcodes already exist
        $has_picker = strpos( $content, '[pick_sheet_table]' ) !== false;
        $has_driver = strpos( $content, '[driver_pick_sheet_table]' ) !== false;
        
        if ( $has_picker && $has_driver ) {
            continue; // Already has both
        }
        
        // Add missing shortcodes
        $new_content = '';
        if ( ! $has_picker ) {
            $new_content .= "[pick_sheet_table]\n\n";
        }
        if ( ! $has_driver ) {
            $new_content .= "[driver_pick_sheet_table]\n\n";
        }
        
        // Prepend new shortcodes to existing content
        $updated_content = $new_content . $content;
        
        // Update post
        wp_update_post( array(
            'ID'           => $sheet->ID,
            'post_content' => $updated_content,
        ) );
        
        $updated++;
    }
    
    return $updated;
}

// Add admin notice with button to run the update
function guinco_picksheet_admin_notice() {
    // Only show to admins
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    // Check if we should run the update
    if ( isset( $_GET['guinco_add_shortcodes'] ) && $_GET['guinco_add_shortcodes'] === '1' && check_admin_referer( 'guinco_add_shortcodes' ) ) {
        $updated = guinco_picksheet_add_missing_shortcodes();
        echo '<div class="notice notice-success is-dismissible"><p><strong>Pick Sheet Unified:</strong> Updated ' . $updated . ' pick sheets with missing shortcodes.</p></div>';
        return;
    }
    
    // Check if any pick sheets are missing shortcodes
    $args = array(
        'post_type'      => 'pick_sheets',
        'posts_per_page' => 1,
        'post_status'    => 'any',
    );
    $test_sheets = get_posts( $args );
    
    if ( ! empty( $test_sheets ) ) {
        $test_content = $test_sheets[0]->post_content;
        $has_picker = strpos( $test_content, '[pick_sheet_table]' ) !== false;
        $has_driver = strpos( $test_content, '[driver_pick_sheet_table]' ) !== false;
        
        if ( ! $has_picker || ! $has_driver ) {
            $url = add_query_arg( array(
                'guinco_add_shortcodes' => '1',
                '_wpnonce' => wp_create_nonce( 'guinco_add_shortcodes' )
            ), admin_url() );
            
            echo '<div class="notice notice-warning"><p><strong>Pick Sheet Unified v1.0.29+:</strong> Some pick sheets are missing required shortcodes for driver view. <a href="' . esc_url( $url ) . '" class="button button-primary">Fix Now</a></p></div>';
        }
    }
    
    // Show new feature notice for v1.0.33 (dismissible, only show once)
    $dismissed = get_option( 'guinco_ps_cancellations_notice_dismissed', false );
    if ( ! $dismissed ) {
        if ( isset( $_GET['dismiss_cancellations_notice'] ) && check_admin_referer( 'dismiss_cancellations_notice' ) ) {
            update_option( 'guinco_ps_cancellations_notice_dismissed', true );
            return;
        }
        
        $dismiss_url = add_query_arg( array(
            'dismiss_cancellations_notice' => '1',
            '_wpnonce' => wp_create_nonce( 'dismiss_cancellations_notice' )
        ), admin_url() );
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>🎉 New Feature in Pick Sheet Unified v1.0.33: Cancellations & Reschedules!</strong></p>';
        echo '<p>You can now digitally log service call cancellations and reschedules. The system will:</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>✓ Automatically exclude cancelled items from new pick sheets</li>';
        echo '<li>✓ Detect late cancellations and create return lists</li>';
        echo '<li>✓ Track returns with barcode scanning for full chain-of-custody</li>';
        echo '<li>✓ Include cancellation data in CSV reports</li>';
        echo '</ul>';
        echo '<p>';
        echo '<a href="' . admin_url( 'admin.php?page=pick-sheet-cancellations' ) . '" class="button button-primary">Try It Now</a> ';
        echo '<a href="' . esc_url( $dismiss_url ) . '" class="button">Dismiss</a> ';
        echo '<a href="' . plugins_url( 'CANCELLATIONS-FEATURE-v1.0.33.md', __FILE__ ) . '" target="_blank">Read Documentation</a>';
        echo '</p>';
        echo '</div>';
    }
}
add_action( 'admin_notices', 'guinco_picksheet_admin_notice' );
// Include imported plugin modules
require_once plugin_dir_path(__FILE__) . 'pick-sheet-importer/pick-sheet-auto-import-v3.3.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-manager/pick-sheet-central-manager-v1.2.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-driver/pick-sheet-driver-v1.2.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-search-fix/pick-sheet-search-fix.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-table-fix/pick-sheet-table-fix.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-cancellations/pick-sheet-cancellations-v1.0.php';
require_once plugin_dir_path(__FILE__) . 'debug-quick-pick.php';
