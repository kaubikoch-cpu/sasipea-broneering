<?php
/**
 * Praktikantide avaldused — loetakse Fluent Formsi vormist (vaikimisi "Praktikandi sooviavaldus").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sam_ff_available() {
	global $wpdb;
	$t = $wpdb->prefix . 'fluentform_submissions';
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

/** Vormi väljade nimed → sildid (ka veergude sees olevad väljad). */
function sam_ff_field_labels( $form_id ) {
	global $wpdb;
	$json   = $wpdb->get_var( $wpdb->prepare( "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $form_id ) ); // phpcs:ignore
	$data   = json_decode( (string) $json, true );
	$labels = array();
	$walk   = function ( $fields ) use ( &$walk, &$labels ) {
		foreach ( (array) $fields as $f ) {
			if ( ! empty( $f['columns'] ) ) {
				foreach ( $f['columns'] as $col ) {
					$walk( $col['fields'] ?? array() );
				}
				continue;
			}
			$name  = $f['attributes']['name'] ?? '';
			$label = $f['settings']['label'] ?? ( $f['settings']['admin_field_label'] ?? '' );
			if ( $name ) {
				$labels[ $name ] = wp_strip_all_tags( $label ? $label : $name );
			}
		}
	};
	$walk( $data['fields'] ?? array() );
	return $labels;
}

function sam_ff_value_to_text( $v ) {
	if ( is_array( $v ) ) {
		$parts = array();
		foreach ( $v as $x ) {
			$x = sam_ff_value_to_text( $x );
			if ( '' !== $x ) {
				$parts[] = $x;
			}
		}
		// Nimeväli (first_name, last_name) → tühikuga, muu → komaga.
		$is_name = (bool) array_filter(
			array_keys( $v ),
			function ( $k ) {
				return is_string( $k ) && false !== strpos( $k, '_name' );
			}
		);
		return implode( $is_name ? ' ' : ', ', $parts );
	}
	return trim( wp_strip_all_tags( (string) $v ) );
}

/** Avalduse lühikokkuvõte: nimi, e-post, telefon. */
function sam_ff_summary( $response ) {
	$s = array(
		'name'  => '',
		'email' => '',
		'phone' => '',
	);
	foreach ( (array) $response as $k => $v ) {
		$lk  = strtolower( $k );
		$txt = sam_ff_value_to_text( $v );
		if ( ! $s['name'] && ( false !== strpos( $lk, 'nimi' ) || false !== strpos( $lk, 'name' ) ) ) {
			$s['name'] = $txt;
		} elseif ( ! $s['email'] && ( false !== strpos( $lk, 'mail' ) || is_email( $txt ) ) ) {
			$s['email'] = $txt;
		} elseif ( ! $s['phone'] && ( false !== strpos( $lk, 'telefon' ) || false !== strpos( $lk, 'phone' ) ) ) {
			$s['phone'] = $txt;
		}
	}
	return $s;
}

function sam_ff_get_submissions( $form_id, $limit = 100 ) {
	global $wpdb;
	if ( ! $form_id || ! sam_ff_available() ) {
		return array();
	}
	return $wpdb->get_results( $wpdb->prepare( "SELECT id, status, created_at, response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d AND status NOT IN ('trashed','spam') ORDER BY id DESC LIMIT %d", $form_id, $limit ) ); // phpcs:ignore
}

function sam_ff_get_submission( $form_id, $id ) {
	global $wpdb;
	if ( ! $form_id || ! sam_ff_available() ) {
		return null;
	}
	return $wpdb->get_row( $wpdb->prepare( "SELECT id, status, created_at, response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d AND id = %d", $form_id, $id ) ); // phpcs:ignore
}

function sam_ff_set_status( $id, $status ) {
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'fluentform_submissions', array( 'status' => $status ), array( 'id' => (int) $id ) ); // phpcs:ignore
}

/** Fluent Formsi aeg on UTC-s? Ei — salvestatakse saidi ajas (current_time('mysql')). */
function sam_ff_date( $mysql ) {
	$ts = strtotime( $mysql );
	return $ts ? date_i18n( 'd.m.Y H:i', $ts ) : '';
}

/** Fluent Formsi vormide nimekiri seadete jaoks. */
function sam_ff_forms() {
	global $wpdb;
	$t = $wpdb->prefix . 'fluentform_forms';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
		return array();
	}
	return $wpdb->get_results( "SELECT id, title FROM {$t} ORDER BY id ASC" ); // phpcs:ignore
}
