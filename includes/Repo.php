<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Andmebaasi päringud. Kõik päringud kasutavad $wpdb->prepare'i.
 */
final class Repo {

	public const ACTIVE = array( 'booked', 'arrived', 'no_show' );

	public const STATUSES = array(
		'booked'           => 'Broneeritud',
		'arrived'          => 'Kohal',
		'no_show'          => 'Ei tulnud',
		'cancelled_client' => 'Tühistatud (klient)',
		'cancelled_salon'  => 'Tühistatud (salong)',
	);

	private static function t( string $n ): string {
		return Install::table( $n );
	}

	public static function utc( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	public static function ts( string $utc ): int {
		return (int) strtotime( $utc . ' UTC' );
	}

	/* ---------- Teenused ---------- */

	public static function catalog( bool $with_hidden = false ): array {
		global $wpdb;
		$where_c = $with_hidden ? '' : 'WHERE hidden = 0';
		$where_s = $with_hidden ? '' : 'WHERE hidden = 0';
		$cats    = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'categories' ) . " $where_c ORDER BY sort_order, id", ARRAY_A );
		$svcs    = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'services' ) . " $where_s ORDER BY sort_order, id", ARRAY_A );
		$out     = array();
		foreach ( $cats as $c ) {
			$out[ $c['id'] ] = array(
				'id'         => (int) $c['id'],
				'name'       => $c['name'],
				'sort_order' => (int) $c['sort_order'],
				'hidden'     => (bool) $c['hidden'],
				'services'   => array(),
			);
		}
		foreach ( $svcs as $s ) {
			if ( isset( $out[ $s['category_id'] ] ) ) {
				$out[ $s['category_id'] ]['services'][] = self::service_out( $s );
			}
		}
		return array_values( array_filter( $out, fn( $c ) => $with_hidden || $c['services'] ) );
	}

	public static function service_out( array $s ): array {
		return array(
			'id'             => (int) $s['id'],
			'category_id'    => (int) $s['category_id'],
			'name'           => $s['name'],
			'duration'       => (int) $s['duration_min'],
			'duration_label' => Settings::duration( (int) $s['duration_min'] ),
			'price_cents'    => (int) $s['price_cents'],
			'price_label'    => Settings::money( (int) $s['price_cents'] ),
			'material_extra' => (bool) $s['material_extra'],
			'note'           => (string) $s['note'],
			'hidden'         => (bool) $s['hidden'],
			'sort_order'     => (int) $s['sort_order'],
		);
	}

	public static function service( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'services' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::service_out( $row ) : null;
	}

	/* ---------- Praktikandid ---------- */

	public static function staff_list( bool $active_only = true ): array {
		global $wpdb;
		$where = $active_only ? 'WHERE active = 1' : '';
		return array_map( array( self::class, 'staff_out' ), $wpdb->get_results( 'SELECT * FROM ' . self::t( 'staff' ) . " $where ORDER BY sort_order, name", ARRAY_A ) );
	}

	public static function staff_out( array $r ): array {
		$out = array(
			'id'                => (int) $r['id'],
			'user_id'           => $r['user_id'] ? (int) $r['user_id'] : null,
			'name'              => $r['name'],
			'photo_url'         => (string) $r['photo_url'],
			'active'            => (bool) $r['active'],
			'sort_order'        => (int) $r['sort_order'],
			'level'             => (string) ( $r['level'] ?? '' ),
			'discount_override' => isset( $r['discount_pct'] ) ? (int) $r['discount_pct'] : null,
			'extra_override'    => isset( $r['extra_min'] ) ? (int) $r['extra_min'] : null,
		);
		// Kehtivad tingimused: tase + praktikandi enda erisused.
		return $out + Pricing::terms( $out, (array) Settings::get()['levels'] );
	}

	/**
	 * Teenuse kestus ja hind konkreetse praktikandi juures.
	 *
	 * @return array{duration:int,price_cents:int}
	 */
	public static function service_terms( array $service, ?array $staff ): array {
		return array(
			'duration'    => Pricing::duration( (int) $service['duration'], (int) ( $staff['extra_min'] ?? 0 ) ),
			'price_cents' => Pricing::price( (int) $service['price_cents'], (int) ( $staff['discount_pct'] ?? 0 ) ),
		);
	}

	public static function staff( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'staff' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::staff_out( $r ) : null;
	}

	public static function staff_for_service( int $service_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.* FROM ' . self::t( 'staff' ) . ' s JOIN ' . self::t( 'staff_services' ) . ' ss ON ss.staff_id = s.id
				 WHERE s.active = 1 AND ss.service_id = %d ORDER BY s.sort_order, s.name',
				$service_id
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'staff_out' ), $rows );
	}

	public static function staff_service_ids( int $staff_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT service_id FROM ' . self::t( 'staff_services' ) . ' WHERE staff_id = %d', $staff_id ) ) );
	}

	/** Kuud ('Y-m'), mille töögraafik on koostatud ja salvestatud. */
	public static function months(): array {
		return array_values( array_filter( (array) get_option( 'sb_months', array() ), 'is_string' ) );
	}

	public static function month_ready( string $ym ): bool {
		return in_array( $ym, self::months(), true );
	}

	/**
	 * Salvestab kuu töögraafiku: iga praktikandi iga päeva kohta kas tunnid või vaba päev.
	 *
	 * @param array $plan array<int staff_id, array<string Y-m-d, list<[int,int]>>> (tühi list = ei tööta)
	 */
	public static function save_month( string $ym, array $plan ): void {
		global $wpdb;
		$t = self::t( 'schedule_exceptions' );
		foreach ( $plan as $staff_id => $days ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE staff_id = %d AND day LIKE %s", (int) $staff_id, $wpdb->esc_like( $ym ) . '-%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $days as $day => $ranges ) {
				if ( ! $ranges ) {
					$wpdb->insert( $t, array( 'staff_id' => (int) $staff_id, 'day' => $day, 'is_off' => 1 ) );
					continue;
				}
				foreach ( $ranges as $r ) {
					$wpdb->insert( $t, array( 'staff_id' => (int) $staff_id, 'day' => $day, 'start_min' => (int) $r[0], 'end_min' => (int) $r[1], 'is_off' => 0 ) );
				}
			}
		}
		$months = self::months();
		if ( ! in_array( $ym, $months, true ) ) {
			$months[] = $ym;
			sort( $months );
			update_option( 'sb_months', $months, false );
		}
	}

	/** Aktiivsete broneeringute arv praktikandi ja kohaliku päeva kaupa: [staff_id][Y-m-d] => n. */
	public static function booking_counts( string $ym ): array {
		$first = new \DateTimeImmutable( $ym . '-01 00:00:00', Settings::tz() );
		$out   = array();
		foreach ( self::bookings_between( $first->getTimestamp(), $first->modify( '+1 month' )->getTimestamp() ) as $b ) {
			$day = Format::local( $b['start_utc'] )->format( 'Y-m-d' );
			$out[ (int) $b['staff_id'] ][ $day ] = ( $out[ (int) $b['staff_id'] ][ $day ] ?? 0 ) + 1;
		}
		return $out;
	}

	/** Praktikandi tööaeg kuupäeval minutites. Päeva kirje (kuugraafik või erand) asendab nädala malli. */
	public static function work_ranges( int $staff_id, string $date ): array {
		global $wpdb;
		if ( ! empty( Settings::get()['require_month'] ) && ! self::month_ready( substr( $date, 0, 7 ) ) ) {
			return array();
		}
		return self::planned_ranges( $staff_id, $date );
	}

	/** Sama mis work_ranges, aga ei arvesta seadet "ainult koostatud kuud" (kuugraafiku eeltäiteks). */
	public static function planned_ranges( int $staff_id, string $date ): array {
		global $wpdb;
		$ex = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'schedule_exceptions' ) . ' WHERE staff_id = %d AND day = %s', $staff_id, $date ), ARRAY_A );
		if ( $ex ) {
			$out = array();
			foreach ( $ex as $r ) {
				if ( (int) $r['is_off'] ) {
					return array();
				}
				$out[] = array( (int) $r['start_min'], (int) $r['end_min'] );
			}
			return $out;
		}
		$wd   = (int) ( new \DateTimeImmutable( $date, Settings::tz() ) )->format( 'N' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT start_min, end_min FROM ' . self::t( 'schedules' ) . ' WHERE staff_id = %d AND weekday = %d ORDER BY start_min', $staff_id, $wd ), ARRAY_A );
		return array_map( fn( $r ) => array( (int) $r['start_min'], (int) $r['end_min'] ), $rows );
	}

	public static function schedule( int $staff_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT weekday, start_min, end_min FROM ' . self::t( 'schedules' ) . ' WHERE staff_id = %d ORDER BY weekday, start_min', $staff_id ), ARRAY_A );
	}

	public static function exceptions( int $staff_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'schedule_exceptions' ) . ' WHERE staff_id = %d AND day >= %s ORDER BY day', $staff_id, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ), ARRAY_A );
	}

	/* ---------- Ajad ---------- */

	/** Kohaliku päeva UTC piirid. */
	public static function day_bounds( string $date ): array {
		$d = new \DateTimeImmutable( $date . ' 00:00:00', Settings::tz() );
		return array( $d->getTimestamp(), $d->modify( '+1 day' )->getTimestamp() );
	}

	public static function bookings_between( int $from, int $to, int $exclude_id = 0, bool $active_only = true ): array {
		global $wpdb;
		$status = $active_only ? "AND b.status IN ('" . implode( "','", self::ACTIVE ) . "')" : '';
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, c.first_name, c.last_name, c.phone, c.email, c.model_id, s.name AS service_name, s.material_extra, st.name AS staff_name
				 FROM ' . self::t( 'bookings' ) . ' b
				 LEFT JOIN ' . self::t( 'clients' ) . ' c ON c.id = b.client_id
				 LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id
				 LEFT JOIN ' . self::t( 'staff' ) . " st ON st.id = b.staff_id
				 WHERE b.start_utc < %s AND b.end_utc > %s AND b.id <> %d $status
				 ORDER BY b.start_utc",
				self::utc( $to ),
				self::utc( $from ),
				$exclude_id
			),
			ARRAY_A
		);
	}

	public static function blocks_between( int $from, int $to ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::t( 'blocks' ) . ' WHERE start_utc < %s AND end_utc > %s ORDER BY start_utc', self::utc( $to ), self::utc( $from ) ),
			ARRAY_A
		);
	}

	/**
	 * Koostab Availability sisendi.
	 *
	 * @param int[]|null $staff_ids null = kõik, kes teenust teevad.
	 * @param bool       $admin     admin ei ole seotud etteteatamise ega ette broneerimise piiriga.
	 * @param int|null   $duration  kindel kestus minutites (olemasoleva broneeringu nihutamisel); null = teenus + praktikandi lisaaeg.
	 */
	public static function availability_input( array $service, ?array $staff_ids, string $date, int $exclude_booking = 0, bool $admin = false, ?int $duration = null ): array {
		$set = Settings::get();
		[ $from, $to ] = self::day_bounds( $date );
		$bookings      = self::bookings_between( $from, $to, $exclude_booking );
		$blocks        = self::blocks_between( $from, $to );

		$rows = null === $staff_ids
			? self::staff_for_service( $service['id'] )
			: array_filter( array_map( array( self::class, 'staff' ), array_map( 'intval', $staff_ids ) ) );
		$staff = array();
		foreach ( $rows as $row ) {
			$staff[ $row['id'] ] = array(
				'work'     => self::work_ranges( $row['id'], $date ),
				'bookings' => array(),
				'blocks'   => array(),
				// Kestus sõltub praktikandist (teenus + tema lisaaeg). $duration hoiab olemasoleva broneeringu pikkust.
				'duration' => $duration ?? self::service_terms( $service, $row )['duration'],
			);
		}
		$salon_blocks = array();
		foreach ( $blocks as $b ) {
			$r = array( self::ts( $b['start_utc'] ), self::ts( $b['end_utc'] ) );
			if ( empty( $b['staff_id'] ) ) {
				$salon_blocks[] = $r;
			} elseif ( isset( $staff[ (int) $b['staff_id'] ] ) ) {
				$staff[ (int) $b['staff_id'] ]['blocks'][] = $r;
			}
		}
		$salon_bookings = array();
		foreach ( $bookings as $b ) {
			$r                = array( self::ts( $b['start_utc'] ), self::ts( $b['end_utc'] ) );
			$salon_bookings[] = $r;
			if ( isset( $staff[ (int) $b['staff_id'] ] ) ) {
				$staff[ (int) $b['staff_id'] ]['bookings'][] = $r;
			}
		}

		return array(
			'date'           => $date,
			'tz'             => Settings::tz(),
			'duration'       => (int) $service['duration'],
			'step'           => (int) $set['slot_step'],
			'buffer'         => (int) $set['buffer'],
			'now'            => time(),
			'min_notice'     => $admin ? null : (int) $set['min_notice_hours'] * 60,
			'max_days'       => $admin ? null : (int) $set['max_days_ahead'],
			'opening'        => Settings::opening_for( $date ),
			'staff'          => $staff,
			'salon_blocks'   => $salon_blocks,
			'salon_bookings' => $salon_bookings,
			'capacity'       => (int) $set['max_concurrent'],
		);
	}

	/* ---------- Broneeringud ---------- */

	public static function booking( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, c.first_name, c.last_name, c.phone, c.email, c.model_id, s.name AS service_name, s.duration_min, s.material_extra, st.name AS staff_name
				 FROM ' . self::t( 'bookings' ) . ' b
				 LEFT JOIN ' . self::t( 'clients' ) . ' c ON c.id = b.client_id
				 LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id
				 LEFT JOIN ' . self::t( 'staff' ) . ' st ON st.id = b.staff_id
				 WHERE b.id = %d',
				$id
			),
			ARRAY_A
		);
		return $r ?: null;
	}

	public static function booking_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) );
		return $id ? self::booking( (int) $id ) : null;
	}

	public static function log( int $booking_id, string $action, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::t( 'booking_log' ),
			array(
				'booking_id' => $booking_id,
				'user_id'    => get_current_user_id() ?: null,
				'action'     => $action,
				'details'    => $details ? wp_json_encode( $details ) : null,
				'created_at' => self::utc( time() ),
			)
		);
	}

	/* ---------- Kliendid ---------- */

	/** Leiab kliendi e-posti või telefoni järgi või loob uue. */
	public static function upsert_client( array $c ): int {
		global $wpdb;
		$now = self::utc( time() );
		$id  = 0;
		if ( $c['email'] !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'clients' ) . ' WHERE email = %s AND anonymized_at IS NULL ORDER BY id DESC LIMIT 1', $c['email'] ) );
		}
		if ( ! $id && $c['phone'] !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'clients' ) . ' WHERE phone = %s AND anonymized_at IS NULL ORDER BY id DESC LIMIT 1', $c['phone'] ) );
		}
		$data = array(
			'first_name'      => $c['first_name'],
			'last_name'       => $c['last_name'],
			'phone'           => $c['phone'],
			'email'           => $c['email'],
			'last_contact_at' => $now,
		);
		$model_id = Integrations\Modellid::find_model_id( $c['email'], $c['phone'] );
		if ( $model_id ) {
			$data['model_id'] = $model_id;
		}
		if ( $id ) {
			$wpdb->update( self::t( 'clients' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = $now;
		$wpdb->insert( self::t( 'clients' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function search_clients( string $q, int $limit = 20 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::t( 'clients' ) . " WHERE anonymized_at IS NULL AND (CONCAT(first_name,' ',last_name) LIKE %s OR phone LIKE %s OR email LIKE %s) ORDER BY last_contact_at DESC LIMIT %d",
				$like,
				$like,
				$like,
				$limit
			),
			ARRAY_A
		);
	}

	public static function client_history( int $client_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, s.name AS service_name, st.name AS staff_name FROM ' . self::t( 'bookings' ) . ' b
				 LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id
				 LEFT JOIN ' . self::t( 'staff' ) . ' st ON st.id = b.staff_id
				 WHERE b.client_id = %d ORDER BY b.start_utc DESC',
				$client_id
			),
			ARRAY_A
		);
	}

	public static function add_consent( int $client_id, string $type, bool $granted ): void {
		global $wpdb;
		$set  = Settings::get();
		$text = $set['consent_texts'][ $type ] ?? '';
		$wpdb->insert(
			self::t( 'consents' ),
			array(
				'client_id'    => $client_id,
				'type'         => $type,
				'granted'      => $granted ? 1 : 0,
				'text_version' => $set['consent_version'],
				'text_hash'    => hash( 'sha256', $text ),
				'created_at'   => self::utc( time() ),
			)
		);
	}
}
