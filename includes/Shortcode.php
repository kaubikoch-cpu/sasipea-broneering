<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * [sasipea_broneering] – avalik broneerimisvorm (ja ?halda=TOKEN korral broneeringu haldus).
 * [sasipea_praktikant] – sisseloginud praktikandi enda graafik.
 */
final class Shortcode {

	public static function init(): void {
		add_shortcode( 'sasipea_broneering', array( self::class, 'render' ) );
		add_shortcode( 'sasipea_praktikant', array( self::class, 'render_staff' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ) );
	}

	public static function register(): void {
		wp_register_style( 'sb-booking', SB_URL . 'assets/css/booking.css', array(), SB_VERSION );
		wp_register_script( 'sb-booking', SB_URL . 'assets/js/booking.js', array(), SB_VERSION, true );
		wp_register_style( 'sb-planner', SB_URL . 'assets/css/planner.css', array(), SB_VERSION );
		wp_register_script( 'sb-planner', SB_URL . 'assets/js/planner.js', array(), SB_VERSION, true );
	}

	public static function render(): string {
		wp_enqueue_style( 'sb-booking' );
		wp_enqueue_script( 'sb-booking' );
		$set    = Settings::get();
		$token  = isset( $_GET['halda'] ) ? sanitize_key( wp_unslash( $_GET['halda'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- token on ise volitus.
		$config = array(
			'rest'        => esc_url_raw( rest_url( Rest\PublicController::NS . '/' ) ),
			'manageToken' => preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '',
			'privacyUrl'  => str_starts_with( $set['privacy_url'], 'http' ) ? $set['privacy_url'] : home_url( $set['privacy_url'] ),
			'consents'    => $set['consent_texts'],
			'phone'       => $set['salon_phone'],
			'bookUrl'     => Settings::booking_url(),
			'email'       => is_email( (string) $set['from_email'] ) ? (string) $set['from_email'] : '',
			// Kuud, mille töögraafik on koostatud. null = piirangut pole (kehtib nädalapõhi).
			'months'      => empty( $set['require_month'] ) ? null : self::open_months(),
		);
		return '<div class="sb-app" data-sb-config="' . esc_attr( wp_json_encode( $config ) ) . '">'
			. '<noscript>' . esc_html__( 'Broneerimiseks luba brauseris JavaScript või helista meile.', 'sasipea-broneering' ) . '</noscript>'
			. '<p class="sb-loading">' . esc_html__( 'Laen…', 'sasipea-broneering' ) . '</p></div>';
	}

	/** Koostatud töögraafikuga kuud alates jooksvast kuust, kasvavas järjekorras. */
	public static function open_months(): array {
		$now = wp_date( 'Y-m', null, Settings::tz() );
		$out = array_values( array_filter( Repo::months(), static fn( $m ) => preg_match( '/^\d{4}-\d{2}$/', $m ) && $m >= $now ) );
		sort( $out );
		return $out;
	}

	public static function render_staff(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Graafiku nägemiseks %s.', 'sasipea-broneering' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'logi sisse', 'sasipea-broneering' ) . '</a>'
			) . '</p>';
		}
		if ( ! current_user_can( 'sb_view_own' ) && ! current_user_can( 'sb_manage_bookings' ) ) {
			return '<p>' . esc_html__( 'Sul pole õigust seda lehte vaadata.', 'sasipea-broneering' ) . '</p>';
		}
		Admin::enqueue_planner( false );
		return '<div id="sb-planner" class="sb-planner-front"></div>';
	}
}
