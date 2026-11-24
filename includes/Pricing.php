<?php
namespace SB;

/**
 * Praktikandi taseme mõju teenusele: soodustus hinnast ja lisaaeg kestusele.
 * Puhas PHP ilma WordPressi sõltuvusteta, et seda saaks eraldi testida.
 */
final class Pricing {

	/**
	 * Praktikandi tingimused: tase annab vaikeväärtused, praktikandi enda väljad
	 * (kui need on täidetud) asendavad taseme omad.
	 *
	 * @param array $staff  level, discount_override (int|null), extra_override (int|null)
	 * @param array $levels list<array{id:string,name:string,discount_pct:int,extra_min:int}>
	 * @return array{level_name:string,discount_pct:int,extra_min:int}
	 */
	public static function terms( array $staff, array $levels ): array {
		$out = array(
			'level_name'   => '',
			'discount_pct' => 0,
			'extra_min'    => 0,
		);
		foreach ( $levels as $l ) {
			if ( (string) $l['id'] === (string) ( $staff['level'] ?? '' ) && '' !== (string) $l['id'] ) {
				$out = array(
					'level_name'   => (string) $l['name'],
					'discount_pct' => (int) $l['discount_pct'],
					'extra_min'    => (int) $l['extra_min'],
				);
				break;
			}
		}
		if ( isset( $staff['discount_override'] ) ) {
			$out['discount_pct'] = (int) $staff['discount_override'];
		}
		if ( isset( $staff['extra_override'] ) ) {
			$out['extra_min'] = (int) $staff['extra_override'];
		}
		$out['discount_pct'] = max( 0, min( 100, $out['discount_pct'] ) );
		$out['extra_min']    = max( 0, min( 240, $out['extra_min'] ) );
		return $out;
	}

	/** Hind sentides pärast soodustust, ümardatud lähima sendini. */
	public static function price( int $cents, int $discount_pct ): int {
		$pct = max( 0, min( 100, $discount_pct ) );
		return (int) round( $cents * ( 100 - $pct ) / 100 );
	}

	/** Kestus minutites koos praktikandi lisaajaga. */
	public static function duration( int $minutes, int $extra_min ): int {
		return $minutes + max( 0, $extra_min );
	}
}
