<?php
/**
 * Rämpsukaitse avalikule vormile: meepott, ajatõend, Cloudflare Turnstile, sagedusepiirang.
 * Turnstile'i võtmed loetakse Fluent Formsi seadetest (Global Settings → Security → Turnstile).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Tagastab ['site' => ..., 'secret' => ...] või null. */
function sam_turnstile_keys() {
	$keys = apply_filters( 'sam_turnstile_keys', null );
	if ( is_array( $keys ) && ! empty( $keys['site'] ) && ! empty( $keys['secret'] ) ) {
		return $keys;
	}
	$opt = get_option( '_fluentform_turnstile_details' );
	if ( is_string( $opt ) ) {
		$opt = maybe_unserialize( $opt );
	}
	if ( is_array( $opt ) ) {
		$site   = $opt['siteKey'] ?? ( $opt['site_key'] ?? '' );
		$secret = $opt['secretKey'] ?? ( $opt['secret_key'] ?? '' );
		if ( $site && $secret ) {
			return array(
				'site'   => $site,
				'secret' => $secret,
			);
		}
	}
	return null;
}

function sam_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			$ip = $cf;
		}
	}
	return $ip;
}

/** Peidetud väljad vormi sisse. */
function sam_security_fields() {
	$t   = time();
	$sig = hash_hmac( 'sha256', 'sam|' . $t, wp_salt( 'nonce' ) );
	$out  = '<div class="sam-hp" aria-hidden="true"><label>Veebileht <input type="text" name="sam_website" value="" tabindex="-1" autocomplete="off"></label></div>';
	$out .= '<input type="hidden" name="sam_ts" value="' . esc_attr( $t . '.' . $sig ) . '">';
	$keys = sam_turnstile_keys();
	if ( $keys ) {
		wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore
		$out .= '<div class="cf-turnstile sam-turnstile" data-sitekey="' . esc_attr( $keys['site'] ) . '" data-theme="auto" data-language="et"></div>';
	}
	return $out;
}

/** Kontrollib postitust. Tagastab true või veateate. */
function sam_security_check() {
	// Meepott.
	if ( ! empty( $_POST['sam_website'] ) ) { // phpcs:ignore
		return 'spam';
	}
	// Ajatõend: vähemalt 4 s ja kuni 1 päev.
	$ts = isset( $_POST['sam_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['sam_ts'] ) ) : ''; // phpcs:ignore
	$parts = explode( '.', $ts, 2 );
	if ( 2 !== count( $parts ) ) {
		return 'Vormi kehtivus aegus. Palun proovi uuesti.';
	}
	$t   = (int) $parts[0];
	$exp = hash_hmac( 'sha256', 'sam|' . $t, wp_salt( 'nonce' ) );
	if ( ! hash_equals( $exp, $parts[1] ) ) {
		return 'Vormi kehtivus aegus. Palun proovi uuesti.';
	}
	$age = time() - $t;
	if ( $age < 4 ) {
		return 'spam';
	}
	if ( $age > DAY_IN_SECONDS ) {
		return 'Vormi kehtivus aegus. Palun proovi uuesti.';
	}

	// Sagedusepiirang: max 5 saatmist tunnis ühelt IP-lt.
	$ip  = sam_client_ip();
	$key = 'sam_rl_' . md5( $ip );
	$cnt = (int) get_transient( $key );
	if ( $cnt >= 5 ) {
		return 'Liiga palju katseid. Palun proovi hiljem uuesti.';
	}
	set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );

	// Turnstile.
	$keys = sam_turnstile_keys();
	if ( $keys ) {
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : ''; // phpcs:ignore
		if ( '' === $token ) {
			return 'Palun kinnita, et oled inimene.';
		}
		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $keys['secret'],
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'Kontroll ebaõnnestus. Palun proovi uuesti.';
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['success'] ) ) {
			return 'Inimkontroll ei õnnestunud. Palun proovi uuesti.';
		}
	}
	return true;
}
