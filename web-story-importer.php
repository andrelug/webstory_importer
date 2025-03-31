<?php
/**
 * Plugin Name: Web Story Importer
 * Plugin URI: https://github.com/andrelug/webstory_importer
 * Description: Import Web Stories from various formats into the Google Web Stories plugin
 * Version: 1.1.0
 * Author: André Lug
 * Author URI: https://andrelug.com
 * License: GPL-2.0+    
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: web-story-importer
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WSI_VERSION', '1.1.0' );
define( 'WSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSI_PLUGIN_FILE', __FILE__ );

// Include required files
require_once WSI_PLUGIN_DIR . 'includes/functions.php';
require_once WSI_PLUGIN_DIR . 'includes/admin-page.php';
require_once WSI_PLUGIN_DIR . 'includes/api.php';

/**
 * Load plugin textdomain.
 */
function wsi_load_textdomain() {
	load_plugin_textdomain( 'web-story-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wsi_load_textdomain' );

/**
 * Check dependencies on activation.
 */
function wsi_activate() {
    // Check if Web Stories plugin is active
    if ( ! class_exists( 'Google\Web_Stories\Plugin' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'Please install and activate the Web Stories plugin before activating this plugin.' );
    }

    // Create database table for tracking imported stories
    wsi_create_stories_table();
}
register_activation_hook( WSI_PLUGIN_FILE, 'wsi_activate' );

/**
 * Create the database table for tracking imported stories.
 */
function wsi_create_stories_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wsi_imported_stories';
    $charset_collate = $wpdb->get_charset_collate();
    
    // Check if table already exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            story_title varchar(255) NOT NULL,
            original_file varchar(255) NOT NULL,
            post_id bigint(20) NOT NULL,
            import_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'completed',
            messages text,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// --- Add other hooks and functions below ---
