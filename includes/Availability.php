<?php
namespace SB;

/**
 * Vaba aja arvutus. Puhas PHP ilma WordPressi sõltuvusteta, et seda saaks eraldi testida.
 *
 * Kõik absoluutsed ajad on Unix ajatemplid (UTC). Tööaeg ja lahtiolekuaeg on minutid
 * kohaliku päeva algusest; need teisendatakse antud ajavööndis, nii et suveaja vahetus
 * läheb õigesti.
 *
 * Sisend $p:
 *   date            string Y-m-d (kohalik kuupäev)
 *   tz              \DateTimeZone
 *   duration        int, teenuse kestus minutites
 *   step            int, ajasamm minutites
 *   buffer          int, puhver minutites enne ja pärast teisi sama juuksuriõpilase broneeringuid
 *   now             int, praegune ajatempel
 *   min_notice      int, minutites (null = ei kontrolli)
 *   max_days        int|null, mitu päeva ette (null = ei kontrolli)
 *   opening         list<[int,int]> lahtiolekuaeg minutites
 *   staff           array<int, [work => list<[int,int]> minutites, bookings => list<[ts,ts]>, blocks => list<[ts,ts]>,
 *                   duration => int|null (juuksuriõpilase enda kestus, nt teenus + lisaaeg; puudumisel üldine duration)]>
 *   salon_blocks    list<[ts,ts]>
 *   salon_bookings  list<[ts,ts]> kõik aktiivsed broneeringud (juhendajate mahu kontrolliks)
 *   capacity        int, mitu klienti korraga (0 = piiranguta)
 */
final class Availability {

	/** @return list<array{time:string,start:int,staff:list<int>}> */
	public static function slots( array $p ): array {
		if ( ! self::day_allowed( $p ) || empty( $p['opening'] ) ) {
			return array();
		}
		$step     = max( 5, (int) $p['step'] );
		$min_open = min( array_column( $p['opening'], 0 ) );
		$max_open = max( array_column( $p['opening'], 1 ) );
		$out      = array();
		$shortest = (int) $p['duration'];
		foreach ( $p['staff'] as $st ) {
			$shortest = min( $shortest, (int) ( $st['duration'] ?? $p['duration'] ) );
		}
		for ( $m = $min_open; $m + $shortest <= $max_open; $m += $step ) {
			$s    = self::ts( $p, $m );
			$free = self::free_staff( $p, $s );
			if ( $free ) {
				$out[] = array(
					'time'  => sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ),
					'start' => $s,
					'staff' => $free,
				);
			}
		}
		return $out;
	}

	/**
	 * Juuksuriõpilased, kellele sobib algus $s (ajatempel). Kasutatakse ka salvestamisel ja
	 * admini lohistamisel, kus algus ei pea olema ajasammul.
	 *
	 * @return list<int>
	 */
	public static function free_staff( array $p, int $s ): array {
		if ( ! self::day_allowed( $p ) ) {
			return array();
		}
		$buffer = (int) ( $p['buffer'] ?? 0 ) * 60;

		if ( isset( $p['min_notice'] ) && $s < (int) $p['now'] + (int) $p['min_notice'] * 60 ) {
			return array();
		}
		$opening = array_map( fn( $r ) => array( self::ts( $p, $r[0] ), self::ts( $p, $r[1] ) ), $p['opening'] );
		$cap     = (int) ( $p['capacity'] ?? 0 );
		$free    = array();
		foreach ( $p['staff'] as $sid => $st ) {
			// Lõpp sõltub juuksuriõpilasest: aeglasemal juuksuriõpilasel on sama teenus pikem.
			$e = $s + (int) ( $st['duration'] ?? $p['duration'] ) * 60;
			if ( ! self::inside_any( $s, $e, $opening ) ) {
				continue;
			}
			if ( self::overlaps_any( $s, $e, $p['salon_blocks'] ?? array() ) ) {
				continue;
			}
			if ( $cap > 0 && self::max_overlap( $s, $e, $p['salon_bookings'] ?? array() ) >= $cap ) {
				continue;
			}
			$work = array_map( fn( $r ) => array( self::ts( $p, $r[0] ), self::ts( $p, $r[1] ) ), $st['work'] );
			if ( ! self::inside_any( $s, $e, $work ) ) {
				continue;
			}
			if ( self::overlaps_any( $s, $e, $st['blocks'] ?? array() ) ) {
				continue;
			}
			if ( self::overlaps_any( $s - $buffer, $e + $buffer, $st['bookings'] ?? array() ) ) {
				continue;
			}
			$free[] = (int) $sid;
		}
		return $free;
	}

	private static function day_allowed( array $p ): bool {
		$tz    = $p['tz'];
		$day   = new \DateTimeImmutable( $p['date'] . ' 00:00:00', $tz );
		$today = ( new \DateTimeImmutable( '@' . (int) $p['now'] ) )->setTimezone( $tz )->setTime( 0, 0 );
		if ( isset( $p['min_notice'] ) && $day < $today ) {
			return false;
		}
		if ( isset( $p['max_days'] ) && $day > $today->modify( '+' . (int) $p['max_days'] . ' days' ) ) {
			return false;
		}
		return true;
	}

	/** Kohalik minut antud päeval -> ajatempel. */
	public static function ts( array $p, int $min ): int {
		$d = new \DateTimeImmutable( $p['date'] . ' 00:00:00', $p['tz'] );
		return $d->setTime( intdiv( $min, 60 ), $min % 60 )->getTimestamp();
	}

	/** Kas [s,e) mahub täielikult mõnda vahemikku. */
	public static function inside_any( int $s, int $e, array $ranges ): bool {
		foreach ( $ranges as $r ) {
			if ( $s >= $r[0] && $e <= $r[1] ) {
				return true;
			}
		}
		return false;
	}

	public static function overlaps_any( int $s, int $e, array $ranges ): bool {
		foreach ( $ranges as $r ) {
			if ( $s < $r[1] && $e > $r[0] ) {
				return true;
			}
		}
		return false;
	}

	/** Suurim samaaegsete vahemike arv lõigus [s,e). */
	public static function max_overlap( int $s, int $e, array $ranges ): int {
		$ev = array();
		foreach ( $ranges as $r ) {
			if ( $r[0] < $e && $r[1] > $s ) {
				$ev[] = array( max( $r[0], $s ), 1 );
				$ev[] = array( min( $r[1], $e ), -1 );
			}
		}
		// Lõpp enne algust samal hetkel: järjestikused broneeringud ei kattu.
		usort( $ev, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
		$cur = 0;
		$max = 0;
		foreach ( $ev as $x ) {
			$cur += $x[1];
			$max  = max( $max, $cur );
		}
		return $max;
	}
}
