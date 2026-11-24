<?php
// Availability on puhas PHP, WordPressi pole testide jaoks vaja.
require dirname( __DIR__ ) . '/includes/Availability.php';
require dirname( __DIR__ ) . '/includes/Pricing.php';
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}
