<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Õigused:
 *  - sb_manage_settings: teenused, praktikandid, seaded (admin)
 *  - sb_manage_bookings: kõik broneeringud ja päevaplaan (admin, juhendaja)
 *  - sb_view_own: ainult enda graafik ja broneeringud (praktikant)
 */
final class Roles {

	public const PRAKTIKANT = 'sb_praktikant';

	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'sb_manage_settings', 'sb_manage_bookings', 'sb_view_own' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// OLETUS: modellide plugina juhendaja roll sisaldab nimes või slugis sõna "juhendaja".
		// Täpne slug selgub, kui sasipea-modellid kood on käes.
		foreach ( self::juhendaja_roles() as $slug ) {
			$role = get_role( $slug );
			if ( $role ) {
				$role->add_cap( 'sb_manage_bookings' );
				$role->add_cap( 'sb_view_own' );
			}
		}

		if ( ! get_role( self::PRAKTIKANT ) ) {
			add_role(
				self::PRAKTIKANT,
				__( 'Praktikant', 'sasipea-broneering' ),
				array(
					'read'        => true,
					'sb_view_own' => true,
				)
			);
		}
	}

	/** @return string[] */
	public static function juhendaja_roles(): array {
		$out = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( stripos( $slug, 'juhendaja' ) !== false || stripos( (string) $name, 'juhendaja' ) !== false ) {
				$out[] = $slug;
			}
		}
		return (array) apply_filters( 'sb_juhendaja_roles', $out );
	}

	/** Praktikandi rida, mis on seotud praeguse WP kasutajaga. */
	public static function current_staff_id(): ?int {
		global $wpdb;
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return null;
		}
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Install::table( 'staff' ) . ' WHERE user_id = %d', $uid ) );
		return $id ? (int) $id : null;
	}
}
