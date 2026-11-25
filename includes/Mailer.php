<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * E-kirjad. Mallid on muudetavad Seadete lehel. Kohatäited: {eesnimi}, {nimi}, {teenus},
 * {kuupaev}, {kell}, {praktikant}, {hind}, {materjal}, {halda_link}, {aadress}, {telefon},
 * {koduleht}, {kliendi_telefon}, {kliendi_email}, {kommentaar}, {staatus}.
 */
final class Mailer {

	/** Klient muudab ise oma lingi kaudu: kasuta sama tokenit, et tema avatud leht jääks tööle. */
	public static ?string $known_token = null;

	public static function init(): void {
		add_action( 'sb_booking_created', array( self::class, 'on_created' ), 10, 3 );
		add_action( 'sb_booking_moved', array( self::class, 'on_moved' ), 10, 3 );
		add_action( 'sb_mail_flush', array( self::class, 'flush_due' ), 10, 0 );
		add_action( 'sb_cron_reminders', array( self::class, 'flush_due' ), 10, 0 );
		add_action( 'sb_booking_cancelled', array( self::class, 'on_cancelled' ), 10, 2 );
	}

	public static function default_templates(): array {
		return array(
			'client_confirm_subject'  => 'Broneering kinnitatud: {teenus}, {kuupaev} kell {kell}',
			'client_confirm_body'     => "Tere, {eesnimi}!\n\nSinu aeg Sasipea Akadeemias on broneeritud.\n\n<strong>{teenus}</strong>\n{kuupaev} kell {kell}\nJuuksuriõpilane: {praktikant}\nHind: {hind}{materjal}\n\nTööd teevad juuksuriõpilased juhendaja käe all.\n\n{halda_link}\n\nKohtumiseni!",
			'client_reminder_subject' => 'Meeldetuletus: homme kell {kell} Sasipea Akadeemias',
			'client_reminder_body'    => "Tere, {eesnimi}!\n\nTuletame meelde, et sinu aeg on {kuupaev} kell {kell}.\n\n<strong>{teenus}</strong>\nJuuksuriõpilane: {praktikant}\n\nKui sa ei saa tulla, palun anna meile teada.\n\n{halda_link}",
			'client_moved_subject'    => 'Broneeringu aeg muudetud: {kuupaev} kell {kell}',
			'client_moved_body'       => "Tere, {eesnimi}!\n\nSinu broneeringu uus aeg on {kuupaev} kell {kell}.\n\n<strong>{teenus}</strong>\nJuuksuriõpilane: {praktikant}\n\n{halda_link}",
			'client_cancel_subject'   => 'Broneering tühistatud: {teenus}, {kuupaev}',
			'client_cancel_body'      => "Tere, {eesnimi}!\n\nSinu broneering ({teenus}, {kuupaev} kell {kell}) on tühistatud.\n\nUue aja saad broneerida meie kodulehel.",
			'admin_new_subject'       => 'Uus broneering: {nimi}, {kuupaev} {kell}',
			'admin_new_body'          => "{teenus}\n{kuupaev} kell {kell}\nJuuksuriõpilane: {praktikant}\n\nKlient: {nimi}\nTelefon: {kliendi_telefon}\nE-post: {kliendi_email}\nKommentaar: {kommentaar}",
			'admin_moved_subject'     => 'Broneering muudetud: {nimi}, {kuupaev} {kell}',
			'admin_moved_body'        => "Uus aeg: {kuupaev} kell {kell}\n{teenus}\nJuuksuriõpilane: {praktikant}\n\nKlient: {nimi}, {kliendi_telefon}",
			'admin_cancel_subject'    => 'Broneering tühistatud: {nimi}, {kuupaev} {kell}',
			'admin_cancel_body'       => "{staatus}\n{teenus}\n{kuupaev} kell {kell}\nJuuksuriõpilane: {praktikant}\n\nKlient: {nimi}, {kliendi_telefon}",
		);
	}

