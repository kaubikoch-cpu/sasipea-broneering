<?php
namespace SB;

/**
 * Käibemaksu arvutus. Puhas PHP ilma WordPressi sõltuvusteta, et seda saaks eraldi testida.
 *
 * Hinnad on süsteemis käibemaksuga. Määrade ajalugu ("kehtib alates" + määr) hoiab ära,
 * et hilisem määra muutus või käibemaksukohustuslaseks hakkamine muudaks varasemaid kuid.
 */
final class Vat {

	/**
	 * @param array $history list<array{from:string Y-m-d, rate:float}>
	 * @return list<array{from:string,rate:float}> kuupäeva järgi kasvavalt
	 */
	public static function normalize( $history ): array {
		$out = array();
		foreach ( (array) $history as $h ) {
			$from = (string) ( $h['from'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
				continue;
			}
			$out[ $from ] = array(
				'from' => $from,
				'rate' => max( 0.0, min( 100.0, (float) ( $h['rate'] ?? 0 ) ) ),
			);
		}
		ksort( $out );
		return array_values( $out );
	}

	/** Kuupäeval kehtiv määr protsentides. 0 = käibemaksu ei arvestata. */
	public static function rate_on( array $history, string $date ): float {
		$rate = 0.0;
		foreach ( self::normalize( $history ) as $h ) {
			if ( $h['from'] <= $date ) {
				$rate = $h['rate'];
			}
		}
		return $rate;
	}

	/**
	 * Jagab käibemaksuga summa osadeks.
	 *
	 * @return array{gross:int,net:int,vat:int} sentides
	 */
	public static function split( int $gross_cents, float $rate ): array {
		$vat = $rate > 0 ? (int) round( $gross_cents * $rate / ( 100 + $rate ) ) : 0;
		return array(
			'gross' => $gross_cents,
			'net'   => $gross_cents - $vat,
			'vat'   => $vat,
		);
	}

	/**
	 * Kuu aruanne liigi ja määra kaupa.
	 *
	 * @param array $rows list<array{kind:string,date:string Y-m-d,cents:int}>
	 * @return array{lines: list<array{kind:string,rate:float,count:int,gross:int,net:int,vat:int}>, total: array{gross:int,net:int,vat:int}}
	 */
	public static function report( array $history, array $rows ): array {
		$lines = array();
		foreach ( $rows as $r ) {
			$rate = self::rate_on( $history, $r['date'] );
			$key  = $r['kind'] . '|' . $rate;
			if ( ! isset( $lines[ $key ] ) ) {
				$lines[ $key ] = array( 'kind' => $r['kind'], 'rate' => $rate, 'count' => 0, 'gross' => 0 );
			}
			++$lines[ $key ]['count'];
			$lines[ $key ]['gross'] += (int) $r['cents'];
		}
		$total = array( 'gross' => 0, 'net' => 0, 'vat' => 0 );
		foreach ( $lines as $k => $l ) {
			// Käibemaks arvutatakse rea kogusummalt, mitte üksikute müükide ümardatud summadest.
			$lines[ $k ] += array_diff_key( self::split( $l['gross'], $l['rate'] ), array( 'gross' => 1 ) );
			foreach ( array( 'gross', 'net', 'vat' ) as $f ) {
				$total[ $f ] += $lines[ $k ][ $f ];
			}
		}
		$order = array( 'service' => 0, 'material' => 1, 'product' => 2 );
		usort( $lines, static fn( $a, $b ) => ( $order[ $a['kind'] ] ?? 9 ) <=> ( $order[ $b['kind'] ] ?? 9 ) ?: $a['rate'] <=> $b['rate'] );
		return array( 'lines' => array_values( $lines ), 'total' => $total );
	}
}
