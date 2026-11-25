<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Lülitab sisse broneerimissüsteemi enda modellide andmebaasi (kaust modellid/).
 *
 * Kuni vana eraldi plugin "Sasipea Modellid" on sisse lülitatud, jääb see osa ootele, et kaks koopiat
 * samast koodist ei läheks omavahel tülli. Andmed on mõlemal juhul samad ja jäävad andmebaasi alles.
 */
final class ModellidModule {

	/** Vana eraldi plugin on veel sisse lülitatud. */
	public static bool $legacy = false;

	public static function init(): void {
		if ( defined( 'SAM_VERSION' ) ) {
			self::$legacy = true;
			add_action( 'admin_notices', array( self::class, 'legacy_notice' ) );
			return;
		}
		// Kui vana pluginat parajasti uuesti sisse lülitatakse, ei laadi me oma koopiat, muidu oleksid samad funktsioonid kaks korda.
		$plugin = isset( $_REQUEST['plugin'] ) ? (string) wp_unslash( $_REQUEST['plugin'] ) : ''; // phpcs:ignore WordPress.Security
		$action = isset( $_REQUEST['action'] ) ? (string) wp_unslash( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security
		if ( is_admin() && 'activate' === $action && false !== strpos( $plugin, 'sasipea-modellid' ) ) {
			return;
		}
		require_once SB_DIR . 'modellid/boot.php';
	}

	/** Modellide vaated on töölaual olemas. */
	public static function embedded(): bool {
		return defined( 'SAM_EMBEDDED' );
	}

	public static function legacy_text(): string {
		return __( 'Modellide andmebaas on nüüd broneerimissüsteemi osa. Lülita vana plugin "Sasipea Modellid" välja (Pluginad → Sasipea Modellid → Lülita välja), siis ilmuvad modellid salongi töölauale. Registreeritud modellid ja pildid jäävad alles.', 'sasipea-broneering' );
	}

	public static function legacy_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( self::legacy_text() ) . '</p></div>';
		}
	}

	/** Modellide arv oleku kaupa (ülemineku kontrolliks ja avalehe jaoks). */
	public static function counts(): array {
		$out = array( 'total' => 0, 'photos' => 0 );
		if ( ! post_type_exists( 'sam_modell' ) ) {
			return $out;
		}
		$ids          = get_posts( array( 'post_type' => 'sam_modell', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		$out['total'] = count( $ids );
		foreach ( $ids as $id ) {
			$p              = get_post_meta( $id, '_sam_photos', true );
			$out['photos'] += is_array( $p ) ? count( $p ) : 0;
		}
		return $out;
	}
}
