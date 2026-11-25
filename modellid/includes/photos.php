<?php
/**
 * Modellide pildid. Hoitakse privaatses kaustas (uploads/sam-private/<id>/),
 * mitte meediateegis. Näidatakse ainult sisselogitud juhendajatele.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SAM_PHOTO_MAX_BYTES = 12582912; // 12 MB
const SAM_PHOTO_MAX_COUNT = 12;

function sam_private_base() {
	$u = wp_upload_dir( null, false );
	return trailingslashit( $u['basedir'] ) . 'sam-private';
}

/** Loob kaitstud kausta (.htaccess + index.php). */
function sam_ensure_private_dir( $sub = '' ) {
	$base = sam_private_base();
	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}
	if ( ! file_exists( $base . '/.htaccess' ) ) {
		file_put_contents(
			$base . '/.htaccess',
			"# Sasipea Modellid: otsene ligipääs keelatud\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n"
		);
	}
	if ( ! file_exists( $base . '/index.php' ) ) {
		file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	$dir = $sub ? $base . '/' . $sub : $base;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

function sam_get_photos( $model_id ) {
	$p = get_post_meta( $model_id, '_sam_photos', true );
	return is_array( $p ) ? $p : array();
}

/**
 * Normaliseerib $_FILES['x'] (üks või mitu faili) massiiviks.
 */
function sam_files_array( $field ) {
	if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['name'] ) ) {
		return array();
	}
	$f   = $_FILES[ $field ]; // phpcs:ignore
	$out = array();
	if ( is_array( $f['name'] ) ) {
		foreach ( $f['name'] as $i => $name ) {
			if ( '' === $name || UPLOAD_ERR_NO_FILE === (int) $f['error'][ $i ] ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'type'     => $f['type'][ $i ],
				'tmp_name' => $f['tmp_name'][ $i ],
				'error'    => (int) $f['error'][ $i ],
				'size'     => (int) $f['size'][ $i ],
			);
		}
	} elseif ( '' !== $f['name'] && UPLOAD_ERR_NO_FILE !== (int) $f['error'] ) {
		$out[] = array(
			'name'     => $f['name'],
			'type'     => $f['type'],
			'tmp_name' => $f['tmp_name'],
			'error'    => (int) $f['error'],
			'size'     => (int) $f['size'],
		);
	}
	return $out;
}

/** Kontrollib faili enne töötlemist. Tagastab true või veateate. */
function sam_validate_photo( $file ) {
	if ( UPLOAD_ERR_OK !== $file['error'] ) {
		if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return 'Pilt on liiga suur (max 12 MB).';
		}
		return 'Pildi üleslaadimine ebaõnnestus.';
	}
	if ( $file['size'] > SAM_PHOTO_MAX_BYTES ) {
		return 'Pilt on liiga suur (max 12 MB).';
	}
	if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
		return 'Pildi üleslaadimine ebaõnnestus.';
	}
	$check = wp_check_filetype_and_ext(
		$file['tmp_name'],
		$file['name'],
		array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		)
	);
	if ( empty( $check['type'] ) ) {
		return 'Lubatud on ainult JPG, PNG või WEBP pildid.';
	}
	$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore
	if ( ! $info ) {
		return 'Fail ei ole korrektne pilt.';
	}
	return true;
}

/**
 * Töötleb pildi: pöörab õigesti, vähendab, eemaldab metaandmed, salvestab privaatselt.
 * $source = 'modell' | 'salong'. Tagastab pildi kirje või WP_Error.
 */
