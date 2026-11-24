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
require dirname( __DIR__ ) . '/includes/Kassa.php';
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}
