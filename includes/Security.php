<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

final class Security {

	/** IP räsi (IP-d ennast ei salvestata). */
	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Lihtne päringupiirang transientidega.
	 *
	 * @return true|\WP_Error
	 */
	public static function rate_limit( string $bucket, int $max, int $window ) {
		$key   = 'sb_rl_' . $bucket . '_' . self::ip_hash();
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new \WP_Error( 'sb_rate', __( 'Liiga palju päringuid. Proovi mõne minuti pärast uuesti.', 'sasipea-broneering' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/** REST nonce kontroll avalikele POST päringutele. */
	public static function check_nonce( \WP_REST_Request $req ): bool {
		$nonce = $req->get_header( 'X-WP-Nonce' );
		return $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public static function clean_phone( string $p ): string {
		$p = preg_replace( '/[^\d+]/', '', $p );
		if ( $p !== '' && $p[0] !== '+' ) {
			$p = '+372' . ltrim( $p, '0' );
		}
		return $p;
	}
}