function sam_store_photo( $model_id, $file, $source ) {
	$valid = sam_validate_photo( $file );
	if ( true !== $valid ) {
		return new WP_Error( 'sam_photo', $valid );
	}
	$photos = sam_get_photos( $model_id );
	if ( count( $photos ) >= SAM_PHOTO_MAX_COUNT ) {
		return new WP_Error( 'sam_photo', 'Modellil on juba maksimaalne arv pilte (' . SAM_PHOTO_MAX_COUNT . ').' );
	}

	$dir = sam_ensure_private_dir( (string) (int) $model_id );
	$key = strtolower( wp_generate_password( 24, false, false ) );

	$editor = wp_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $editor ) ) {
		return new WP_Error( 'sam_photo', 'Pilti ei õnnestunud töödelda.' );
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$editor->maybe_exif_rotate();
	}
	$editor->set_quality( 82 );
	$editor->resize( 1600, 1600, false );
	$full = $editor->save( $dir . '/' . $key . '.jpg', 'image/jpeg' );
	if ( is_wp_error( $full ) ) {
		return new WP_Error( 'sam_photo', 'Pildi salvestamine ebaõnnestus.' );
	}

	$thumb_editor = wp_get_image_editor( $full['path'] );
	if ( ! is_wp_error( $thumb_editor ) ) {
		$thumb_editor->set_quality( 78 );
		$thumb_editor->resize( 480, 480, true );
		$thumb_editor->save( $dir . '/' . $key . '-t.jpg', 'image/jpeg' );
	}

	$entry           = array(
		'key'    => $key,
		'source' => 'salong' === $source ? 'salong' : 'modell',
		'added'  => time(),
		'by'     => get_current_user_id(),
	);
	$photos[ $key ] = $entry;
	update_post_meta( $model_id, '_sam_photos', $photos );
	return $entry;
}

function sam_photo_path( $model_id, $key, $thumb = false ) {
	$key = preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );
	return sam_private_base() . '/' . (int) $model_id . '/' . $key . ( $thumb ? '-t' : '' ) . '.jpg';
}

function sam_delete_photo( $model_id, $key ) {
	$photos = sam_get_photos( $model_id );
	if ( ! isset( $photos[ $key ] ) ) {
		return false;
	}
	foreach ( array( false, true ) as $thumb ) {
		$p = sam_photo_path( $model_id, $key, $thumb );
		if ( file_exists( $p ) ) {
			wp_delete_file( $p );
		}
	}
	unset( $photos[ $key ] );
	update_post_meta( $model_id, '_sam_photos', $photos );
	return true;
}

function sam_delete_all_photos( $model_id ) {
	$dir = sam_private_base() . '/' . (int) $model_id;
	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
	delete_post_meta( $model_id, '_sam_photos' );
}

/** Pildi URL (serveeritakse PHP kaudu, õigusi kontrollides). */
function sam_photo_url( $model_id, $key, $thumb = false ) {
	return add_query_arg(
		array(
			'sam_photo' => (int) $model_id . '-' . $key,
			't'         => $thumb ? 1 : 0,
		),
		home_url( '/' )
	);
}

add_action( 'init', 'sam_serve_photo', 20 );
function sam_serve_photo() {
	if ( empty( $_GET['sam_photo'] ) ) { // phpcs:ignore
		return;
	}
	if ( ! is_user_logged_in() || ! current_user_can( SAM_CAP ) ) {
		status_header( 403 );
		exit;
	}
	$raw = sanitize_text_field( wp_unslash( $_GET['sam_photo'] ) ); // phpcs:ignore
	if ( ! preg_match( '/^(\d+)-([a-z0-9]+)$/', $raw, $m ) ) {
		status_header( 404 );
		exit;
	}
	$model_id = (int) $m[1];
	$key      = $m[2];
	$photos   = sam_get_photos( $model_id );
	if ( SAM_CPT !== get_post_type( $model_id ) || ! isset( $photos[ $key ] ) ) {
		status_header( 404 );
		exit;
	}
	$thumb = ! empty( $_GET['t'] ); // phpcs:ignore
	$path  = sam_photo_path( $model_id, $key, $thumb );
	if ( ! file_exists( $path ) && $thumb ) {
		$path = sam_photo_path( $model_id, $key, false );
	}
	if ( ! file_exists( $path ) ) {
		status_header( 404 );
		exit;
	}
	nocache_headers();
	header( 'Content-Type: image/jpeg' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Robots-Tag: noindex' );
	if ( ! empty( $_GET['dl'] ) ) { // phpcs:ignore
		header( 'Content-Disposition: attachment; filename="modell-' . $model_id . '-' . substr( $key, 0, 6 ) . '.jpg"' );
	}
	readfile( $path ); // phpcs:ignore
	exit;
}
