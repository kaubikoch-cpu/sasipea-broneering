<?php
/**
 * Andmemudel: modelli postitüüp, kategooriad (taksonoomiad), staatused, seaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kategooriarühmad. Võti = taksonoomia nimi.
 * multi = kas võib valida mitu; public = kas modell valib ise vormis.
 */
function sam_taxonomies() {
	return array(
		'sam_sugu'    => array(
			'label'   => 'Sugu',
			'plural'  => 'Sugu',
			'multi'   => false,
			'public'  => true,
			'ask'     => 'Sugu',
			'default' => array( 'Naine', 'Mees' ),
		),
		'sam_juuksed' => array(
			'label'   => 'Juuksevärv',
			'plural'  => 'Juuksevärvid',
			'multi'   => false,
			'public'  => true,
			'ask'     => 'Sinu praegune juuksevärv',
			'default' => array( 'Tumedad', 'Heledad / blond', 'Punakad', 'Hallid / hallikad' ),
		),
		'sam_pikkus'  => array(
			'label'   => 'Juuste pikkus',
			'plural'  => 'Juuste pikkused',
			'multi'   => false,
			'public'  => true,
			'ask'     => 'Juuste pikkus',
			'default' => array( 'Lühikesed', 'Keskmised', 'Pikad' ),
		),
		'sam_teenus'  => array(
			'label'   => 'Teenus',
			'plural'  => 'Teenused',
			'multi'   => true,
			'public'  => true,
			'ask'     => 'Milliseks modelliks oled valmis? (vali kõik sobivad)',
			'default' => array( 'Lõikus', 'Värvimine', 'Hallikatvus', 'Triibutamine', 'Heledaks / blondeerimine', 'Habe', 'Soeng', 'Hooldus' ),
		),
	);
}

function sam_statuses() {
	return array(
		'uus'       => 'Uus',
		'aktiivne'  => 'Aktiivne',
		'arhiiv'    => 'Arhiveeritud',
	);
}

