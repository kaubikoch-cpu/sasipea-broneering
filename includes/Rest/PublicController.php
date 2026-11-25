<?php
namespace SB\Rest;

use SB\Availability;
use SB\BookingService;
use SB\Ics;
use SB\Repo;
use SB\Security;
use SB\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Avalik REST API: sasipea/v1
 */
final class PublicController {

	public const NS = 'sasipea/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$open = '__return_true';
		register_rest_route( self::NS, '/nonce', array( 'methods' => 'GET', 'callback' => array( self::class, 'nonce' ), 'permission_callback' => $open ) );
		register_rest_route( self::NS, '/services', array( 'methods' => 'GET', 'callback' => array( self::class, 'services' ), 'permission_callback' => $open ) );
		register_rest_route(
			self::NS,
			'/staff',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'staff' ),
				'permission_callback' => $open,
				'args'                => array( 'service' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d+(,\d+)*$' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/availability/days',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'days' ),
				'permission_callback' => $open,
				'args'                => array(
					'service' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d+(,\d+)*$' ),
					'staff'   => array( 'type' => 'integer', 'default' => 0 ),
					'month'   => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/availability/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'slots' ),
				'permission_callback' => $open,
				'args'                => array(
					'service' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d+(,\d+)*$' ),
					'staff'   => array( 'type' => 'integer', 'default' => 0 ),
					'date'    => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'exclude' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Security::class, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NS,
			'/manage/(?P<token>[a-f0-9]{64})',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'manage_get' ), 'permission_callback' => $open ),
				array( 'methods' => 'POST', 'callback' => array( self::class, 'manage_post' ), 'permission_callback' => array( Security::class, 'check_nonce' ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/ics/(?P<token>[a-f0-9]{64})',
			array( 'methods' => 'GET', 'callback' => array( self::class, 'ics' ), 'permission_callback' => $open )
		);
	}

	public static function nonce(): \WP_REST_Response {
		$r = new \WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	public static function services(): array {
		return Repo::catalog();
	}

	/**
	 * Praktikandid, kes teenust teevad, koos nende juures kehtiva kestuse ja hinnaga.
	 * "any" on vahemik valiku "Ükskõik kes" jaoks.
	 */
	public static function staff( \WP_REST_Request $req ): array {
		$service = Repo::combo( (string) $req['service'] );
		if ( ! $service || $service['hidden'] ) {
			return array( 'staff' => array(), 'any' => null );
		}
		$out = array();
		$dur = array();
		$pri = array();
		foreach ( Repo::staff_for_services( Repo::ids_of( $service ) ) as $s ) {
			$t     = Repo::service_terms( $service, $s );
			$dur[] = $t['duration'];
			$pri[] = $t['price_cents'];
			$out[] = array(
				'id'             => $s['id'],
				'name'           => $s['name'],
				'photo_url'      => $s['photo_url'],
				'level'          => $s['level_name'],
				'discount_pct'   => $t['price_cents'] < $service['price_cents'] ? $s['discount_pct'] : 0,
				'duration_label' => Settings::duration( $t['duration'] ),
				'price_label'    => Settings::money( $t['price_cents'] ),
			);
		}
		$any = null;
		if ( $out ) {
			$any = array(
				'duration_label' => min( $dur ) === max( $dur ) ? Settings::duration( $dur[0] ) : Settings::duration( min( $dur ) ) . ' – ' . Settings::duration( max( $dur ) ),
				'price_label'    => min( $pri ) === max( $pri ) ? Settings::money( $pri[0] ) : Settings::money( min( $pri ) ) . ' – ' . Settings::money( max( $pri ) ),
			);
		}
		return array( 'staff' => $out, 'any' => $any );
	}

	private static function staff_ids( int $service_id, int $staff ): ?array {
		return $staff ? array( $staff ) : null;
	}

	/** Mitu vaba aega on kuu igal päeval. */
	public static function days( \WP_REST_Request $req ) {
		$rl = Security::rate_limit( 'read', 300, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		$service = Repo::combo( (string) $req['service'] );
		if ( ! $service || $service['hidden'] ) {
			return new \WP_Error( 'sb_service', __( 'Teenust ei leitud.', 'sasipea-broneering' ), array( 'status' => 404 ) );
		}
		$staff = (int) $req['staff'];
		if ( $staff && array_diff( Repo::ids_of( $service ), Repo::staff_service_ids( $staff ) ) ) {
			return array();
		}
		$first = new \DateTimeImmutable( $req['month'] . '-01', Settings::tz() );
		$out   = array();
		for ( $d = $first; $d->format( 'm' ) === $first->format( 'm' ); $d = $d->modify( '+1 day' ) ) {
			$date        = $d->format( 'Y-m-d' );
			$slots       = Availability::slots( Repo::availability_input( $service, self::staff_ids( $service['id'], $staff ), $date ) );
			$out[ $date ] = count( $slots );
		}
		return $out;
	}

	public static function slots( \WP_REST_Request $req ) {
		$rl = Security::rate_limit( 'read', 300, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		$service = Repo::combo( (string) $req['service'] );
		if ( ! $service || $service['hidden'] ) {
			return new \WP_Error( 'sb_service', __( 'Teenust ei leitud.', 'sasipea-broneering' ), array( 'status' => 404 ) );
		}
		$staff   = (int) $req['staff'];
		$exclude = 0;
		if ( $req['exclude'] ) {
			$b       = Repo::booking_by_token( (string) $req['exclude'] );
			$exclude = $b ? (int) $b['id'] : 0;
		}
		$slots = Availability::slots( Repo::availability_input( $service, self::staff_ids( $service['id'], $staff ), (string) $req['date'], $exclude ) );
		return array_map( fn( $s ) => array( 'time' => $s['time'], 'start' => $s['start'] ), $slots );
	}

	private static function str( \WP_REST_Request $req, string $k, int $max = 190 ): string {
		return mb_substr( trim( sanitize_text_field( (string) $req->get_param( $k ) ) ), 0, $max );
	}

	public static function create( \WP_REST_Request $req ) {
		// Honeypot: päris inimene välja "website" ei näe ega täida.
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return new \WP_Error( 'sb_spam', __( 'Broneering ebaõnnestus.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		// Liiga kiire täitmine (alla 4 sekundi) viitab robotile.
		$elapsed = (int) $req->get_param( 'elapsed' );
		if ( $elapsed > 0 && $elapsed < 4 ) {
			return new \WP_Error( 'sb_spam', __( 'Broneering ebaõnnestus.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$rl = Security::rate_limit( 'book', 8, HOUR_IN_SECONDS );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$client = array(
			'first_name' => self::str( $req, 'first_name', 100 ),
			'last_name'  => self::str( $req, 'last_name', 100 ),
			'phone'      => Security::clean_phone( self::str( $req, 'phone', 40 ) ),
			'email'      => sanitize_email( (string) $req->get_param( 'email' ) ),
		);
		$errors = array();
		if ( '' === $client['first_name'] ) {
			$errors['first_name'] = __( 'Sisesta eesnimi.', 'sasipea-broneering' );
		}
		if ( '' === $client['last_name'] ) {
			$errors['last_name'] = __( 'Sisesta perekonnanimi.', 'sasipea-broneering' );
		}
		if ( ! preg_match( '/^\+\d{7,15}$/', $client['phone'] ) ) {
			$errors['phone'] = __( 'Sisesta telefoninumber, näiteks 5123 4567.', 'sasipea-broneering' );
		}
		if ( ! is_email( $client['email'] ) ) {
			$errors['email'] = __( 'Sisesta korrektne e-posti aadress.', 'sasipea-broneering' );
		}
		if ( ! rest_sanitize_boolean( $req->get_param( 'privacy' ) ) ) {
			$errors['privacy'] = __( 'Broneerimiseks pead nõustuma privaatsuspoliitikaga.', 'sasipea-broneering' );
		}
		if ( $errors ) {
			return new \WP_Error( 'sb_invalid', __( 'Palun paranda vormi vead.', 'sasipea-broneering' ), array( 'status' => 400, 'fields' => $errors ) );
		}

		$res = BookingService::create(
			array(
				'service_id' => preg_match( '/^\d+(,\d+)*$/', (string) $req->get_param( 'service' ) ) ? (string) $req->get_param( 'service' ) : '',
				'staff_id'   => (int) $req->get_param( 'staff' ),
				'start'      => (int) $req->get_param( 'start' ),
				'client'     => $client,
				'comment'    => mb_substr( sanitize_textarea_field( (string) $req->get_param( 'comment' ) ), 0, 1000 ),
				'consents'   => array(
					'privacy' => true,
					'models'  => rest_sanitize_boolean( $req->get_param( 'models' ) ),
					'social'  => rest_sanitize_boolean( $req->get_param( 'social' ) ),
				),
				'source'     => 'online',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::summary( $res['booking'], $res['token'] );
	}

	/** Kliendile näidatav kokkuvõte. */
	public static function summary( array $b, string $token ): array {
		$set   = Settings::get();
		$start = ( new \DateTimeImmutable( $b['start_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( Settings::tz() );
		$can   = in_array( $b['status'], Repo::ACTIVE, true ) && Repo::ts( $b['start_utc'] ) - time() >= (int) $set['cancel_hours'] * HOUR_IN_SECONDS;
		return array(
			'service'        => $b['service_name'],
			// Mitme teenusega broneeringul kõik id-d komaga ("3,7"), et aja muutmisel otsitaks vaba aega kogu kestuse järgi.
			'service_id'     => ! empty( $b['service_ids'] ) ? (string) $b['service_ids'] : (int) $b['service_id'],
			'staff'          => $b['staff_name'],
			'staff_id'       => (int) $b['staff_id'],
			'date'           => $start->format( 'Y-m-d' ),
			'date_label'     => \SB\Format::date_long( $start ),
			'time'           => $start->format( 'H:i' ),
			'end_time'       => \SB\Format::local( $b['end_utc'] )->format( 'H:i' ),
			'price'          => Settings::money( (int) $b['price_cents'] ),
			'material_extra' => (bool) $b['material_extra'],
			'status'         => $b['status'],
			'status_label'   => Repo::STATUSES[ $b['status'] ] ?? $b['status'],
			'name'           => trim( $b['first_name'] . ' ' . $b['last_name'] ),
			'token'          => $token,
			'manage_url'     => Settings::booking_url( array( 'halda' => $token ) ),
			'ics_url'        => rest_url( self::NS . '/ics/' . $token ),
			'can_change'     => $can,
			'cancel_hours'   => (int) $set['cancel_hours'],
			'address'        => $set['salon_address'],
			'note'           => $set['confirmation_note'],
		);
	}

	public static function manage_get( \WP_REST_Request $req ) {
		$rl = Security::rate_limit( 'manage', 60, 600 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		$b = Repo::booking_by_token( (string) $req['token'] );
		if ( ! $b ) {
			return new \WP_Error( 'sb_404', __( 'Broneeringut ei leitud. Link võib olla vigane.', 'sasipea-broneering' ), array( 'status' => 404 ) );
		}
		return self::summary( $b, (string) $req['token'] );
	}

	/** action=cancel või action=move (start, staff). */
	public static function manage_post( \WP_REST_Request $req ) {
		$rl = Security::rate_limit( 'manage', 60, 600 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}
		$token = (string) $req['token'];
		$b     = Repo::booking_by_token( $token );
		if ( ! $b ) {
			return new \WP_Error( 'sb_404', __( 'Broneeringut ei leitud.', 'sasipea-broneering' ), array( 'status' => 404 ) );
		}
		$summary = self::summary( $b, $token );
		if ( ! $summary['can_change'] ) {
			return new \WP_Error(
				'sb_late',
				/* translators: %d: hours */
				sprintf( __( 'Broneeringut saab muuta või tühistada kuni %d tundi enne algust. Palun helista meile.', 'sasipea-broneering' ), $summary['cancel_hours'] ),
				array( 'status' => 403 )
			);
		}
		$action = (string) $req->get_param( 'action' );
		\SB\Mailer::$known_token = $token;
		if ( 'cancel' === $action ) {
			$res = BookingService::update( (int) $b['id'], array( 'status' => 'cancelled_client' ), false );
		} elseif ( 'move' === $action ) {
			$res = BookingService::update(
				(int) $b['id'],
				array(
					'start'    => (int) $req->get_param( 'start' ),
					'staff_id' => (int) $req->get_param( 'staff' ) ?: (int) $b['staff_id'],
				),
				false
			);
		} else {
			return new \WP_Error( 'sb_action', 'Unknown action', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::summary( $res, $token );
	}

	public static function ics( \WP_REST_Request $req ) {
		$b = Repo::booking_by_token( (string) $req['token'] );
		if ( ! $b ) {
			return new \WP_Error( 'sb_404', 'Not found', array( 'status' => 404 ) );
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sasipea-broneering.ics"' );
		echo Ics::booking( $b ); // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar, mitte HTML.
		exit;
	}
}
