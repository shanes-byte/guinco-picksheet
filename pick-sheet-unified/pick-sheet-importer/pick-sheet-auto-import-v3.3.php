<?php

//

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the custom post type used to store imported pick sheet data.
 */
function psai_register_post_type() {
    $labels = array(
        'name'               => 'Pick Sheets',
        'singular_name'      => 'Pick Sheet',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Pick Sheet',
        'edit_item'          => 'Edit Pick Sheet',
        'new_item'           => 'New Pick Sheet',
        'view_item'          => 'View Pick Sheet',
        'search_items'       => 'Search Pick Sheets',
        'not_found'          => 'No pick sheets found',
        'not_found_in_trash' => 'No pick sheets found in Trash',
        'menu_name'          => 'Pick Sheets'
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'pick-sheets' ),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 20,
        'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'custom-fields' ),
    );
    register_post_type( 'pick_sheets', $args );
}
add_action( 'init', 'psai_register_post_type' );

/**
 * Admin menu for uploading CSV files.
 */
function psai_register_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Pick Sheet Import',
        'Pick Sheet Import',
        'manage_options',
        'pick-sheet-import',
        'psai_import_page'
    );
}
add_action( 'admin_menu', 'psai_register_admin_menu' );

/**
 * Render the CSV import page.
 */
function psai_import_page() {
    echo '<div class="wrap"><h1>Import Pick Sheet CSVs</h1>';
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="pick_csv[]" accept=".csv" multiple required> ';
    echo '<input type="submit" name="psai_import_submit" class="button button-primary" value="Upload & Create Posts">';
    echo '</form>';

    if ( isset( $_POST['psai_import_submit'] ) && ! empty( $_FILES['pick_csv']['tmp_name'] ) ) {
        $count = 0;
        $files = $_FILES['pick_csv'];
        $upload_dir = wp_upload_dir();
        foreach ( $files['tmp_name'] as $index => $tmp_name ) {
            if ( ! empty( $tmp_name ) && is_uploaded_file( $tmp_name ) ) {
                $csv_data  = array_map( 'str_getcsv', file( $tmp_name ) );
                if ( empty( $csv_data ) || ! is_array( $csv_data ) ) {
                    continue;
                }
                $header    = array_map( 'sanitize_key', array_shift( $csv_data ) );
                $sheet_uid = 'PS-' . strtoupper( wp_generate_password( 8, false, false ) );
                // Determine a meaningful title based on technician name and date if available.
                $technician = '';
                $date       = current_time( 'Y-m-d' );
                foreach ( $csv_data as $row ) {
                    $data = array_combine( $header, $row );
                    if ( isset( $data['Technician'] ) && ! empty( $data['Technician'] ) ) {
                        $technician = $data['Technician'];
                    }
                    if ( isset( $data['Date'] ) && ! empty( $data['Date'] ) ) {
                        $date = $data['Date'];
                    }
                    // Use first row for fields only.
                    break;
                }
                $post_title = $technician ? $technician . ' – ' . $sheet_uid : 'Pick Sheet – ' . $sheet_uid;
                $post_id    = wp_insert_post( array(
                    'post_title'   => $post_title,
                    'post_type'    => 'pick_sheets',
                    'post_status'  => 'publish',
                    'meta_input'   => array(
                        'sheet_uid'          => $sheet_uid,
                        'items_table'        => maybe_serialize( $csv_data ),
                        'psai_header'        => maybe_serialize( $header ),
                        'completion_file'    => '',
                        'driver_completion_file' => '',
                    ),
                ) );
                if ( ! is_wp_error( $post_id ) ) {
                    // Optionally store technician name as meta.
                    if ( $technician ) {
                        update_post_meta( $post_id, 'technician_name', $technician );
                    }
                    $count++;
                }
            }
        }
        echo '<div class="updated notice"><p>' . esc_html( $count ) . ' pick sheet posts created.</p></div>';
    }

    echo '</div>';
}

