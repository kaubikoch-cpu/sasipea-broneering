<?php
namespace SB\Rest;

use SB\BookingService;
use SB\Format;
use SB\Install;
use SB\Repo;
use SB\Roles;
use SB\Security;
use SB\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Päevaplaani REST API. Nõuab sisselogimist ja REST nonce'i (WordPressi cookie auth).
 */
final class AdminController {

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function can_view(): bool {
		return current_user_can( 'sb_manage_bookings' ) || current_user_can( 'sb_view_own' );
	}

	public static function can_manage(): bool {
		return current_user_can( 'sb_manage_bookings' );
	}

	public static function routes(): void {
		$ns = PublicController::NS;
		register_rest_route(
			$ns,
			'/admin/day',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'day' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => array(
					'date' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'days' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 7 ),
				),
			)
		);
		register_rest_route( $ns, '/admin/bookings', array( 'methods' => 'POST', 'callback' => array( self::class, 'create' ), 'permission_callback' => array( self::class, 'can_manage' ) ) );
		register_rest_route(
			$ns,
			'/admin/bookings/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'get' ), 'permission_callback' => array( self::class, 'can_view' ) ),
				array( 'methods' => 'PATCH', 'callback' => array( self::class, 'update' ), 'permission_callback' => array( self::class, 'can_manage' ) ),
			)
		);
		register_rest_route( $ns, '/admin/blocks', array( 'methods' => 'POST', 'callback' => array( self::class, 'block_create' ), 'permission_callback' => array( self::class, 'can_manage' ) ) );
		register_rest_route( $ns, '/admin/blocks/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( self::class, 'block_delete' ), 'permission_callback' => array( self::class, 'can_manage' ) ) );
		register_rest_route(
			$ns,
			'/admin/clients',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'clients' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array( 'q' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	/** Praktikant näeb ainult enda veergu. */
	private static function visible_staff(): array {
		$all = Repo::staff_list( true );
		if ( self::can_manage() ) {
			return $all;
		}
		if ( self::trainee_may( 'others' ) ) {
			return $all;
		}
		$own = Roles::current_staff_id();
		return array_values( array_filter( $all, fn( $s ) => $s['id'] === $own ) );
	}

	/** Kas juhendaja on lubanud praktikandil seda näha (Seaded → Praktikandi vaade). */
	private static function trainee_may( string $what ): bool {
		return ! empty( Settings::get()['trainee_view'][ $what ] );
	}

	/** Broneering praktikandi pilgu läbi: ainult see, mis on lubatud. Võõra broneeringu klienti ei näidata. */
	private static function booking_for_trainee( array $b ): array {
		$own = (int) $b['staff_id'] === Roles::current_staff_id();
		$out = self::booking_out( $b, $own && self::trainee_may( 'details' ) );
		if ( ! $own ) {
			$out['client'] = '';
			$out['phone']  = '';
			$out['price']  = '';
			return $out;
		}
		if ( ! self::trainee_may( 'phone' ) ) {
			$out['phone'] = '';
		}
		if ( ! self::trainee_may( 'price' ) ) {
			$out['price'] = '';
		}
		unset( $out['client_id'], $out['model_id'] );
		return $out;
	}

	public static function booking_out( array $b, bool $full = true ): array {
		$s = Format::local( $b['start_utc'] );
		$e = Format::local( $b['end_utc'] );
		$out = array(
			'id'           => (int) $b['id'],
			'staff_id'     => (int) $b['staff_id'],
			'service_id'   => (int) $b['service_id'],
			'service'      => $b['service_name'],
			'start'        => Repo::ts( $b['start_utc'] ),
			'end'          => Repo::ts( $b['end_utc'] ),
			'date'         => $s->format( 'Y-m-d' ),
			'start_min'    => (int) $s->format( 'G' ) * 60 + (int) $s->format( 'i' ),
			'end_min'      => (int) $e->format( 'G' ) * 60 + (int) $e->format( 'i' ),
			'time'         => $s->format( 'H:i' ) . '–' . $e->format( 'H:i' ),
			'status'       => $b['status'],
			'status_label' => Repo::STATUSES[ $b['status'] ] ?? $b['status'],
			'client'       => trim( $b['first_name'] . ' ' . $b['last_name'] ),
			'phone'        => $b['phone'],
			'price'        => Settings::money( (int) $b['price_cents'] ),
			'material'     => (bool) $b['material_extra'],
			'source'       => $b['source'],
		);
		if ( $full ) {
			$out['email']     = $b['email'];
			$out['comment']   = (string) $b['comment'];
			$out['client_id'] = (int) $b['client_id'];
			$out['model_id']  = $b['model_id'] ? (int) $b['model_id'] : null;
		}
		return $out;
	}

	public static function day( \WP_REST_Request $req ) {
		$date  = (string) $req['date'];
		$days  = (int) $req['days'];
		$staff = self::visible_staff();
		$ids   = array_column( $staff, 'id' );
		$first = new \DateTimeImmutable( $date, Settings::tz() );
		$out   = array(
			'date'     => $date,
			'staff'    => $staff,
			'step'     => (int) Settings::get()['slot_step'],
			'statuses' => Repo::STATUSES,
			'days'     => array(),
			'can_manage' => self::can_manage(),
		);
		for ( $i = 0; $i < $days; $i++ ) {
			$d             = $first->modify( "+$i day" )->format( 'Y-m-d' );
			[ $from, $to ] = Repo::day_bounds( $d );
			$work          = array();
			foreach ( $ids as $sid ) {
				$work[ $sid ] = Repo::work_ranges( $sid, $d );
			}
			$bookings = array();
			foreach ( Repo::bookings_between( $from, $to, 0, false ) as $b ) {
				if ( in_array( (int) $b['staff_id'], $ids, true ) ) {
					$bookings[] = self::can_manage() ? self::booking_out( $b ) : self::booking_for_trainee( $b );
				}
			}
			$blocks = array();
			foreach ( Repo::blocks_between( $from, $to ) as $bl ) {
				if ( $bl['staff_id'] && ! in_array( (int) $bl['staff_id'], $ids, true ) ) {
					continue;
				}
				$s        = Format::local( $bl['start_utc'] );
				$e        = Format::local( $bl['end_utc'] );
				$blocks[] = array(
					'id'        => (int) $bl['id'],
					'staff_id'  => $bl['staff_id'] ? (int) $bl['staff_id'] : null,
					'start_min' => $s->format( 'Y-m-d' ) < $d ? 0 : (int) $s->format( 'G' ) * 60 + (int) $s->format( 'i' ),
					'end_min'   => $e->format( 'Y-m-d' ) > $d ? 1440 : (int) $e->format( 'G' ) * 60 + (int) $e->format( 'i' ),
					'reason'    => (string) $bl['reason'],
				);
			}
			$out['days'][] = array(
				'date'     => $d,
				'label'    => Format::date_long( new \DateTimeImmutable( $d, Settings::tz() ) ),
				'opening'  => Settings::opening_for( $d ),
				'work'     => $work,
				'bookings' => $bookings,
				'blocks'   => $blocks,
			);
		}
		if ( self::can_manage() ) {
			$out['services'] = Repo::catalog( true );
		}
		return $out;
	}

	public static function get( \WP_REST_Request $req ) {
		$b = Repo::booking( (int) $req['id'] );
		if ( ! $b ) {
			return new \WP_Error( 'sb_404', 'Not found', array( 'status' => 404 ) );
		}
		if ( ! self::can_manage() && (int) $b['staff_id'] !== Roles::current_staff_id() && ! self::trainee_may( 'others' ) ) {
			return new \WP_Error( 'sb_forbidden', 'Forbidden', array( 'status' => 403 ) );
		}
		$out            = self::can_manage() ? self::booking_out( $b, true ) : self::booking_for_trainee( $b );
		$out['history'] = array();
		if ( self::can_manage() ) {
			foreach ( array_slice( Repo::client_history( (int) $b['client_id'] ), 0, 10 ) as $h ) {
				$out['history'][] = array(
					'date'    => Format::local( $h['start_utc'] )->format( 'd.m.Y H:i' ),
					'service' => $h['service_name'],
					'staff'   => $h['staff_name'],
					'status'  => Repo::STATUSES[ $h['status'] ] ?? $h['status'],
				);
			}
		}
		return $out;
	}

	/** "Y-m-d" + "HH:MM" kohalikus ajas -> ajatempel. */
	private static function local_ts( string $date, string $time ): int {
		return ( new \DateTimeImmutable( $date . ' ' . $time, Settings::tz() ) )->getTimestamp();
	}

	private static function start_from( \WP_REST_Request $req ): ?int {
		if ( $req->get_param( 'start' ) ) {
			return (int) $req->get_param( 'start' );
		}
		$date = (string) $req->get_param( 'date' );
		$time = (string) $req->get_param( 'time' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
			return self::local_ts( $date, $time );
		}
		return null;
	}

	public static function create( \WP_REST_Request $req ) {
		$start = self::start_from( $req );
		if ( ! $start ) {
			return new \WP_Error( 'sb_invalid', __( 'Vali aeg.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$client = array(
			'first_name' => sanitize_text_field( (string) $req->get_param( 'first_name' ) ),
			'last_name'  => sanitize_text_field( (string) $req->get_param( 'last_name' ) ),
			'phone'      => Security::clean_phone( sanitize_text_field( (string) $req->get_param( 'phone' ) ) ),
			'email'      => sanitize_email( (string) $req->get_param( 'email' ) ),
		);
		if ( '' === $client['first_name'] ) {
			return new \WP_Error( 'sb_invalid', __( 'Sisesta kliendi nimi.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$res = BookingService::create(
			array(
				'service_id' => (int) $req->get_param( 'service_id' ),
				'staff_id'   => (int) $req->get_param( 'staff_id' ),
				'start'      => $start,
				'client'     => $client,
				'comment'    => sanitize_textarea_field( (string) $req->get_param( 'comment' ) ),
				'consents'   => array(),
				'source'     => 'admin',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::booking_out( $res['booking'] );
	}

	public static function update( \WP_REST_Request $req ) {
		$ch    = array();
		$start = self::start_from( $req );
		if ( $start ) {
			$ch['start'] = $start;
		}
		foreach ( array( 'staff_id', 'service_id' ) as $k ) {
			if ( null !== $req->get_param( $k ) ) {
				$ch[ $k ] = (int) $req->get_param( $k );
			}
		}
		if ( null !== $req->get_param( 'status' ) ) {
			$ch['status'] = sanitize_key( (string) $req->get_param( 'status' ) );
		}
		if ( null !== $req->get_param( 'comment' ) ) {
			$ch['comment'] = sanitize_textarea_field( (string) $req->get_param( 'comment' ) );
		}
		$res = BookingService::update( (int) $req['id'], $ch, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::booking_out( $res );
	}

	public static function block_create( \WP_REST_Request $req ) {
		global $wpdb;
		$date = (string) $req->get_param( 'date' );
		$from = (string) $req->get_param( 'from' );
		$to   = (string) $req->get_param( 'to' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $from ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $to ) ) {
			return new \WP_Error( 'sb_invalid', __( 'Vigane aeg.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$to_date = (string) ( $req->get_param( 'to_date' ) ?: $date );
		$s       = self::local_ts( $date, $from );
		$e       = self::local_ts( $to_date, $to );
		if ( $e <= $s ) {
			return new \WP_Error( 'sb_invalid', __( 'Lõpp peab olema pärast algust.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$staff = (int) $req->get_param( 'staff_id' );
		$wpdb->insert(
			Install::table( 'blocks' ),
			array(
				'staff_id'   => $staff ?: null,
				'start_utc'  => Repo::utc( $s ),
				'end_utc'    => Repo::utc( $e ),
				'reason'     => sanitize_text_field( (string) $req->get_param( 'reason' ) ),
				'created_by' => get_current_user_id(),
			)
		);
		return array( 'id' => (int) $wpdb->insert_id );
	}

	public static function block_delete( \WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'blocks' ), array( 'id' => (int) $req['id'] ) );
		return array( 'deleted' => true );
	}

	public static function clients( \WP_REST_Request $req ) {
		$q = sanitize_text_field( (string) $req['q'] );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}
		return array_map(
			fn( $c ) => array(
				'id'         => (int) $c['id'],
				'first_name' => $c['first_name'],
				'last_name'  => $c['last_name'],
				'phone'      => $c['phone'],
				'email'      => $c['email'],
			),
			Repo::search_clients( $q, 10 )
		);
	}
}
