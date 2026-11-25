<?php
/**
 * Plugin Name:       Sasipea Broneering
 * Description:       Sasipea Akadeemia online-broneerimine ja päevaplaan (asendab Saloninfrat).
 * Version:           0.16.2
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Sasipea Akadeemia
 * Text Domain:       sasipea-broneering
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'SB_VERSION', '0.16.2' );
define( 'SB_DB_VERSION', '14' );
define( 'SB_FILE', __FILE__ );
define( 'SB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'SB\\' ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, 3 ) );
		$file = SB_DIR . 'includes/' . $rel . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SB\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SB\\Cron', 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'sasipea-broneering', false, dirname( plugin_basename( SB_FILE ) ) . '/languages' );
		SB\Install::maybe_upgrade();
		SB\Rest\PublicController::init();
		SB\Rest\AdminController::init();
		SB\Shortcode::init();
		SB\Cron::init();
		SB\Privacy::init();
		SB\Mailer::init();
		SB\Dashboard::init();
		SB\Users::init();
		SB\ModellidModule::init();
		SB\Integrations\Modellid::init();
		if ( is_admin() ) {
			SB\Admin::init();
		}
	}
);
