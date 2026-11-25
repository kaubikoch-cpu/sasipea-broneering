<?php
/**
 * Seadistus broneerimissüsteemi osana: õigused, vaikimisi kategooriad, kaitstud piltide kaust ja cron.
 * Sisselogimise, suunamise ja töölaua eest vastab broneerimissüsteemi töölaud (/salong/).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sam_activate() {
	sam_register_types();
	sam_setup_roles();

	// Vaikimisi kategooriad.
	foreach ( sam_taxonomies() as $tax => $cfg ) {
		foreach ( $cfg['default'] as $name ) {
			if ( ! term_exists( $name, $tax ) ) {
				wp_insert_term( $name, $tax );
			}
		}
	}

	// Praktikantide vorm: otsi Fluent Formsist nime järgi.
	if ( ! (int) sam_setting( 'practicum_form' ) ) {
		$fid = sam_find_fluent_form( 'Praktikandi' );
		if ( $fid ) {
			sam_update_setting( 'practicum_form', $fid );
		}
	}

	sam_ensure_private_dir();

	if ( ! wp_next_scheduled( 'sam_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sam_daily_cleanup' );
	}
	update_option( 'sam_version', SAM_VERSION );
}

function sam_deactivate() {
	wp_clear_scheduled_hook( 'sam_daily_cleanup' );
}

function sam_setup_roles() {
	$role = get_role( 'sam_juhendaja' );
	if ( ! $role ) {
		add_role( 'sam_juhendaja', 'Juhendaja', array( 'read' => true, SAM_CAP => true ) );
	} else {
		$role->add_cap( SAM_CAP );
	}
	// Broneerimissüsteemi omanik ja juhendaja haldavad ka modelle.
	foreach ( array( 'administrator', 'sb_omanik', 'sb_juhendaja' ) as $slug ) {
		$r = get_role( $slug );
		if ( $r ) {
			$r->add_cap( SAM_CAP );
		}
	}
}

// Uuendusel (kui plugin vahetati failide kaudu) käivita seadistus uuesti.
add_action( 'init', 'sam_maybe_upgrade', 5 );
function sam_maybe_upgrade() {
	if ( get_option( 'sam_version' ) !== SAM_VERSION ) {
		sam_activate();
	}
}

function sam_find_fluent_form( $title_like ) {
	global $wpdb;
	$table = $wpdb->prefix . 'fluentform_forms';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return 0;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE title LIKE %s ORDER BY id ASC LIMIT 1", '%' . $wpdb->esc_like( $title_like ) . '%' ) ); // phpcs:ignore
}

/**
 * Modellide vaated asuvad salongi töölaual. Vana plugina vaadete nimed teisendatakse töölaua omadeks.
 */
function sam_dashboard_url( $args = array() ) {
	$map  = array(
		'modellid'     => 'modellid',
		'modell'       => 'modell',
		'lisa'         => 'lisa-modell',
		'praktikandid' => 'avaldused',
		'avaldus'      => 'avaldus',
	);
	$view = isset( $args['vaade'], $map[ $args['vaade'] ] ) ? $map[ $args['vaade'] ] : 'modellid';
	unset( $args['vaade'] );
	$url = \SB\Dashboard::url( $view );
	return $args ? add_query_arg( $args, $url ) : $url;
}
