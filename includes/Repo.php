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

	/** Juuksuriõpilase värvid päevaplaanis: nimi => [tugev, hele taust, tume tekst]. Sama loend on planner.js-is. */
	public const COLORS = array(
		'#d6336c' => 'Roosa',
		'#7048e8' => 'Lilla',
		'#1c7ed6' => 'Sinine',
		'#0c8599' => 'Sinakasroheline',
		'#ae3ec9' => 'Fuksia',
		'#e67700' => 'Oranž',
		'#0b84c6' => 'Taevasinine',
		'#4263eb' => 'Indigo',
		'#e8590c' => 'Korall',
		'#5c940d' => 'Laim',
		'#8d6e4b' => 'Pruun',
		'#495057' => 'Grafiit',
	);

	/** Salvestab juuksuriõpilase värvi; tühi = automaatne värv. Tundmatu värv jäetakse vahele. */
	public static function set_staff_color( int $staff_id, string $color ): bool {
		global $wpdb;
		$color = strtolower( $color );
		if ( '' !== $color && ! isset( self::COLORS[ $color ] ) ) {
			return false;
		}
		return false !== $wpdb->update( self::t( 'staff' ), array( 'color' => '' === $color ? null : $color ), array( 'id' => $staff_id ) );
	}

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

	/* ---------- Juuksuriõpilased ---------- */

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
			'pay'               => Payroll::normalize( $r['pay'] ?? null ),
			'color'             => isset( self::COLORS[ (string) ( $r['color'] ?? '' ) ] ) ? (string) $r['color'] : '',
		);
		// Kehtivad tingimused: tase + juuksuriõpilase enda erisused.
		return $out + Pricing::terms( $out, (array) Settings::get()['levels'] );
	}

	public const MAX_COMBO = 5;

	/** "3,7" või [3, 7] -> unikaalsed teenuste id-d sisestamise järjekorras (kuni MAX_COMBO). */
	public static function parse_ids( $raw ): array {
		$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids   = array();
		foreach ( $parts as $p ) {
			$id = (int) $p;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, self::MAX_COMBO );
	}

	/**
	 * Mitu teenust ühe broneeringuna: kestused ja hinnad liidetakse. Puhas funktsioon (testitav).
	 * Tulemus on sama kujuga mis üks teenus; 'id' on esimese teenuse id ja 'ids' kõik id-d.
	 *
	 * @param list<array> $services service_out() kujul teenused
	 */
	public static function combine( array $services ): ?array {
		if ( ! $services ) {
			return null;
		}
		$out        = $services[0];
		$out['ids'] = array_map( static fn( $s ) => (int) $s['id'], $services );
		// Osad eraldi: juuksuriõpilase lisaaeg ja soodustus arvestatakse iga teenuse kohta.
		$out['parts'] = array_map( static fn( $s ) => array( 'duration' => (int) $s['duration'], 'price_cents' => (int) $s['price_cents'] ), $services );
		if ( count( $services ) > 1 ) {
			$out['name']           = implode( ' + ', array_column( $services, 'name' ) );
			$out['duration']       = array_sum( array_map( static fn( $s ) => (int) $s['duration'], $services ) );
			$out['price_cents']    = array_sum( array_map( static fn( $s ) => (int) $s['price_cents'], $services ) );
			$out['material_extra'] = (bool) array_filter( array_column( $services, 'material_extra' ) );
			$out['hidden']         = (bool) array_filter( array_column( $services, 'hidden' ) );
			$out['note']           = '';
			$out['duration_label'] = Settings::duration( $out['duration'] );
			$out['price_label']    = Settings::money( $out['price_cents'] );
		}
		return $out;
	}

	/** Teenus või teenuste kombinatsioon id-de järgi; null, kui mõnda teenust ei leitud. */
	public static function combo( $raw ): ?array {
		$services = array();
		foreach ( self::parse_ids( $raw ) as $id ) {
			$s = self::service( $id );
			if ( ! $s ) {
				return null;
			}
			$services[] = $s;
		}
		return self::combine( $services );
	}

	/** Broneeringu teenus koos lisateenustega (kui broneering tehti mitme teenusega). */
	public static function booking_service( array $b ): ?array {
		return ! empty( $b['service_ids'] ) ? ( self::combo( $b['service_ids'] ) ?? self::service( (int) $b['service_id'] ) ) : self::service( (int) $b['service_id'] );
	}

	/** Teenuse id-d: kombinatsiooni korral kõik, muidu üks. */
	public static function ids_of( array $service ): array {
		return isset( $service['ids'] ) ? array_map( 'intval', (array) $service['ids'] ) : array( (int) $service['id'] );
	}

	/** Juuksuriõpilased, kes teevad kõiki antud teenuseid. */
	public static function staff_for_services( array $ids ): array {
		$out = null;
		foreach ( $ids as $id ) {
			$rows = array();
			foreach ( self::staff_for_service( (int) $id ) as $s ) {
				$rows[ $s['id'] ] = $s;
			}
			$out = null === $out ? $rows : array_intersect_key( $out, $rows );
		}
		return array_values( $out ?? array() );
	}

	/**
	 * Teenuse kestus ja hind konkreetse juuksuriõpilase juures (mitme teenuse korral osade summa).
	 *
	 * @return array{duration:int,price_cents:int}
	 */
	public static function service_terms( array $service, ?array $staff ): array {
		$out = array( 'duration' => 0, 'price_cents' => 0 );
		foreach ( $service['parts'] ?? array( $service ) as $p ) {
			$out['duration']    += Pricing::duration( (int) $p['duration'], (int) ( $staff['extra_min'] ?? 0 ) );
			$out['price_cents'] += Pricing::price( (int) $p['price_cents'], (int) ( $staff['discount_pct'] ?? 0 ) );
		}
		return $out;
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

	/** Kuud, mille töögraafik on salvestatud (avalikud ja veel avalikustamata). */
	public static function saved_months(): array {
		$saved = array_filter( (array) get_option( 'sb_months_saved', array() ), 'is_string' );
		return array_values( array_unique( array_merge( $saved, self::months() ) ) );
	}

	/** Kas kuu töögraafik on salvestatud (ka siis, kui see pole veel klientidele avalik). */
	public static function month_saved( string $ym ): bool {
		return in_array( $ym, self::saved_months(), true );
	}

	/**
	 * Salvestab kuu töögraafiku: iga juuksuriõpilase iga päeva kohta kas tunnid või vaba päev.
	 *
	 * @param array $plan   array<int staff_id, array<string Y-m-d, list<[int,int]>>> (tühi list = ei tööta)
	 * @param bool  $public kas kuu on klientidele avalik (false = ainult salvestatud, salongi enda jaoks)
	 */
	public static function save_month( string $ym, array $plan, bool $public = true ): void {
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
		$saved = array_values( array_filter( (array) get_option( 'sb_months_saved', array() ), 'is_string' ) );
		if ( ! in_array( $ym, $saved, true ) ) {
			$saved[] = $ym;
			sort( $saved );
			update_option( 'sb_months_saved', $saved, false );
		}
		$months = self::months();
		$is     = in_array( $ym, $months, true );
		if ( $public && ! $is ) {
			$months[] = $ym;
			sort( $months );
			update_option( 'sb_months', $months, false );
		} elseif ( ! $public && $is ) {
			update_option( 'sb_months', array_values( array_diff( $months, array( $ym ) ) ), false );
		}
	}

	/**
	 * Tööaeg päevaplaani jaoks: nagu work_ranges, aga salvestatud ja veel avalikustamata kuu
	 * on salongi töötajatele juba näha (klientidele mitte).
	 */
	public static function staff_view_ranges( int $staff_id, string $date ): array {
		if ( ! empty( Settings::get()['require_month'] ) && ! self::month_saved( substr( $date, 0, 7 ) ) ) {
			return array();
		}
		return self::planned_ranges( $staff_id, $date );
	}

	/** Aktiivsete broneeringute arv juuksuriõpilase ja kohaliku päeva kaupa: [staff_id][Y-m-d] => n. */
	public static function booking_counts( string $ym ): array {
		$first = new \DateTimeImmutable( $ym . '-01 00:00:00', Settings::tz() );
		$out   = array();
		foreach ( self::bookings_between( $first->getTimestamp(), $first->modify( '+1 month' )->getTimestamp() ) as $b ) {
			$day = Format::local( $b['start_utc'] )->format( 'Y-m-d' );
			$out[ (int) $b['staff_id'] ][ $day ] = ( $out[ (int) $b['staff_id'] ][ $day ] ?? 0 ) + 1;
		}
		return $out;
	}

	/** Juuksuriõpilase tööaeg kuupäeval minutites. Päeva kirje (kuugraafik või erand) asendab nädala malli. */
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
		// Salongi suletud päeval nädalapõhi ei kehti; tööle saab ainult eraldi märgitud tundidega.
		if ( ! Settings::opening_for( $date ) ) {
			return array();
		}
		$wd   = (int) ( new \DateTimeImmutable( $date, Settings::tz() ) )->format( 'N' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT start_min, end_min FROM ' . self::t( 'schedules' ) . ' WHERE staff_id = %d AND weekday = %d ORDER BY start_min', $staff_id, $wd ), ARRAY_A );
		return array_map( fn( $r ) => array( (int) $r['start_min'], (int) $r['end_min'] ), $rows );
	}

	/** Töögraafikus sellele päevale eraldi märgitud tunnid (ilma nädalapõhjata). Vaba päev või märkimata = tühi. */
	public static function explicit_ranges( int $staff_id, string $date ): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'schedule_exceptions' ) . ' WHERE staff_id = %d AND day = %s', $staff_id, $date ), ARRAY_A ) as $r ) {
			if ( (int) $r['is_off'] ) {
				return array();
			}
			$out[] = array( (int) $r['start_min'], (int) $r['end_min'] );
		}
		return $out;
	}

	/**
	 * Päeva tegelik lahtiolekuaeg: seadete lahtiolekuaeg pluss tunnid, mis mõnele aktiivsele
	 * juuksuriõpilasele on töögraafikus just sellele päevale märgitud. Nii saab salongi avada
	 * ka muidu suletud päeval (nt esmaspäeval) või tavapärasest varem ja kauem.
	 */
	public static function opening_for( string $date ): array {
		global $wpdb;
		$ranges = Settings::opening_for( $date );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.staff_id, e.start_min, e.end_min, e.is_off FROM ' . self::t( 'schedule_exceptions' ) . ' e INNER JOIN ' . self::t( 'staff' ) . ' s ON s.id = e.staff_id WHERE e.day = %s AND s.active = 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$date
			),
			ARRAY_A
		);
		$off = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['is_off'] ) {
				$off[ (int) $r['staff_id'] ] = true;
			}
		}
		foreach ( $rows as $r ) {
			if ( ! isset( $off[ (int) $r['staff_id'] ] ) ) {
				$ranges[] = array( (int) $r['start_min'], (int) $r['end_min'] );
			}
		}
		return Availability::merge_ranges( $ranges );
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
				'SELECT b.*, c.first_name, c.last_name, c.phone, c.email, c.model_id, COALESCE(b.service_label, s.name) AS service_name, s.material_extra, st.name AS staff_name
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
	 * @param int|null   $duration  kindel kestus minutites (olemasoleva broneeringu nihutamisel); null = teenus + juuksuriõpilase lisaaeg.
	 */
	public static function availability_input( array $service, ?array $staff_ids, string $date, int $exclude_booking = 0, bool $admin = false, ?int $duration = null ): array {
		$set = Settings::get();
		[ $from, $to ] = self::day_bounds( $date );
		$bookings      = self::bookings_between( $from, $to, $exclude_booking );
		$blocks        = self::blocks_between( $from, $to );

		$rows = null === $staff_ids
			? self::staff_for_services( self::ids_of( $service ) )
			: array_filter( array_map( array( self::class, 'staff' ), array_map( 'intval', $staff_ids ) ) );
		$staff = array();
		foreach ( $rows as $row ) {
			$staff[ $row['id'] ] = array(
				'work'     => self::work_ranges( $row['id'], $date ),
				'bookings' => array(),
				'blocks'   => array(),
				// Kestus sõltub juuksuriõpilasest (teenus + tema lisaaeg). $duration hoiab olemasoleva broneeringu pikkust.
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
			'opening'        => self::opening_for( $date ),
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
				'SELECT b.*, c.first_name, c.last_name, c.phone, c.email, c.model_id, COALESCE(b.service_label, s.name) AS service_name, s.duration_min, s.material_extra, st.name AS staff_name
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

	/* ---------- Tooted, müük ja tasud ---------- */

	public static function products( bool $active_only = true ): array {
		global $wpdb;
		$where = $active_only ? 'WHERE active = 1' : '';
		return array_map(
			fn( $r ) => array(
				'id'          => (int) $r['id'],
				'name'        => $r['name'],
				'price_cents' => (int) $r['price_cents'],
				'price_label' => Settings::money( (int) $r['price_cents'] ),
				'active'      => (bool) $r['active'],
				'sort_order'  => (int) $r['sort_order'],
			),
			$wpdb->get_results( 'SELECT * FROM ' . self::t( 'products' ) . " $where ORDER BY sort_order, name", ARRAY_A )
		);
	}

	/**
	 * Lisab müügirea. Materjali hind tuleb seadetest (senti grammi kohta), toote hind toodete nimekirjast.
	 *
	 * @param string $item "material:<id>" või "product:<id>"
	 * @return int|\WP_Error rea id
	 */
	public static function add_sale( int $staff_id, ?int $booking_id, string $item, float $qty, int $sold_at ) {
		global $wpdb;
		[ $kind, $ref ] = explode( ':', $item, 2 ) + array( '', '' );
		$bad            = new \WP_Error( 'sb_invalid', __( 'Vali materjal või toode ja sisesta kogus.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		if ( $qty <= 0 || $qty > 100000 || ! $staff_id ) {
			return $bad;
		}
		$row = null;
		if ( 'material' === $kind ) {
			foreach ( (array) Settings::get()['materials'] as $m ) {
				if ( (string) $m['id'] === $ref ) {
					$row = array( 'product_id' => null, 'label' => $m['name'], 'unit_cents' => (int) $m['cents_per_g'] );
				}
			}
		} elseif ( 'woo' === $kind ) {
			// Veebipoe toode: "woo:<toote id>:<variatsiooni id>". Hind ja nimi tulevad poest, mitte brauserist.
			[ $pid, $vid ] = array_map( 'intval', explode( ':', $ref ) + array( 0, 0 ) );
			$qty           = (float) max( 1, round( $qty ) );
			$note          = $booking_id
				/* translators: %d: booking id */
				? sprintf( __( 'Müüdud salongis (broneering nr %d)', 'sasipea-broneering' ), $booking_id )
				: __( 'Müüdud salongis', 'sasipea-broneering' );
			$sold          = $pid ? Integrations\Woo::sell( $pid, $vid, (int) $qty, $note ) : $bad;
			if ( is_wp_error( $sold ) ) {
				return $sold;
			}
			$kind = 'product';
			$row  = array(
				'product_id'   => null,
				'label'        => $sold['name'],
				'unit_cents'   => (int) round( $sold['total_cents'] / $qty ),
				'sku'          => $sold['sku'],
				'woo_order_id' => $sold['order_id'],
				'total_cents'  => $sold['total_cents'],
			);
		} elseif ( 'product' === $kind ) {
			foreach ( self::products() as $p ) {
				if ( $p['id'] === (int) $ref ) {
					$row = array( 'product_id' => $p['id'], 'label' => $p['name'], 'unit_cents' => $p['price_cents'] );
					$qty = (float) max( 1, round( $qty ) );
				}
			}
		}
		if ( ! $row ) {
			return $bad;
		}
		$wpdb->insert(
			self::t( 'sales' ),
			$row + array(
				'booking_id'  => $booking_id ?: null,
				'staff_id'    => $staff_id,
				'kind'        => $kind,
				'qty'         => $qty,
				'total_cents' => (int) round( $row['unit_cents'] * $qty ),
				'sold_at'     => self::utc( $sold_at ),
				'created_by'  => get_current_user_id() ?: null,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Eemaldab müügirea. Veebipoe tellimusega rida eemaldatakse alles siis, kui tellimuse
	 * tühistamine poes õnnestus, et ladu ja meie arvestus lahku ei läheks.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_sale( int $id ) {
		global $wpdb;
		$order = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT woo_order_id FROM ' . self::t( 'sales' ) . ' WHERE id = %d', $id ) );
		if ( $order ) {
			$res = Integrations\Woo::cancel( $order );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$wpdb->delete( self::t( 'sales' ), array( 'id' => $id ) );
		return true;
	}

	public static function sale_out( array $r ): array {
		$qty = (float) $r['qty'];
		return array(
			'id'          => (int) $r['id'],
			'booking_id'  => $r['booking_id'] ? (int) $r['booking_id'] : null,
			'staff_id'    => (int) $r['staff_id'],
			'kind'        => $r['kind'],
			'label'       => $r['label'],
			'sku'         => (string) ( $r['sku'] ?? '' ),
			'woo_order_id' => ! empty( $r['woo_order_id'] ) ? (int) $r['woo_order_id'] : null,
			'qty'         => $qty,
			'qty_label'   => rtrim( rtrim( number_format( $qty, 2, ',', '' ), '0' ), ',' ) . ( 'material' === $r['kind'] ? ' g' : ' tk' ),
			'total_cents' => (int) $r['total_cents'],
			'total_label' => Settings::money( (int) $r['total_cents'] ),
			'sold_at'     => $r['sold_at'],
		);
	}

	public static function sales_for_booking( int $booking_id ): array {
		global $wpdb;
		return array_map( array( self::class, 'sale_out' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sales' ) . ' WHERE booking_id = %d ORDER BY id', $booking_id ), ARRAY_A ) );
	}

	/**
	 * Kuu tasude alusandmed juuksuriõpilase kaupa: tehtud teenused (staatus "Kohal") ja müügiread.
	 *
	 * @return array<int, array{services: list<array>, sales: list<array>}>
	 */
	public static function payroll_data( string $ym ): array {
		global $wpdb;
		$first = new \DateTimeImmutable( $ym . '-01 00:00:00', Settings::tz() );
		$from  = self::utc( $first->getTimestamp() );
		$to    = self::utc( $first->modify( '+1 month' )->getTimestamp() );
		$out   = array();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.id, b.staff_id, b.start_utc, b.price_cents, COALESCE(b.service_label, s.name) AS service_name, c.first_name, c.last_name
				 FROM ' . self::t( 'bookings' ) . ' b
				 LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id
				 LEFT JOIN ' . self::t( 'clients' ) . " c ON c.id = b.client_id
				 WHERE b.status = 'arrived' AND b.start_utc >= %s AND b.start_utc < %s ORDER BY b.start_utc",
				$from,
				$to
			),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$out[ (int) $r['staff_id'] ]['services'][] = $r;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sales' ) . ' WHERE sold_at >= %s AND sold_at < %s ORDER BY sold_at, id', $from, $to ), ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ (int) $r['staff_id'] ]['sales'][] = self::sale_out( $r );
		}
		foreach ( $out as $sid => $d ) {
			$out[ $sid ] += array( 'services' => array(), 'sales' => array() );
		}
		return $out;
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
				'SELECT b.*, COALESCE(b.service_label, s.name) AS service_name, st.name AS staff_name FROM ' . self::t( 'bookings' ) . ' b
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
