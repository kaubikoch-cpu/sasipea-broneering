<?php
namespace SB;

/**
 * Juuksuriõpilase tasu arvutus teenustelt, materjali müügilt ja toodete müügilt.
 * Puhas PHP ilma WordPressi sõltuvusteta, et seda saaks eraldi testida.
 *
 * Tasumäär on iga liigi kohta kas protsent käibest ('pct') või kindel summa sentides ('fixed'):
 * teenuse puhul iga tehtud teenuse eest, toote puhul iga müüdud tüki eest, materjali puhul iga müügirea eest.
 */
final class Payroll {

	public const KINDS = array( 'service', 'material', 'product' );

	/** @return array<string, array{type:string,value:float}> */
	public static function normalize( $raw ): array {
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$out = array();
		foreach ( self::KINDS as $k ) {
			$type      = ( $raw[ $k ]['type'] ?? 'pct' ) === 'fixed' ? 'fixed' : 'pct';
			$value     = max( 0.0, (float) ( $raw[ $k ]['value'] ?? 0 ) );
			$out[ $k ] = array(
				'type'  => $type,
				'value' => 'pct' === $type ? min( 100.0, $value ) : $value,
			);
		}
		return $out;
	}

	/** Tasu sentides ühe rea eest. */
	public static function amount( array $rate, int $base_cents, float $qty = 1.0 ): int {
		if ( 'fixed' === $rate['type'] ) {
			return (int) round( $rate['value'] * $qty );
		}
		return (int) round( $base_cents * $rate['value'] / 100 );
	}

	/**
	 * Kuu kokkuvõte ühe juuksuriõpilase kohta.
	 *
	 * @param array $rates    normalize() tulemus
	 * @param int[] $services tehtud teenuste hinnad sentides
	 * @param array $sales    list<array{kind:string,total_cents:int,qty:float}>
	 * @return array service/material/product => [count, turnover, pay], total_turnover, total_pay
	 */
	public static function summarize( array $rates, array $services, array $sales ): array {
		$out = array();
		foreach ( self::KINDS as $k ) {
			$out[ $k ] = array( 'count' => 0, 'turnover' => 0, 'pay' => 0 );
		}
		foreach ( $services as $cents ) {
			++$out['service']['count'];
			$out['service']['turnover'] += (int) $cents;
			$out['service']['pay']      += self::amount( $rates['service'], (int) $cents );
		}
		foreach ( $sales as $s ) {
			$k = 'product' === $s['kind'] ? 'product' : 'material';
			++$out[ $k ]['count'];
			$out[ $k ]['turnover'] += (int) $s['total_cents'];
			$out[ $k ]['pay']      += self::amount( $rates[ $k ], (int) $s['total_cents'], 'product' === $k ? (float) $s['qty'] : 1.0 );
		}
		$out['total_turnover'] = $out['service']['turnover'] + $out['material']['turnover'] + $out['product']['turnover'];
		$out['total_pay']      = $out['service']['pay'] + $out['material']['pay'] + $out['product']['pay'];
		return $out;
	}
}
