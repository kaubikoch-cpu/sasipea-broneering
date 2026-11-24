<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Broneeringu loomine ja muutmine. Kõik kirjutused käivad salongi ühise luku all
 * (GET_LOCK) ja transaktsioonis ning aja vabadust kontrollitakse vahetult enne
 * salvestamist uuesti. Nii ei teki topeltbroneeringut ka siis, kui kaks klienti
 * vajutavad samal hetkel "Broneeri".
 */
final class BookingService {

	private static function lock_name(): string {
		global $wpdb;
		return 'sb_booking_' . $wpdb->prefix;
	}

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T|\WP_Error
	 */
	public static function locked( callable $fn ) {
		global $wpdb;
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', self::lock_name() ) );
		if ( 1 !== $got ) {
			return new \WP_Error( 'sb_busy', __( 'Süsteem on hetkel hõivatud. Proovi mõne sekundi pärast uuesti.', 'sasipea-broneering' ), array( 'status' => 503 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$result = $fn();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( 'COMMIT' );
			}
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'sb_error', __( 'Salvestamine ebaõnnestus.', 'sasipea-broneering' ), array( 'status' => 500 ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	public static function local_date( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( Settings::tz() )->format( 'Y-m-d' );
	}

	/**
	 * Valib vaba praktikandi või kontrollib, et soovitud praktikant on vaba.
	 *
	 * @return int|\WP_Error
	 */
	public static function pick_staff( array $service, int $staff_id, int $start, int $exclude = 0, bool $admin = false, ?int $duration = null ) {
		$date = self::local_date( $start );
		if ( $staff_id ) {
			if ( ! $admin && ! in_array( $service['id'], Repo::staff_service_ids( $staff_id ), true ) ) {
				return new \WP_Error( 'sb_staff', __( 'See praktikant seda teenust ei tee.', 'sasipea-broneering' ), array( 'status' => 400 ) );
			}
			$ids = array( $staff_id );
		} else {
			$ids = null;
		}
		$input = Repo::availability_input( $service, $ids, $date, $exclude, $admin, $duration );
		$free  = Availability::free_staff( $input, $start );
		if ( ! $free ) {
			return new \WP_Error( 'sb_taken', __( 'Kahjuks see aeg ei ole enam vaba. Palun vali uus aeg.', 'sasipea-broneering' ), array( 'status' => 409 ) );
		}
		if ( $staff_id ) {
			return $staff_id;
		}
		// "Ükskõik kes": see, kellel on sel päeval kõige vähem broneeringuid.
		[ $from, $to ] = Repo::day_bounds( $date );
		$count         = array_fill_keys( $free, 0 );
		foreach ( Repo::bookings_between( $from, $to, $exclude ) as $b ) {
			if ( isset( $count[ (int) $b['staff_id'] ] ) ) {
				++$count[ (int) $b['staff_id'] ];
			}
		}
		asort( $count );
		return (int) array_key_first( $count );
	}

	/**
	 * @param array $in service_id, staff_id, start (ts), client[], comment, consents[], source
	 * @return array{booking: array, token: string}|\WP_Error
	 */
	public static function create( array $in ) {
		$service = Repo::service( (int) $in['service_id'] );
		if ( ! $service || ( $service['hidden'] && 'online' === $in['source'] ) ) {
			return new \WP_Error( 'sb_service', __( 'Teenust ei leitud.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$admin = 'online' !== $in['source'];

		$result = self::locked(
			function () use ( $in, $service, $admin ) {
				global $wpdb;
				$start = (int) $in['start'];
				$staff = self::pick_staff( $service, (int) $in['staff_id'], $start, 0, $admin );
				if ( is_wp_error( $staff ) ) {
					return $staff;
				}
				// Kestus ja hind sõltuvad praktikandi tasemest (lisaaeg, soodustus).
				$terms     = Repo::service_terms( $service, Repo::staff( (int) $staff ) );
				$client_id = Repo::upsert_client( $in['client'] );
				$token     = bin2hex( random_bytes( 32 ) );
				$now       = Repo::utc( time() );
				$ok        = $wpdb->insert(
					Install::table( 'bookings' ),
					array(
						'client_id'   => $client_id,
						'staff_id'    => $staff,
						'service_id'  => $service['id'],
						'start_utc'   => Repo::utc( $start ),
						'end_utc'     => Repo::utc( $start + $terms['duration'] * 60 ),
						'price_cents' => $terms['price_cents'],
						'status'      => 'booked',
						'source'      => $in['source'],
						'comment'     => $in['comment'] ?? '',
						'token_hash'  => hash( 'sha256', $token ),
						'created_at'  => $now,
						'updated_at'  => $now,
					)
				);
				if ( ! $ok ) {
					throw new \RuntimeException( 'insert failed' );
				}
				$id = (int) $wpdb->insert_id;
				foreach ( (array) ( $in['consents'] ?? array() ) as $type => $granted ) {
					Repo::add_consent( $client_id, $type, (bool) $granted );
				}
				Repo::log( $id, 'created', array( 'source' => $in['source'] ) );
				return array(
					'id'        => $id,
					'token'     => $token,
					'client_id' => $client_id,
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $in['consents']['models'] ) ) {
			Integrations\Modellid::opt_in( $result['client_id'], $in['client'] );
		}
		$booking = Repo::booking( $result['id'] );
		do_action( 'sb_booking_created', $booking, $result['token'] );
		return array(
			'booking' => $booking,
			'token'   => $result['token'],
		);
	}

	/**
	 * Muudab aega, praktikanti, teenust, staatust või kommentaari.
	 *
	 * @return array|\WP_Error uuendatud broneering
	 */
	public static function update( int $id, array $ch, bool $admin = true ) {
		$result = self::locked(
			function () use ( $id, $ch, $admin ) {
				global $wpdb;
				$b = Repo::booking( $id );
				if ( ! $b ) {
					return new \WP_Error( 'sb_404', __( 'Broneeringut ei leitud.', 'sasipea-broneering' ), array( 'status' => 404 ) );
				}
				$service_id = isset( $ch['service_id'] ) ? (int) $ch['service_id'] : (int) $b['service_id'];
				$service    = Repo::service( $service_id );
				if ( ! $service ) {
					return new \WP_Error( 'sb_service', __( 'Teenust ei leitud.', 'sasipea-broneering' ), array( 'status' => 400 ) );
				}
				$start  = isset( $ch['start'] ) ? (int) $ch['start'] : Repo::ts( $b['start_utc'] );
				$staff  = isset( $ch['staff_id'] ) ? (int) $ch['staff_id'] : (int) $b['staff_id'];
				$status = $ch['status'] ?? $b['status'];
				if ( ! isset( Repo::STATUSES[ $status ] ) ) {
					return new \WP_Error( 'sb_status', __( 'Vigane staatus.', 'sasipea-broneering' ), array( 'status' => 400 ) );
				}

				$moved = $start !== Repo::ts( $b['start_utc'] ) || $staff !== (int) $b['staff_id'] || $service_id !== (int) $b['service_id'];
				// Ainult aja muutmisel jääb kestus ja hind samaks, mis kliendile lubati. Teise praktikandi
				// või teenuse korral arvutatakse need uuesti selle praktikandi taseme järgi.
				$reprice  = $staff !== (int) $b['staff_id'] || $service_id !== (int) $b['service_id'];
				$terms    = Repo::service_terms( $service, Repo::staff( $staff ) );
				$duration = $reprice ? $terms['duration'] : (int) round( ( Repo::ts( $b['end_utc'] ) - Repo::ts( $b['start_utc'] ) ) / 60 );
				$reactivated = in_array( $status, Repo::ACTIVE, true ) && ! in_array( $b['status'], Repo::ACTIVE, true );
				if ( in_array( $status, Repo::ACTIVE, true ) && ( $moved || $reactivated ) ) {
					$ok = self::pick_staff( $service, $staff, $start, $id, $admin, $duration );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
				}
				$data = array(
					'service_id' => $service_id,
					'staff_id'   => $staff,
					'start_utc'  => Repo::utc( $start ),
					'end_utc'    => Repo::utc( $start + $duration * 60 ),
					'status'     => $status,
					'updated_at' => Repo::utc( time() ),
				);
				if ( $reprice ) {
					$data['price_cents'] = $terms['price_cents'];
				}
				if ( isset( $ch['comment'] ) ) {
					$data['comment'] = $ch['comment'];
				}
				if ( $moved ) {
					$data['reminder_sent_at'] = null;
				}
				$wpdb->update( Install::table( 'bookings' ), $data, array( 'id' => $id ) );
				$diff = array();
				foreach ( array( 'service_id', 'staff_id', 'start_utc', 'end_utc', 'price_cents', 'status' ) as $k ) {
					if ( isset( $data[ $k ] ) && (string) $data[ $k ] !== (string) $b[ $k ] ) {
						$diff[ $k ] = array( $b[ $k ], $data[ $k ] );
					}
				}
				Repo::log( $id, 'updated', $diff );
				return array(
					'old'   => $b,
					'moved' => $moved,
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$new = Repo::booking( $id );
		if ( str_starts_with( $new['status'], 'cancelled' ) && ! str_starts_with( $result['old']['status'], 'cancelled' ) ) {
			do_action( 'sb_booking_cancelled', $new );
		} elseif ( $result['moved'] && in_array( $new['status'], Repo::ACTIVE, true ) ) {
			do_action( 'sb_booking_moved', $new, $result['old'] );
		}
		return $new;
	}
}
