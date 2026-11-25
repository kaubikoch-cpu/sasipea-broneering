<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const TZ = 'Europe/Tallinn';

	public static function defaults(): array {
		return array(
			// ISO nädalapäev (1 = E ... 7 = P) => [[algus, lõpp], ...].
			'opening_hours'       => array(
				'1' => array(),
				'2' => array( array( '12:00', '19:00' ) ),
				'3' => array( array( '10:00', '16:00' ) ),
				'4' => array( array( '12:00', '19:00' ) ),
				'5' => array( array( '10:00', '16:00' ) ),
				'6' => array(),
				'7' => array(),
			),
			'slot_step'           => 30,   // min
			'buffer'              => 0,    // min teenuste vahel
			'min_notice_hours'    => 12,
			'max_days_ahead'      => 60,
			'max_concurrent'      => 3,    // korraga juhendatavaid kliente
			'cancel_hours'        => 24,   // kuni mitu h ette saab klient tühistada/muuta
			// Juuksuriõpilase tasemed: soodustus teenuse hinnast (%) ja lisaaeg teenuse kestusele (min).
			'levels'              => array(
				array( 'id' => 'noorem', 'name' => 'Noorempraktikant', 'discount_pct' => 10, 'extra_min' => 30 ),
				array( 'id' => 'vanem', 'name' => 'Vanempraktikant', 'discount_pct' => 0, 'extra_min' => 0 ),
			),
			// Materjalid, mida müüakse grammi kaupa (hind sentides grammi kohta).
			'materials'           => array(
				array( 'id' => 'pulber', 'name' => 'Blondeerimispulber', 'cents_per_g' => 20 ),
				array( 'id' => 'varv', 'name' => 'Värv', 'cents_per_g' => 30 ),
			),
			// Veebipoe (WooCommerce) ühendus toodete otsinguks ja lao mahaarvamiseks.
			'woo_url'             => '',
			'woo_key'             => '',
			'woo_secret'          => '',
			// Sisselogimine Google'i kontoga (OAuth klient Google Cloudist). Tühi = välja lülitatud.
			'google_client_id'     => '',
			'google_client_secret' => '',
			'google_admins'        => false,
			// Käibemaksu määrade ajalugu: [['from' => 'Y-m-d', 'rate' => 24], ...]. Tühi = pole käibemaksukohustuslane.
			'vat_history'         => array(),
			// Kui sees, saab online broneerida ainult nendesse kuudesse, mille töögraafik on koostatud.
			'require_month'       => true,
			// Mida sisseloginud juuksuriõpilane päevaplaanis näeb. Muuta ei saa ta midagi.
			'trainee_view'        => array(
				'phone'   => true,  // kliendi telefon
				'price'   => true,  // teenuse hind
				'details' => false, // kliendi e-post ja kommentaar
				'others'  => false, // teiste juuksuriõpilaste veerud (ilma klientide andmeteta)
				'pay'     => false, // enda kuu tasu kokkuvõte
			),
			// Töölaua (oma sisselogimisega vaade väljaspool WP adminni) aadressi lõpp: /salong/.
			'dashboard_slug'      => 'salong',
			'booking_page_url'    => '/broneeri/',
			'privacy_url'         => '/privaatsuspoliitika/',
			'notify_email'        => 'info@oppesalong.ee',
			'from_email'          => 'info@oppesalong.ee',
			'from_name'           => 'Sasipea Akadeemia',
			'salon_address'       => 'Pikk 39, Tartu',
			'salon_phone'         => '5858 8007',
			'salon_web'           => 'oppesalong.ee',
			'consent_version'     => '2026-11-2',
			'consent_texts'       => array(
				'privacy' => 'Olen tutvunud privaatsuspoliitikaga ja nõustun, et mu andmeid kasutatakse broneeringu haldamiseks.',
				'models'  => 'Lisa mind ka tulevikus modellide andmebaasi.',
				'social'  => 'Luban oma soengu pilte kasutada sotsiaalmeedias.',
			),
			'confirmation_note'   => 'Tööd teevad juuksuriõpilased juhendaja käe all, seetõttu võib teenus võtta veidi kauem aega. Värvimisel ja triibutamisel lisandub hinnale materjal (blondeerimispulber 0,20 €/g, värv 0,30 €/g).',
			'templates'           => Mailer::default_templates(),
		);
	}

	public static function get(): array {
		$saved = get_option( 'sb_settings', array() );
		$s     = array_replace( self::defaults(), is_array( $saved ) ? $saved : array() );
		$s['templates'] = array_replace( Mailer::default_templates(), (array) ( $s['templates'] ?? array() ) );
		return $s;
	}

	public static function update( array $values ): void {
		update_option( 'sb_settings', array_replace( self::get(), $values ) );
	}

	public static function tz(): \DateTimeZone {
		return new \DateTimeZone( self::TZ );
	}

	public static function to_min( string $hhmm ): int {
		[ $h, $m ] = array_map( 'intval', explode( ':', $hhmm ) + array( 0, 0 ) );
		return $h * 60 + $m;
	}

	public static function from_min( int $min ): string {
		return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
	}

	/** Avatud ajad antud kuupäeval minutites [[start,end]]. */
	public static function opening_for( string $date ): array {
		$wd  = (int) ( new \DateTimeImmutable( $date, self::tz() ) )->format( 'N' );
		$out = array();
		foreach ( self::get()['opening_hours'][ (string) $wd ] ?? array() as $r ) {
			$out[] = array( self::to_min( $r[0] ), self::to_min( $r[1] ) );
		}
		return $out;
	}

	public static function money( int $cents ): string {
		$s = number_format( $cents / 100, ( $cents % 100 ) ? 2 : 0, ',', ' ' );
		return $s . ' €';
	}

	public static function duration( int $min ): string {
		$h = intdiv( $min, 60 );
		$m = $min % 60;
		if ( $h && $m ) {
			return sprintf( '%d h %d min', $h, $m );
		}
		return $h ? sprintf( '%d h', $h ) : sprintf( '%d min', $m );
	}

	public static function booking_url( array $args = array() ): string {
		$base = self::get()['booking_page_url'];
		$url  = str_starts_with( $base, 'http' ) ? $base : home_url( $base );
		return $args ? add_query_arg( $args, $url ) : $url;
	}
}