/**
 * Shortcode to display the interactive pick sheet table on the front end.
 * Usage: [pick_sheet_table]
 */
function psai_pick_sheet_shortcode( $atts ) {
    if ( ! is_singular( 'pick_sheets' ) ) {
        return '';
    }
    global $post;
    $post_id = $post->ID;
    // SwiftPick view is now the default. Show full list view only if full_view=1 query param is present
    if ( ! isset( $_GET['full_view'] ) || intval( $_GET['full_view'] ) !== 1 ) {
        return psai_swift_pick_shortcode( array( 'id' => $post_id ) );
    }
    $items   = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header  = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $picked_items = maybe_unserialize( get_post_meta( $post_id, 'picked_items', true ) );
    if ( ! is_array( $picked_items ) ) {
        $picked_items = array();
    }
    $picked_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );
    if ( ! is_array( $picked_details ) ) {
        $picked_details = array();
    }
    $completed_time = get_post_meta( $post_id, 'completed_time', true );
    $last_saved     = get_post_meta( $post_id, 'last_saved_at_picker', true );

    ob_start();
    // Display a notice if completed.
    if ( $completed_time ) {
        echo '<div class="psai-complete-message">This pick sheet was completed on ' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', intval( $completed_time ) ) ) . '.</div>';
    }
    // Last saved timestamp.
    if ( $last_saved ) {
        echo '<div class="psai-last-saved">Last saved: ' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', intval( $last_saved ) ) ) . '</div>';
    }
    // Toggle link to SwiftPick view (default view)
    $swift_url = remove_query_arg( 'full_view', get_permalink( $post_id ) );
    echo '<p><a href="' . esc_url( $swift_url ) . '" class="psai-toggle-swift">Switch to SwiftPick</a></p>';
    // Save progress button (only if not completed).
    if ( ! $completed_time ) {
        echo '<button id="psai-save-progress" class="button button-secondary" style="margin-bottom:10px;">Save Progress</button>';
    }
    // Search input for scanning
    echo '<input id="psai-search" type="text" placeholder="Scan or search part..." style="margin-bottom:10px;width:200px;" />';
    // Start table
    echo '<table class="psai-table widefat fixed striped"><thead><tr>';
    foreach ( $header as $col ) {
        echo '<th class="psai-sortable" data-col="' . esc_attr( $col ) . '">' . esc_html( $col ) . '</th>';
    }
    echo '<th>Status</th></tr></thead><tbody>';
    // Render each item row with data-index for unique keys and store HTML in array
    $rows_html = array();
    foreach ( $items as $row_index => $row ) {
        $data = array();
        foreach ( $header as $index => $col ) {
            $data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
        }
        $part_number = $data['PartNumber'] ?? '';
        $bin_loc     = $data['BinLoc'] ?? '';
        $shelf_loc   = '';
        if ( isset( $data['Shelf'] ) ) {
            $shelf_loc = $data['Shelf'];
        } elseif ( isset( $data['ShelfNumber'] ) ) {
            $shelf_loc = $data['ShelfNumber'];
        } elseif ( isset( $data['ShelfLoc'] ) ) {
            $shelf_loc = $data['ShelfLoc'];
        }
        $picked      = in_array( $row_index, $picked_items, true );
        $detail      = isset( $picked_details[ $row_index ] ) ? $picked_details[ $row_index ] : array();
        // Build the row HTML
        $row_html = '<tr data-index="' . esc_attr( $row_index ) . '" data-part="' . esc_attr( $part_number ) . '" data-bin="' . esc_attr( $bin_loc ) . '" data-shelf="' . esc_attr( $shelf_loc ) . '">';
        foreach ( $header as $col ) {
            $value = isset( $data[ $col ] ) ? $data[ $col ] : '';
            $row_html .= '<td>' . esc_html( $value ) . '</td>';
        }
        // Determine status display
        if ( $completed_time ) {
            if ( ! empty( $detail ) ) {
                $row_html .= '<td><span style="color:green;">Completed</span></td>';
            } else {
                $row_html .= '<td><span style="color:red;">Not picked</span></td>';
            }
        } else {
            $checked    = $picked ? ' checked' : '';
            $row_html .= '<td><label><input type="checkbox" class="psai-pick-checkbox" value="' . esc_attr( $row_index ) . '"' . $checked . '> Picked</label></td>';
        }
        $row_html .= '</tr>';
        $rows_html[] = $row_html;
    }
    // Output rows
    foreach ( $rows_html as $r ) {
        echo $r;
    }
    echo '</tbody></table>';

    // Display completion button if not completed.
    if ( ! $completed_time ) {
        echo '<button id="psai-complete-sheet" class="button button-primary" style="margin-top:10px;">Complete Pick Sheet</button>';
    }

    // Placeholder for success message and log link.
    echo '<div id="psai-success" style="margin-top:10px;"></div>';

    $output = ob_get_clean();

    // Enqueue scripts and styles.
    psai_enqueue_scripts();
    // Localize data for JS.
    $data = array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'post_id'        => $post_id,
        'picked_items'   => $picked_items,
        'picked_details' => $picked_details,
        'completed'      => (bool) $completed_time,
        'nonce'          => wp_create_nonce( 'psai_nonce' ),
    );
    wp_localize_script( 'psai-script', 'psaiData', $data );

    return $output;
}

