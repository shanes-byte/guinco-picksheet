<?php
/**
 * Plugin Name: Pick Sheet Unified
 * Description: Combines Pick Sheet Manager, Importer, Driver, and fix plugins into a single plugin.
 * Version: 1.0.10
 * Author: ChatGPT Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Include imported plugin modules
require_once plugin_dir_path(__FILE__) . 'pick-sheet-importer/pick-sheet-auto-import-v3.3.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-manager/pick-sheet-central-manager-v1.2.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-driver/pick-sheet-driver-v1.2.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-search-fix/pick-sheet-search-fix.php';
require_once plugin_dir_path(__FILE__) . 'pick-sheet-table-fix/pick-sheet-table-fix.php';