function sam_settings() {
	$defaults = array(
		'notify_email'    => get_option( 'admin_email' ),
		'retention_years' => 2,
		'practicum_form'  => 0,
		'dashboard_page'  => 0,
		'register_page'   => 0,
	);
	$saved = get_option( 'sam_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function sam_setting( $key ) {
	$s = sam_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

function sam_update_setting( $key, $value ) {
	$s         = sam_settings();
	$s[ $key ] = $value;
	update_option( 'sam_settings', $s );
}

add_action( 'init', 'sam_register_types' );
function sam_register_types() {
	$caps = array(
		'edit_post'              => SAM_CAP,
		'read_post'              => SAM_CAP,
		'delete_post'            => SAM_CAP,
		'edit_posts'             => SAM_CAP,
		'edit_others_posts'      => SAM_CAP,
		'publish_posts'          => SAM_CAP,
		'read_private_posts'     => SAM_CAP,
		'delete_posts'           => SAM_CAP,
		'delete_others_posts'    => SAM_CAP,
		'delete_published_posts' => SAM_CAP,
		'edit_published_posts'   => SAM_CAP,
		'create_posts'           => SAM_CAP,
	);

	register_post_type(
		SAM_CPT,
		array(
			'labels'              => array(
				'name'          => 'Modellid',
				'singular_name' => 'Modell',
				'menu_name'     => 'Modellid',
				'all_items'     => 'Kõik modellid',
				'edit_item'     => 'Muuda modelli',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-groups',
			'menu_position'       => 26,
			'supports'            => array( 'title' ),
			'capabilities'        => $caps,
			'map_meta_cap'        => false,
			'rewrite'             => false,
			'query_var'           => false,
		)
	);

	foreach ( sam_taxonomies() as $tax => $cfg ) {
		register_taxonomy(
			$tax,
			SAM_CPT,
			array(
				'labels'            => array(
					'name'          => $cfg['plural'],
					'singular_name' => $cfg['label'],
					'menu_name'     => $cfg['plural'],
					'add_new_item'  => 'Lisa uus',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => SAM_CAP,
				),
			)
		);
	}
}

/** Kategooria terminid järjekorras (term_order / nimi). */
function sam_terms( $tax ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/** Modelli andmed ühes massiivis. */
function sam_get_model( $post ) {
	$post = get_post( $post );
	if ( ! $post || SAM_CPT !== $post->post_type ) {
		return null;
	}
	$id = $post->ID;
	$m  = array(
		'id'           => $id,
		'name'         => $post->post_title,
		'email'        => get_post_meta( $id, '_sam_email', true ),
		'phone'        => get_post_meta( $id, '_sam_phone', true ),
		'availability' => get_post_meta( $id, '_sam_availability', true ),
		'info'         => get_post_meta( $id, '_sam_info', true ),
		'notes'        => get_post_meta( $id, '_sam_notes', true ),
		'status'       => get_post_meta( $id, '_sam_status', true ) ?: 'uus',
		'source'       => get_post_meta( $id, '_sam_source', true ) ?: 'vorm',
		'consent'      => (int) get_post_meta( $id, '_sam_consent', true ),
		'last_contact' => (int) get_post_meta( $id, '_sam_last_contact', true ),
		'created'      => strtotime( $post->post_date_gmt . ' UTC' ),
		'photos'       => sam_get_photos( $id ),
		'terms'        => array(),
	);
	foreach ( array_keys( sam_taxonomies() ) as $tax ) {
		$t                    = wp_get_object_terms( $id, $tax );
		$m['terms'][ $tax ] = is_wp_error( $t ) ? array() : $t;
	}
	return $m;
}

/** Kuupäev, millal modell automaatselt kustub. */
function sam_expiry_ts( $m ) {
	$base = $m['last_contact'] ? $m['last_contact'] : $m['created'];
	return $base + (int) sam_setting( 'retention_years' ) * YEAR_IN_SECONDS;
}

function sam_date( $ts ) {
	return $ts ? wp_date( 'd.m.Y', $ts ) : '–';
}

/** Puhastab telefoninumbri (jätab +, numbrid ja tühikud). */
function sam_clean_phone( $v ) {
	$v = preg_replace( '/[^0-9+ ]/', '', (string) $v );
	return trim( preg_replace( '/\s+/', ' ', $v ) );
}

/**
 * Salvestab modelli. $data: name,email,phone,availability,info,notes,status,terms[tax=>[ids]].
 * Tagastab postituse ID või WP_Error.
 */
function sam_save_model( $data, $id = 0 ) {
	$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
	if ( '' === $name ) {
		return new WP_Error( 'sam_name', 'Nimi on kohustuslik.' );
	}

	$postarr = array(
		'post_type'   => SAM_CPT,
		'post_title'  => $name,
		'post_status' => 'publish',
	);
	if ( $id ) {
		$postarr['ID'] = $id;
		$res           = wp_update_post( $postarr, true );
	} else {
		$res = wp_insert_post( $postarr, true );
	}
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$id = (int) $res;

	$fields = array(
		'email'        => '_sam_email',
		'phone'        => '_sam_phone',
		'availability' => '_sam_availability',
		'info'         => '_sam_info',
		'notes'        => '_sam_notes',
	);
	foreach ( $fields as $key => $meta ) {
		if ( ! array_key_exists( $key, $data ) ) {
			continue;
		}
		$val = $data[ $key ];
		if ( 'email' === $key ) {
			$val = sanitize_email( $val );
		} elseif ( 'phone' === $key ) {
			$val = sam_clean_phone( $val );
		} elseif ( in_array( $key, array( 'info', 'notes' ), true ) ) {
			$val = sanitize_textarea_field( $val );
		} else {
			$val = sanitize_text_field( $val );
		}
		update_post_meta( $id, $meta, $val );
	}

	if ( isset( $data['status'] ) && array_key_exists( $data['status'], sam_statuses() ) ) {
		update_post_meta( $id, '_sam_status', $data['status'] );
	}
	foreach ( array( 'consent', 'last_contact', 'source' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			update_post_meta( $id, '_sam_' . $k, $data[ $k ] );
		}
	}

	if ( isset( $data['terms'] ) && is_array( $data['terms'] ) ) {
		foreach ( sam_taxonomies() as $tax => $cfg ) {
			$ids = isset( $data['terms'][ $tax ] ) ? (array) $data['terms'][ $tax ] : array();
			$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			if ( ! $cfg['multi'] ) {
				$ids = array_slice( $ids, 0, 1 );
			}
			// Ainult olemasolevad terminid.
			$ids = array_values(
				array_filter(
					$ids,
					function ( $tid ) use ( $tax ) {
						return (bool) term_exists( $tid, $tax );
					}
				)
			);
			wp_set_object_terms( $id, $ids, $tax, false );
		}
	}

	return $id;
}

/** Kustutab modelli koos piltidega. */
function sam_delete_model( $id ) {
	sam_delete_all_photos( $id );
	wp_delete_post( $id, true );
}

add_action( 'before_delete_post', 'sam_before_delete' );
function sam_before_delete( $post_id ) {
	if ( SAM_CPT === get_post_type( $post_id ) ) {
		sam_delete_all_photos( $post_id );
	}
}

/** Igapäevane puhastus: kustutab modellid, kelle säilitusaeg on läbi. */
add_action( 'sam_daily_cleanup', 'sam_run_cleanup' );
function sam_run_cleanup() {
	$years = max( 1, (int) sam_setting( 'retention_years' ) );
	$limit = time() - $years * YEAR_IN_SECONDS;
	$q     = new WP_Query(
		array(
			'post_type'      => SAM_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $q->posts as $pid ) {
		$m = sam_get_model( $pid );
		if ( $m && sam_expiry_ts( $m ) < time() && ( $m['last_contact'] ?: $m['created'] ) < $limit ) {
			sam_delete_model( $pid );
		}
	}
}