/**
 * Shortcode to display simplified picker UI showing only the next item to pick.
 *
 * Usage: [swift_pick_sheet order="asc|desc"]
 * This UI displays one row at a time (technician, part number, description, BinLoc) and
 * guides the picker through the scanning steps (part → bin → shelf).
 * It is designed for HTML5 "Add to Home Screen" usage with PWA features.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content for the simplified picker UI.
 */
function psai_swift_pick_shortcode( $atts ) {
    // Accept a sheet ID attribute so the shortcode can be used outside of a single pick_sheets post.
    $atts = shortcode_atts(
        array(
            'order' => 'asc',
            'id'    => 0,
        ),
        $atts,
        'swift_pick_sheet'
    );
    $order = strtolower( $atts['order'] ) === 'desc' ? 'desc' : 'asc';
    // Determine which pick sheet post to use: id attribute if provided, otherwise current post ID
    $post_id = intval( $atts['id'] );
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }
    // Ensure we are working with a pick_sheets post
    if ( get_post_type( $post_id ) !== 'pick_sheets' ) {
        return '<p>No pick sheet specified.</p>';
    }
    $items        = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header       = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $picked_items = maybe_unserialize( get_post_meta( $post_id, 'picked_items', true ) );
    $picked_items = is_array( $picked_items ) ? $picked_items : array();
    $picked_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );
    $picked_details = is_array( $picked_details ) ? $picked_details : array();
    if ( ! is_array( $items ) || ! is_array( $header ) ) {
        return '<p>No data.</p>';
    }
    // Determine unpicked items keyed by row index (not part number) so duplicates can be handled separately
    $unpicked = array();
    foreach ( $items as $idx => $row ) {
        // Check if this row has been picked: a row is considered picked if shelf is present in picked_details
        if ( ! isset( $picked_details[ $idx ] ) || empty( $picked_details[ $idx ]['shelf'] ) ) {
            $unpicked[ $idx ] = $row;
        }
    }
    // Sort unpicked items by BinLoc ascending or descending while preserving keys
    $bin_index = array_search( 'BinLoc', $header, true );
    if ( $bin_index !== false ) {
        if ( $order === 'desc' ) {
            uasort( $unpicked, function ( $a, $b ) use ( $bin_index ) {
                return strnatcasecmp( $b[ $bin_index ], $a[ $bin_index ] );
            } );
        } else {
            uasort( $unpicked, function ( $a, $b ) use ( $bin_index ) {
                return strnatcasecmp( $a[ $bin_index ], $b[ $bin_index ] );
            } );
        }
    }
    // Get next item (first unpicked)
    $next_index = key( $unpicked );
    $next_row   = $next_index !== null ? $unpicked[ $next_index ] : null;
    // Look up column indexes
    $tech_idx   = array_search( 'Technician', $header, true );
    $desc_idx   = array_search( 'Description', $header, true );
    $part_idx   = array_search( 'PartNumber', $header, true );
    $bin_idx    = array_search( 'BinLoc', $header, true );
    $tech       = $next_row && $tech_idx !== false && isset( $next_row[ $tech_idx ] ) ? $next_row[ $tech_idx ] : '';
    $desc       = $next_row && $desc_idx !== false && isset( $next_row[ $desc_idx ] ) ? $next_row[ $desc_idx ] : '';
    $part       = $next_row && $part_idx !== false && isset( $next_row[ $part_idx ] ) ? $next_row[ $part_idx ] : '';
    $bin        = $next_row && $bin_idx !== false && isset( $next_row[ $bin_idx ] ) ? $next_row[ $bin_idx ] : '';
    ob_start();
    echo '<div class="psai-swift-container">';
    // Add link back to full view for toggling
    $full_url = add_query_arg( 'full_view', '1', get_permalink( $post_id ) );
    echo '<p><a href="' . esc_url( $full_url ) . '" class="psai-toggle-full">Switch to Full View</a></p>';
    echo '<div class="psai-swift-item">';
    if ( $next_row ) {
        echo '<h3>Next Item to Pick</h3>';
        echo '<table class="widefat psai-swift-table">';
        echo '<thead><tr><th>Technician</th><th>Part Number</th><th>Description</th><th>Bin Location</th></tr></thead>';
        echo '<tbody>';
        echo '<tr data-index="' . esc_attr( $next_index ) . '" data-part="' . esc_attr( $part ) . '">';
        echo '<td>' . esc_html( $tech ) . '</td>';
        echo '<td>' . esc_html( $part ) . '</td>';
        echo '<td>' . esc_html( $desc ) . '</td>';
        echo '<td>' . esc_html( $bin ) . '</td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '<input type="text" id="psai-swift-search" placeholder="Scan or search part..." style="margin-top:10px;width:200px;" />';
        echo '<button id="psai-swift-save" class="button button-secondary" style="margin-top:10px;">Save Progress</button>';
        echo '<button id="psai-swift-complete" class="button button-primary" style="margin-top:10px;">Complete Pick Sheet</button>';
    } else {
        echo '<p>All items picked!</p>';
    }
    echo '</div>';
    echo '<div id="psai-swift-feedback" style="margin-top:10px;"></div>';
    $output = ob_get_clean();
    // Enqueue SwiftPick UI script and styles
    wp_enqueue_script( 'psai-swift-script', plugins_url( 'pick-sheet-importer/psai-swift.js', __FILE__ ), array( 'jquery' ), '1.0', true );
    // Localize data for SwiftPick UI
    $data = array(
        'ajax_url'        => admin_url( 'admin-ajax.php' ),
        'post_id'         => $post_id,
        'current_index'   => $next_index,
        'picked_items'    => $picked_items,
        'picked_details'  => $picked_details,
        'order'           => $order,
        'nonce'           => wp_create_nonce( 'psai_nonce' ),
    );
    wp_localize_script( 'psai-swift-script', 'psaiSwiftData', $data );
    // Output manifest link and meta for PWA (only on SwiftPick UI)
    add_action( 'wp_head', 'psai_swift_manifest_link' );
    return $output;
}
add_shortcode( 'swift_pick_sheet', 'psai_swift_pick_shortcode' );