	public static function vars( array $b, ?string $token ): array {
		$set   = Settings::get();
		$start = Format::local( $b['start_utc'] );
		$link  = '';
		if ( $token ) {
			$link = sprintf( '<a href="%s">%s</a>', esc_url( Settings::booking_url( array( 'halda' => $token ) ) ), esc_html__( 'Tühista või muuda aega', 'sasipea-broneering' ) );
		}
		return array(
			'{eesnimi}'         => esc_html( $b['first_name'] ),
			'{nimi}'            => esc_html( trim( $b['first_name'] . ' ' . $b['last_name'] ) ),
			'{teenus}'          => esc_html( $b['service_name'] ),
			'{kuupaev}'         => esc_html( Format::date_long( $start ) ),
			'{kell}'            => $start->format( 'H:i' ),
			'{praktikant}'      => esc_html( $b['staff_name'] ),
			'{hind}'            => esc_html( Settings::money( (int) $b['price_cents'] ) ),
			'{materjal}'        => $b['material_extra'] ? esc_html__( ' + materjal', 'sasipea-broneering' ) : '',
			'{halda_link}'      => $link,
			'{aadress}'         => esc_html( $set['salon_address'] ),
			'{telefon}'         => esc_html( $set['salon_phone'] ),
			'{koduleht}'        => esc_html( $set['salon_web'] ),
			'{kliendi_telefon}' => esc_html( $b['phone'] ),
			'{kliendi_email}'   => esc_html( $b['email'] ),
			'{kommentaar}'      => esc_html( (string) $b['comment'] ),
			'{staatus}'         => esc_html( Repo::STATUSES[ $b['status'] ] ?? $b['status'] ),
		);
	}

	private static function render( string $tpl, array $vars, bool $html_footer ): string {
		$body = nl2br( strtr( wp_kses_post( $tpl ), $vars ) );
		if ( $html_footer ) {
			$set   = Settings::get();
			$web   = esc_html( $set['salon_web'] );
			$body .= '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px">'
				. '<p style="color:#555;font-size:13px;margin:0">Sasipea Akadeemia · ' . esc_html( $set['salon_address'] )
				. ' · tel ' . esc_html( $set['salon_phone'] ) . ' · <a href="https://' . $web . '">' . $web . '</a></p>';
		}
		return '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#222">' . $body . '</div>';
	}

