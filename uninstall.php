<?php
/**
 * Plugina kustutamisel andmeid EI eemaldata, et broneeringud kogemata kaduma ei läheks.
 * Andmete täielikuks eemaldamiseks lisa wp-config.php faili:
 *   define( 'SB_REMOVE_DATA_ON_UNINSTALL', true );
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'sb_cron_reminders' );
wp_clear_scheduled_hook( 'sb_cron_daily' );

if ( defined( 'SB_REMOVE_DATA_ON_UNINSTALL' ) && SB_REMOVE_DATA_ON_UNINSTALL ) {
	global $wpdb;
	foreach ( array( 'categories', 'services', 'staff', 'staff_services', 'schedules', 'schedule_exceptions', 'blocks', 'clients', 'bookings', 'consents', 'booking_log', 'products', 'sales' ) as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sb_' . $t ); // phpcs:ignore
	}
	delete_option( 'sb_settings' );
	delete_option( 'sb_db_version' );
	delete_option( 'sb_months' );
	remove_role( 'sb_praktikant' );
}