/**
 * Output manifest link and meta tags for PWA on SwiftPick UI.
 */
function psai_swift_manifest_link() {
    echo '<link rel="manifest" href="' . esc_url( plugins_url( 'pick-sheet-importer/manifest.json', __FILE__ ) ) . '">';
    echo '<meta name="theme-color" content="#0073aa">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
}
add_shortcode( 'pick_sheet_table', 'psai_pick_sheet_shortcode' );

/**
 * Enqueue frontend scripts and styles for the pick sheet table.
 */
function psai_enqueue_scripts() {
    // Only enqueue on single pick_sheets pages or if shortcode used.
    if ( ! is_singular( 'pick_sheets' ) ) {
        return;
    }
    wp_enqueue_style( 'psai-style', plugins_url( 'psai-style.css', __FILE__ ), array(), '3.3' );
    wp_enqueue_script( 'psai-script', plugins_url( 'psai-script.js', __FILE__ ), array( 'jquery' ), '3.3', true );
}

/**
 * AJAX handler to save picker progress.
 */
function psai_ajax_save_progress() {
    check_ajax_referer( 'psai_nonce', 'nonce' );
    $post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;
    
    // Verify user can edit the post
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    
    $picked_items = isset( $_POST['picked_items'] ) ? (array) $_POST['picked_items'] : array();
    $picked_items = array_map( 'intval', array_map( 'wp_unslash', $picked_items ) );
    
    $picked_details_raw = isset( $_POST['picked_details'] ) ? (array) $_POST['picked_details'] : array();
    $picked_details = array();
    foreach ( $picked_details_raw as $idx => $details ) {
        if ( ! is_array( $details ) ) {
            continue;
        }
        $idx = intval( $idx );
        $picked_details[ $idx ] = array(
            'bin'   => isset( $details['bin'] ) ? sanitize_text_field( wp_unslash( $details['bin'] ) ) : '',
            'shelf' => isset( $details['shelf'] ) ? sanitize_text_field( wp_unslash( $details['shelf'] ) ) : '',
            'time'  => isset( $details['time'] ) ? intval( wp_unslash( $details['time'] ) ) : 0,
        );
    }
    
    // Get previous state to detect new shelf scans
    $previous_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );
    if ( ! is_array( $previous_details ) ) {
        $previous_details = array();
    }
    
    // Get items and header for custody logging
    $items = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $tech_name = get_post_meta( $post_id, 'technician_name', true );
    $tech_id = guinco_resolve_tech_id( $tech_name );
    $actor_user_id = get_current_user_id();
    $device_id = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 64 ) : 'unknown';
    
    // Log PICKED events for newly scanned shelves
    if ( is_array( $items ) && is_array( $header ) ) {
        foreach ( $picked_details as $idx => $details ) {
            if ( ! isset( $details['shelf'] ) || empty( $details['shelf'] ) ) {
                continue;
            }
            
            // Check if this is a new scan (shelf wasn't in previous details)
            $was_previously_scanned = isset( $previous_details[ $idx ] ) && ! empty( $previous_details[ $idx ]['shelf'] );
            if ( $was_previously_scanned ) {
                continue; // Already logged
            }
            
            // Extract row data
            if ( ! isset( $items[ $idx ] ) ) {
                continue;
            }
            
            $row = $items[ $idx ];
            $data = array();
            foreach ( $header as $index => $col ) {
                $data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
            }
            
            $part_number = $data['PartNumber'] ?? '';
            $qty = 1; // Default to 1 if qty not specified
            if ( isset( $data['Qty'] ) ) {
                $qty = absint( $data['Qty'] );
            } elseif ( isset( $data['Quantity'] ) ) {
                $qty = absint( $data['Quantity'] );
            }
            if ( $qty <= 0 ) {
                $qty = 1;
            }
            
            if ( $part_number && $tech_id > 0 ) {
                try {
                    guinco_log_custody_event( array(
                        'pick_sheet_id' => $post_id,
                        'tech_id' => $tech_id,
                        'part_number' => $part_number,
                        'qty' => $qty,
                        'action' => 'PICKED',
                        'from_loc' => 'WAREHOUSE_SHELF',
                        'to_loc' => 'DRIVER_TRUCK', // Picked items ready for driver loading
                        'actor_role' => 'PICKER',
                        'actor_user_id' => $actor_user_id,
                        'device_id' => $device_id,
                        'source' => 'picker',
                        'extra_json' => array(
                            'bin' => $details['bin'] ?? '',
                            'shelf' => $details['shelf'],
                            'pick_time' => $details['time'] ?? time(),
                        ),
                    ) );
                } catch ( Exception $e ) {
                    error_log( '[GUINCO] Picker event log failed: ' . $e->getMessage() );
                }
            }
        }
    }
    
    update_post_meta( $post_id, 'picked_items', maybe_serialize( $picked_items ) );
    update_post_meta( $post_id, 'picked_details', maybe_serialize( $picked_details ) );
    update_post_meta( $post_id, 'last_saved_at_picker', time() );
    wp_send_json_success( array( 'message' => 'Saved at ' . date_i18n( get_option( 'date_format' ) . ' H:i' ) ) );
}
add_action( 'wp_ajax_psai_save_progress', 'psai_ajax_save_progress' );

