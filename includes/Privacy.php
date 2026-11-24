<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/** WordPressi isikuandmete eksportija ja kustutaja (Tööriistad → Ekspordi/Kustuta isikuandmed). */
final class Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	public static function register_exporter( array $e ): array {
		$e['sasipea-broneering'] = array( 'exporter_friendly_name' => __( 'Sasipea broneeringud', 'sasipea-broneering' ), 'callback' => array( self::class, 'export' ) );
		return $e;
	}

	public static function register_eraser( array $e ): array {
		$e['sasipea-broneering'] = array( 'eraser_friendly_name' => __( 'Sasipea broneeringud', 'sasipea-broneering' ), 'callback' => array( self::class, 'erase' ) );
		return $e;
	}

	private static function clients( string $email ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'clients' ) . ' WHERE email = %s', $email ), ARRAY_A );
	}

	public static function export( string $email ): array {
		global $wpdb;
		$items = array();
		foreach ( self::clients( $email ) as $c ) {
			$items[] = array(
				'group_id'    => 'sb-client',
				'group_label' => __( 'Broneerimise klient', 'sasipea-broneering' ),
				'item_id'     => 'sb-client-' . $c['id'],
				'data'        => array(
					array( 'name' => 'Nimi', 'value' => trim( $c['first_name'] . ' ' . $c['last_name'] ) ),
					array( 'name' => 'Telefon', 'value' => $c['phone'] ),
					array( 'name' => 'E-post', 'value' => $c['email'] ),
					array( 'name' => 'Märkmed', 'value' => (string) $c['notes'] ),
				),
			);
			foreach ( Repo::client_history( (int) $c['id'] ) as $b ) {
				$items[] = array(
					'group_id'    => 'sb-bookings',
					'group_label' => __( 'Broneeringud', 'sasipea-broneering' ),
					'item_id'     => 'sb-booking-' . $b['id'],
					'data'        => array(
						array( 'name' => 'Aeg', 'value' => Format::local( $b['start_utc'] )->format( 'd.m.Y H:i' ) ),
						array( 'name' => 'Teenus', 'value' => $b['service_name'] ),
						array( 'name' => 'Staatus', 'value' => Repo::STATUSES[ $b['status'] ] ?? $b['status'] ),
						array( 'name' => 'Kommentaar', 'value' => (string) $b['comment'] ),
					),
				);
			}
			$consents = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'consents' ) . ' WHERE client_id = %d', $c['id'] ), ARRAY_A );
			foreach ( $consents as $k ) {
				$items[] = array(
					'group_id'    => 'sb-consents',
					'group_label' => __( 'Nõusolekud', 'sasipea-broneering' ),
					'item_id'     => 'sb-consent-' . $k['id'],
					'data'        => array(
						array( 'name' => 'Tüüp', 'value' => $k['type'] ),
						array( 'name' => 'Antud', 'value' => $k['granted'] ? 'jah' : 'ei' ),
						array( 'name' => 'Teksti versioon', 'value' => $k['text_version'] ),
						array( 'name' => 'Aeg', 'value' => $k['created_at'] . ' UTC' ),
					),
				);
			}
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( string $email ): array {
		$n = 0;
		foreach ( self::clients( $email ) as $c ) {
			Cron::anonymize_client( (int) $c['id'] );
			++$n;
		}
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
