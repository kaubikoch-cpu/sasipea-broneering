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

	/** Vana plugina fail kujul "kaust/fail.php" või tühi, kui seda ei õnnestu kindlaks teha. */
	public static function legacy_plugin(): string {
		if ( ! self::$legacy ) {
			return '';
		}
		$file = defined( 'SAM_FILE' ) ? (string) SAM_FILE : ( defined( 'SAM_DIR' ) ? SAM_DIR . 'sasipea-modellid.php' : '' );
		if ( '' === $file ) {
			return '';
		}
		$base = plugin_basename( $file );
		// Kindluse mõttes: lülitame välja ainult vana modellide plugina, mitte kunagi iseennast.
		return ( $base !== plugin_basename( SB_FILE ) && false !== strpos( $base, 'modellid' ) ) ? $base : '';
	}

	/** Töölaua nupp "Lülita vana plugin välja". Käivitub ainult vormi saatmisel õige kasutaja poolt. */
	public static function maybe_switch(): void {
		if ( ! self::$legacy || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['sb_sam_switch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) || ! isset( $_POST['_sbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_sbnonce'] ) ), 'sb_sam_switch' ) ) {
			wp_die( esc_html__( 'Ligipääs puudub.', 'sasipea-broneering' ), 403 );
		}
		$plugin = self::legacy_plugin();
		if ( '' === $plugin ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $plugin );
		wp_safe_redirect( Dashboard::url( 'modellid' ) );
		exit;
	}

	/** Vaade "Modellid" seni, kuni vana plugin on veel sisse lülitatud: selgitus ja nupp. */
	public static function view_legacy(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Modellid', 'sasipea-broneering' ) . '</h1>';
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Modellide andmebaas on nüüd broneerimissüsteemi osa, kuid vana plugin "Sasipea Modellid" on veel sisse lülitatud. Seni kuni see töötab, hoiame uue osa ootel, et kaks koopiat omavahel tülli ei läheks.', 'sasipea-broneering' ) . '</p></div>';
		$c = self::counts();
		if ( $c['total'] ) {
			/* translators: 1: number of models, 2: number of photos */
			echo '<p>' . esc_html( sprintf( __( 'Andmebaasis on praegu %1$d modelli ja %2$d pilti. Need jäävad kõik alles: uus osa loeb samu andmeid.', 'sasipea-broneering' ), $c['total'], $c['photos'] ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Registreeritud modellid ja pildid jäävad kõik alles: uus osa loeb samu andmeid.', 'sasipea-broneering' ) . '</p>';
		}
		if ( current_user_can( 'activate_plugins' ) && '' !== self::legacy_plugin() ) {
			echo '<form method="post" action="' . esc_url( Dashboard::url( 'modellid' ) ) . '">';
			wp_nonce_field( 'sb_sam_switch', '_sbnonce' );
			echo '<p><button type="submit" name="sb_sam_switch" value="1" class="button button-primary">' . esc_html__( 'Lülita vana plugin välja ja ava modellid', 'sasipea-broneering' ) . '</button></p></form>';
			echo '<p class="description">' . esc_html__( 'Vana pluginat ei kustutata, see lülitatakse ainult välja. Tagasi saab menüüst Pluginad → Sasipea Modellid → Aktiveeri.', 'sasipea-broneering' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Palu lehe haldajal vana plugin välja lülitada: Pluginad → Sasipea Modellid → Lülita välja. Seejärel ilmuvad modellid siia.', 'sasipea-broneering' ) . '</p>';
		}
		echo '</div>';
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