/**
 * AJAX handler to complete pick sheet and generate CSV log.
 */
function psai_ajax_complete_sheet() {
    check_ajax_referer( 'psai_nonce', 'nonce' );
    $post_id = isset( $_POST['post_id'] ) ? intval( wp_unslash( $_POST['post_id'] ) ) : 0;
    
    // Verify user can edit the post
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    $items          = maybe_unserialize( get_post_meta( $post_id, 'items_table', true ) );
    $header         = maybe_unserialize( get_post_meta( $post_id, 'psai_header', true ) );
    $picked_details = maybe_unserialize( get_post_meta( $post_id, 'picked_details', true ) );
    if ( ! is_array( $items ) ) {
        wp_send_json_error( 'No items' );
    }
    
    // Create CSV file (for backward compatibility).
    $upload_dir = wp_upload_dir();
    $dir        = trailingslashit( $upload_dir['basedir'] ) . 'pick_sheet_logs/';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $filename   = 'pick_sheet_' . $post_id . '_' . time() . '.csv';
    $filepath   = $dir . $filename;
    $fh         = fopen( $filepath, 'w' );
    // Write header with extra scanned details columns.
    $extended_header = $header;
    $extended_header[] = 'Picked Bin';
    $extended_header[] = 'Picked Shelf';
    $extended_header[] = 'Pick Time';
    fputcsv( $fh, $extended_header );
    foreach ( $items as $idx => $row ) {
        $data = array();
        foreach ( $header as $index => $col ) {
            $data[ $col ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
        }
        // Use row index to look up picked details
        $details = isset( $picked_details[ $idx ] ) ? $picked_details[ $idx ] : array();
        $bin  = $details['bin'] ?? '';
        $shelf= $details['shelf'] ?? '';
        $time = $details['time'] ?? '';
        $extended_row = $row;
        $extended_row[] = $bin;
        $extended_row[] = $shelf;
        $extended_row[] = $time;
        fputcsv( $fh, $extended_row );
    }
    fclose( $fh );
    // Save file URL in meta.
    $file_url = trailingslashit( $upload_dir['baseurl'] ) . 'pick_sheet_logs/' . $filename;
    update_post_meta( $post_id, 'completion_file', $file_url );
    update_post_meta( $post_id, 'completed_time', time() );
    wp_send_json_success( array( 'file_url' => $file_url ) );
}
add_action( 'wp_ajax_psai_complete_sheet', 'psai_ajax_complete_sheet' );

/**
 * Save section order preference via AJAX.
 */
function psai_ajax_save_order_pref() {
    check_ajax_referer( 'psai_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }
    $pref = sanitize_text_field( $_POST['order'] );
    update_user_meta( get_current_user_id(), 'psai_section_order', $pref );
    wp_send_json_success();
}
add_action( 'wp_ajax_psai_save_order_pref', 'psai_ajax_save_order_pref' );

/**
 * Removed unused psai_add_ajax_nonce filter - nonce is now added directly in psai_pick_sheet_shortcode().
 */