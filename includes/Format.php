<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

final class Format {

	public static function days(): array {
		return array( 1 => __( 'esmaspäev', 'sasipea-broneering' ), __( 'teisipäev', 'sasipea-broneering' ), __( 'kolmapäev', 'sasipea-broneering' ), __( 'neljapäev', 'sasipea-broneering' ), __( 'reede', 'sasipea-broneering' ), __( 'laupäev', 'sasipea-broneering' ), __( 'pühapäev', 'sasipea-broneering' ) );
	}

	public static function months(): array {
		return array( 1 => __( 'jaanuar', 'sasipea-broneering' ), __( 'veebruar', 'sasipea-broneering' ), __( 'märts', 'sasipea-broneering' ), __( 'aprill', 'sasipea-broneering' ), __( 'mai', 'sasipea-broneering' ), __( 'juuni', 'sasipea-broneering' ), __( 'juuli', 'sasipea-broneering' ), __( 'august', 'sasipea-broneering' ), __( 'september', 'sasipea-broneering' ), __( 'oktoober', 'sasipea-broneering' ), __( 'november', 'sasipea-broneering' ), __( 'detsember', 'sasipea-broneering' ) );
	}

	/** "teisipäev, 1. detsember 2026" */
	public static function date_long( \DateTimeInterface $d ): string {
		return sprintf( '%s, %d. %s %d', self::days()[ (int) $d->format( 'N' ) ], (int) $d->format( 'j' ), self::months()[ (int) $d->format( 'n' ) ], (int) $d->format( 'Y' ) );
	}

	public static function local( string $utc ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( Settings::tz() );
	}
}
