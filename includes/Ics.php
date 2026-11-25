<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

final class Ics {

	private static function esc( string $s ): string {
		return str_replace( array( "\r\n", "\n" ), '\n', addcslashes( $s, ',;\\' ) );
	}

	private static function fold( string $line ): string {
		$out = '';
		while ( strlen( $line ) > 74 ) {
			$cut  = 74;
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut; // ära lõika UTF-8 märki pooleks
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
		}
		return $out . $line;
	}

	public static function booking( array $b, string $method = 'PUBLISH' ): string {
		$set  = Settings::get();
		$fmt  = static fn( $utc ) => gmdate( 'Ymd\THis\Z', Repo::ts( $utc ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'oppesalong.ee';
		$cancelled = str_starts_with( (string) $b['status'], 'cancelled' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Sasipea Akadeemia//Broneering//ET',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:sb-' . (int) $b['id'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $fmt( $b['start_utc'] ),
			'DTEND:' . $fmt( $b['end_utc'] ),
			'SUMMARY:' . self::esc( $b['service_name'] . ' – Sasipea Akadeemia' ),
			'LOCATION:' . self::esc( 'Sasipea Akadeemia, ' . $set['salon_address'] ),
			'DESCRIPTION:' . self::esc( sprintf( "Juuksuriõpilane: %s\nTel: %s\n%s", $b['staff_name'], $set['salon_phone'], $set['salon_web'] ) ),
			'STATUS:' . ( $cancelled ? 'CANCELLED' : 'CONFIRMED' ),
			'SEQUENCE:' . (int) strtotime( $b['updated_at'] . ' UTC' ),
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}
}