	public static function send( string $to, string $key, array $b, ?string $token = null, bool $ics = false, bool $footer = true ): bool {
		$set   = Settings::get();
		$tpl   = $set['templates'];
		$vars  = self::vars( $b, $token );
		$subj  = wp_strip_all_tags( html_entity_decode( strtr( $tpl[ $key . '_subject' ], $vars ), ENT_QUOTES ) );
		$body  = self::render( $tpl[ $key . '_body' ], $vars, $footer );
		$files = array();
		if ( $ics ) {
			$path = trailingslashit( get_temp_dir() ) . 'sasipea-broneering-' . (int) $b['id'] . '.ics';
			file_put_contents( $path, Ics::booking( $b ) ); // phpcs:ignore
			$files[] = $path;
		}
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $set['from_name'], $set['from_email'] ),
		);
		if ( str_starts_with( $key, 'admin_' ) && is_email( $b['email'] ) ) {
			$headers[] = 'Reply-To: ' . $b['email'];
		}
		$ok = wp_mail( $to, $subj, $body, $headers, $files );
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		return (bool) $ok;
	}

	private static function notify( string $key, array $b ): void {
		$to = Settings::get()['notify_email'];
		if ( is_email( $to ) ) {
			self::send( $to, $key, $b, null, false, false );
		}
	}

	/** Uue tokeni loomine, et kliendile saadetavas kirjas oleks töötav link. */
	public static function fresh_token( int $booking_id ): string {
		global $wpdb;
		if ( self::$known_token ) {
			return self::$known_token;
		}
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( Install::table( 'bookings' ), array( 'token_hash' => hash( 'sha256', $token ) ), array( 'id' => $booking_id ) );
		return $token;
	}

	/**
	 * Kellele kiri läheb. Kliendi enda tegevus (online): kiri kliendile ja salongile. Salongi tegevus:
	 * salongile kirja ei saadeta; kliendile ainult siis, kui töötaja jättis teavituse sisse, aeg on
	 * tulevikus ja muutmisel muutus päriselt algusaeg (teise õpilase või teenuse pärast kirja ei tule).
	 *
	 * @param string $event created|moved|cancelled
	 * @return array{client:bool,admin:bool}
	 */
	public static function plan( string $event, bool $by_client, bool $notify, bool $future, bool $time_changed = true ): array {
		if ( $by_client ) {
			return array( 'client' => true, 'admin' => true );
		}
		$client = $notify && $future && ( 'moved' !== $event || $time_changed );
		return array( 'client' => $client, 'admin' => false );
	}

	private static function plan_for( string $event, array $b, array $ctx, bool $time_changed = true ): array {
		return self::plan(
			$event,
			! empty( $ctx['by_client'] ),
			! isset( $ctx['notify'] ) || $ctx['notify'],
			Repo::ts( $b['start_utc'] ) > time(),
			$time_changed
		);
	}

	public const PENDING = 'sb_pending_moves';
	public const DELAY   = 600;

	/**
	 * Lisab ootele aja muutmise teate. Esimese muudatuse algne aeg jääb meelde, iga uus muudatus
	 * lükkab saatmist edasi.
	 *
	 * @param array<int,array{from:string,due:int}> $pending
	 */
	public static function queue( array $pending, int $id, string $from, int $now ): array {
		$pending[ $id ] = array(
			'from' => $pending[ $id ]['from'] ?? $from,
			'due'  => $now + self::DELAY,
		);
		return $pending;
	}

	/** Kas ootel teade tuleb saata: aeg peab erinema algsest, olema tulevikus ja broneering kehtiv. */
	public static function move_still_matters( string $from, string $start_utc, bool $active, bool $future ): bool {
		return $active && $future && $from !== $start_utc;
	}

	private static function forget( int $id ): void {
		$pending = (array) get_option( self::PENDING, array() );
		if ( isset( $pending[ $id ] ) ) {
			unset( $pending[ $id ] );
			update_option( self::PENDING, $pending, false );
		}
	}

	/** Saadab ootel teated, mille aeg on käes. Käivitub ühekordse cron-sündmusega ja varuks iga 15 minuti järel. */
	public static function flush_due(): int {
		$pending = (array) get_option( self::PENDING, array() );
		$now     = time();
		$sent    = 0;
		foreach ( $pending as $id => $p ) {
			if ( (int) $p['due'] > $now ) {
				continue;
			}
			self::forget( (int) $id );
			$b = Repo::booking( (int) $id );
			if ( $b && is_email( $b['email'] ) && self::move_still_matters( (string) $p['from'], $b['start_utc'], in_array( $b['status'], Repo::ACTIVE, true ), Repo::ts( $b['start_utc'] ) > $now ) ) {
				self::send( $b['email'], 'client_moved', $b, self::fresh_token( (int) $b['id'] ), true );
				++$sent;
			}
		}
		return $sent;
	}

	public static function on_created( array $b, string $token, array $ctx = array() ): void {
		$plan = self::plan_for( 'created', $b, $ctx );
		if ( $plan['client'] && is_email( $b['email'] ) ) {
			self::send( $b['email'], 'client_confirm', $b, $token, true );
		}
		if ( $plan['admin'] ) {
			self::notify( 'admin_new', $b );
		}
	}

	public static function on_moved( array $b, array $old, array $ctx = array() ): void {
		$plan = self::plan_for( 'moved', $b, $ctx, $b['start_utc'] !== $old['start_utc'] );
		if ( empty( $ctx['by_client'] ) ) {
			// Salongi muudatus ootab 10 minutit: kui töötaja eksib ja parandab, saab klient ühe kirja lõpliku ajaga.
			if ( $plan['client'] && is_email( $b['email'] ) ) {
				update_option( self::PENDING, self::queue( (array) get_option( self::PENDING, array() ), (int) $b['id'], $old['start_utc'], time() ), false );
				wp_clear_scheduled_hook( 'sb_mail_flush', array( (int) $b['id'] ) );
				wp_schedule_single_event( time() + self::DELAY, 'sb_mail_flush', array( (int) $b['id'] ) );
			}
		} elseif ( is_email( $b['email'] ) ) {
			self::forget( (int) $b['id'] );
			self::send( $b['email'], 'client_moved', $b, self::fresh_token( (int) $b['id'] ), true );
		}
		if ( $plan['admin'] ) {
			self::notify( 'admin_moved', $b );
		}
	}

	public static function on_cancelled( array $b, array $ctx = array() ): void {
		self::forget( (int) $b['id'] );
		$plan = self::plan_for( 'cancelled', $b, $ctx );
		if ( $plan['client'] && is_email( $b['email'] ) ) {
			self::send( $b['email'], 'client_cancel', $b, null, true );
		}
		if ( $plan['admin'] ) {
			self::notify( 'admin_cancel', $b );
		}
	}

	public static function reminder( array $b ): bool {
		if ( ! is_email( $b['email'] ) ) {
			return false;
		}
		return self::send( $b['email'], 'client_reminder', $b, self::fresh_token( (int) $b['id'] ) );
	}
}
