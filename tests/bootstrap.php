<?php
// Availability on puhas PHP, WordPressi pole testide jaoks vaja.
require dirname( __DIR__ ) . '/includes/Availability.php';
require dirname( __DIR__ ) . '/includes/Pricing.php';
require dirname( __DIR__ ) . '/includes/Payroll.php';
require dirname( __DIR__ ) . '/includes/Vat.php';
// Woo::match on puhas funktsioon; fail ise nõuab ABSPATH konstanti.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/Integrations/Woo.php';
require dirname( __DIR__ ) . '/includes/Integrations/Modellid.php';
// Kassa veateated ja summade vormindus: tõlkefunktsioon tagastab testis algse teksti.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { // phpcs:ignore
		return $text;
	}
}
require dirname( __DIR__ ) . '/includes/Settings.php';
require dirname( __DIR__ ) . '/includes/Kassa.php';
require dirname( __DIR__ ) . '/includes/Repo.php';
require dirname( __DIR__ ) . '/includes/Roles.php';
require dirname( __DIR__ ) . '/includes/Mailer.php';
require dirname( __DIR__ ) . '/includes/Users.php';
require dirname( __DIR__ ) . '/includes/Dashboard.php';
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}
