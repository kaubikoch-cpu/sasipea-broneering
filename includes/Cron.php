<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Meeldetuletused (iga 15 min) ja GDPR anonüümimine (kord ööpäevas).
 * Jagatud majutusel soovitame päris cron'i, vt README.
 */
final class Cron {

	public static function init(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) );
		add_action( 'sb_cron_reminders', array( self::class, 'reminders' ) );
		add_action( 'sb_cron_daily', array( self::class, 'anonymize' ) );
		add_action( 'sb_cron_daily', array( self::class, 'shop_sync' ) );
		if ( ! wp_next_scheduled( 'sb_cron_reminders' ) ) {
			self::schedule();
		}
	}

	public static function intervals( array $s ): array {
		$s['sb_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Iga 15 minuti järel' );
		return $s;
	}

	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) );
		if ( ! wp_next_scheduled( 'sb_cron_reminders' ) ) {
			wp_schedule_event( time() + 60, 'sb_15min', 'sb_cron_reminders' );
		}
		if ( ! wp_next_scheduled( 'sb_cron_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00 Europe/Tallinn' ), 'daily', 'sb_cron_daily' );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( 'sb_cron_reminders' );
		wp_clear_scheduled_hook( 'sb_cron_daily' );
	}

	/** Uuendab kord ööpäevas veebipoe tootenimekirja koopiat (triipkoodi otsingu jaoks). */
	public static function shop_sync(): void {
		if ( Integrations\Woo::enabled() ) {
			Integrations\Woo::sync();
		}
	}

	/** Saadab meeldetuletuse broneeringutele, mis algavad järgmise 24 tunni jooksul. */
	public static function reminders(): int {
		global $wpdb;
		$now  = time();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Install::table( 'bookings' ) . "
				 WHERE status = 'booked' AND reminder_sent_at IS NULL
				   AND start_utc > %s AND start_utc <= %s
				   AND created_at <= DATE_SUB(start_utc, INTERVAL 24 HOUR)",
				Repo::utc( $now ),
				Repo::utc( $now + DAY_IN_SECONDS )
			)
		);
		$sent = 0;
		foreach ( $rows as $id ) {
			$b = Repo::booking( (int) $id );
			// Märgime enne saatmist, et topeltsaatmist ei tekiks, kui cron käivitub kaks korda.
			$wpdb->update( Install::table( 'bookings' ), array( 'reminder_sent_at' => Repo::utc( $now ) ), array( 'id' => (int) $id ) );
			if ( $b && Mailer::reminder( $b ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/** Anonüümib kliendid, kellega pole 2 aastat kontakti olnud. Broneeringud (statistika) jäävad alles. */
	public static function anonymize(): int {
		global $wpdb;
		$years  = (int) apply_filters( 'sb_retention_years', 2 );
		$cutoff = Repo::utc( strtotime( "-{$years} years" ) );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT c.id FROM ' . Install::table( 'clients' ) . ' c
				 WHERE c.anonymized_at IS NULL AND COALESCE(c.last_contact_at, c.created_at) < %s
				   AND NOT EXISTS (SELECT 1 FROM ' . Install::table( 'bookings' ) . ' b WHERE b.client_id = c.id AND b.end_utc > %s)',
				$cutoff,
				$cutoff
			)
		);
		foreach ( $ids as $id ) {
			self::anonymize_client( (int) $id );
		}
		return count( $ids );
	}

	public static function anonymize_client( int $id ): void {
		global $wpdb;
		$wpdb->update(
			Install::table( 'clients' ),
			array(
				'first_name'    => 'Anonüümne',
				'last_name'     => '',
				'phone'         => '',
				'email'         => '',
				'notes'         => null,
				'model_id'      => null,
				'anonymized_at' => Repo::utc( time() ),
			),
			array( 'id' => $id )
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Install::table( 'bookings' ) . ' SET comment = NULL, token_hash = NULL WHERE client_id = %d', $id ) );
	}
}
