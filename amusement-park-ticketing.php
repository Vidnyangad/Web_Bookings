<?php
/**
 * Plugin Name: Amusement Park Ticketing
 * Description: Online ticketing and booking system for an amusement park, integrating with WooCommerce.
 * Version: 1.4.0
 * Author: Jules
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'APT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'APT_VERSION', '1.4.0' );

// Include necessary files
require_once APT_PLUGIN_DIR . 'includes/class-apt-admin.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-frontend.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-scanner.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-woocommerce.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-tickets.php';
require_once APT_PLUGIN_DIR . 'includes/class-apt-barcode.php';

/**
 * Plugin Activation
 */
function apt_activate_plugin() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Table for explicit inventory overrides/bookings per date and slot
    $table_inventory = $wpdb->prefix . 'apt_inventory';
    $sql_inventory = "CREATE TABLE $table_inventory (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        visit_date date NOT NULL,
        time_slot varchar(20) NOT NULL,
        adult_capacity int(11) DEFAULT NULL, /* NULL means fallback to default settings */
        adult_booked int(11) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY date_slot (visit_date, time_slot)
    ) $charset_collate;";

    // Table for temporary cart holds (5 minutes)
    $table_holds = $wpdb->prefix . 'apt_holds';
    $sql_holds = "CREATE TABLE $table_holds (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        visit_date date NOT NULL,
        time_slot varchar(20) NOT NULL,
        adult_quantity int(11) DEFAULT 0 NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Table for tracking tickets and check-in status
    $table_tickets = $wpdb->prefix . 'apt_tickets';
    $sql_tickets = "CREATE TABLE $table_tickets (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        visit_date date NOT NULL,
        time_slot varchar(20) NOT NULL,
        adult_qty int(11) DEFAULT 0 NOT NULL,
        child_qty int(11) DEFAULT 0 NOT NULL,
        infant_qty int(11) DEFAULT 0 NOT NULL,
        pnr varchar(10) DEFAULT NULL,
        status varchar(20) DEFAULT 'valid' NOT NULL, /* valid, used */
        checkin_time datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        UNIQUE KEY pnr (pnr)
    ) $charset_collate;";

    dbDelta( $sql_inventory );
    dbDelta( $sql_holds );
    dbDelta( $sql_tickets );

    update_option( 'apt_db_version', APT_VERSION );
}
register_activation_hook( __FILE__, 'apt_activate_plugin' );

/**
 * Re-runs dbDelta automatically whenever the plugin version changes (e.g.
 * after uploading an updated zip), so schema changes like the pnr UNIQUE
 * key take effect without requiring a manual deactivate/reactivate.
 */
function apt_maybe_upgrade_db() {
    if ( get_option( 'apt_db_version' ) !== APT_VERSION ) {
        apt_activate_plugin();
    }
}
add_action( 'plugins_loaded', 'apt_maybe_upgrade_db' );

/**
 * Plugin Deactivation
 */
function apt_deactivate_plugin() {
    // Optionally clean up scheduled hooks here
}
register_deactivation_hook( __FILE__, 'apt_deactivate_plugin' );
