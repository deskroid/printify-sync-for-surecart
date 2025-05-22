<?php
/**
 * Plugin Name: Printify SureCart Sync
 * Plugin URI: https://github.com/yourusername/printify-surecart-sync
 * Description: Syncs products and orders from Printify to SureCart
 * Version: 1.0.0
 * Author: David Leavitt
 * Author URI: https://david.leavitt.pro
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: printify-surecart-sync
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('PRINTIFY_SURECART_SYNC_VERSION', '1.0.0');
define('PRINTIFY_SURECART_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PRINTIFY_SURECART_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load the main plugin class
require_once PRINTIFY_SURECART_SYNC_PLUGIN_DIR . 'includes/class-printify-surecart-sync.php';

// Initialize the plugin
$printify_surecart_sync = new Printify_SureCart_Sync_Main();