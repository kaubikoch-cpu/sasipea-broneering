<?php
namespace SB\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Sidumine pluginaga sasipea-modellid.
 *
 * AJUTINE: modellide plugina tabelite struktuuri ma veel ei tea (koodi pole näinud).
 * Seni käib sidumine filtrite kaudu ja ilma nendeta tagastatakse null.
 * Kui plugina kood on käes, lisan siia otse päringu modellide tabelisse.
 */
final class Modellid {

	public static function active(): bool {
		return defined( 'SASIPEA_MODELLID_VERSION' ) || class_exists( 'Sasipea_Modellid' ) || has_filter( 'sb_find_model_id' );
	}

	/** Modelli ID e-posti või telefoni järgi või null. */
	public static function find_model_id( string $email, string $phone ): ?int {
		$id = apply_filters( 'sb_find_model_id', null, $email, $phone );
		return $id ? (int) $id : null;
	}

	/** Klient soovis, et ta lisataks modellide andmebaasi. */
	public static function opt_in( int $client_id, array $client ): void {
		do_action( 'sb_model_db_optin', $client_id, $client );
	}

	/** Link modelli kaardile adminis, kui see on teada. */
	public static function model_url( ?int $model_id ): ?string {
		if ( ! $model_id ) {
			return null;
		}
		$url = apply_filters( 'sb_model_admin_url', null, $model_id );
		return $url ? (string) $url : null;
	}
}
