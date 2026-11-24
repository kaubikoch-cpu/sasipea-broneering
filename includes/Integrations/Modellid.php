<?php
namespace SB\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Sidumine pluginaga sasipea-modellid (modellide andmebaas samal WordPressi lehel).
 *
 * Modellid on privaatne postitüüp sam_modell, andmed post meta väljadel (_sam_email, _sam_phone,
 * _sam_status, _sam_last_contact) ja kategooriad taksonoomiates (sam_teenus, sam_juuksed, sam_pikkus).
 * Loeme ja kirjutame otse sama lehe andmebaasist; andmed lehelt välja ei liigu.
 */
final class Modellid {

	public static function init(): void {
		// Modellide plugin suunab juhendaja (ilma edit_posts õiguseta) kõigilt wp-admini aadressidelt
		// oma töölauale, ka admin-post.php pealt. Meie vormid salvestavad admin-post.php kaudu,
		// seega lubame need läbi (õigust kontrollib iga käsitleja ise).
		add_action(
			'admin_init',
			static function (): void {
				global $pagenow;
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( 'admin-post.php' === $pagenow && str_starts_with( $action, 'sb_' ) ) {
					remove_action( 'admin_init', 'sam_block_admin' );
				}
			},
			0
		);
	}

	public static function active(): bool {
		return defined( 'SAM_CPT' ) && function_exists( 'sam_save_model' );
	}

	/** Telefon võrdlemiseks: ainult numbrid, ilma Eesti suunakoodita. */
	public static function phone_key( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		if ( str_starts_with( $d, '00372' ) ) {
			$d = substr( $d, 5 );
		} elseif ( str_starts_with( $d, '372' ) && strlen( $d ) > 8 ) {
			$d = substr( $d, 3 );
		}
		return strlen( $d ) >= 7 ? $d : '';
	}

	/**
	 * Millised modellide teenusekategooriad sobivad broneeringu teenusega.
	 * Võrdleb sõnatüvesid: "Juukselõikus" sobib kategooriaga "Lõikus", "Värvimine, pikad juuksed" kategooriaga "Värvimine".
	 *
	 * @param array<int|string, string> $terms kategooria ID => nimi
	 * @return array<int, int|string> sobivate kategooriate võtmed
	 */
	public static function match_terms( array $terms, string $text ): array {
		$text = mb_strtolower( $text );
		$out  = array();
		foreach ( $terms as $key => $name ) {
			foreach ( preg_split( '/[^\p{L}]+/u', mb_strtolower( (string) $name ) ) as $word ) {
				if ( mb_strlen( $word ) < 4 ) {
					continue;
				}
				if ( false !== mb_strpos( $text, mb_substr( $word, 0, 5 ) ) ) {
					$out[] = $key;
					break;
				}
			}
		}
		return $out;
	}

	/** Modelli ID e-posti või telefoni järgi või null. */
	public static function find_model_id( string $email, string $phone ): ?int {
		if ( ! self::active() ) {
			$id = apply_filters( 'sb_find_model_id', null, $email, $phone );
			return $id ? (int) $id : null;
		}
		global $wpdb;
		if ( '' !== $email ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sam_email'
					 WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value = %s ORDER BY p.ID DESC LIMIT 1",
					SAM_CPT,
					$email
				)
			);
			if ( $id ) {
				return $id;
			}
		}
		$key = self::phone_key( $phone );
		if ( '' === $key ) {
			return null;
		}
		// Telefonid on modellide juures vabas vormis ("5123 4567", "+372 5123 4567"), seega võrdleme numbreid.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sam_phone'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value <> '' ORDER BY p.ID DESC",
				SAM_CPT
			)
		);
		foreach ( $rows as $r ) {
			if ( self::phone_key( (string) $r->meta_value ) === $key ) {
				return (int) $r->ID;
			}
		}
		return null;
	}

	/** Modellide teenusekategooriad: ID => nimi. */
	public static function service_terms(): array {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( sam_terms( 'sam_teenus' ) as $t ) {
			$out[ (int) $t->term_id ] = $t->name;
		}
		return $out;
	}

	/**
	 * Otsib modelle nime ja teenusekategooriate järgi. Arhiveeritud modelle ei näidata.
	 * Ees on need, kellega pole kõige kauem ühendust võetud.
	 *
	 * @param int[] $term_ids sam_teenus kategooriad; tühi = kõik
	 */
	public static function search( string $q, array $term_ids = array(), int $limit = 40 ): array {
		if ( ! self::active() ) {
			return array();
		}
		$args = array(
			'post_type'              => SAM_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => 300,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		if ( $term_ids ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'sam_teenus',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', $term_ids ),
				),
			);
		}
		$rows = array();
		foreach ( get_posts( $args ) as $id ) {
			$status = get_post_meta( $id, '_sam_status', true ) ?: 'uus';
			if ( 'arhiiv' === $status ) {
				continue;
			}
			$rows[] = array(
				'id'   => (int) $id,
				'last' => (int) get_post_meta( $id, '_sam_last_contact', true ),
				'st'   => $status,
			);
		}
		usort( $rows, static fn( $a, $b ) => $a['last'] <=> $b['last'] ?: $a['id'] <=> $b['id'] );
		$names = static function ( int $id, string $tax ): string {
			$t = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
			return is_wp_error( $t ) ? '' : implode( ', ', $t );
		};
		$statuses = sam_statuses();
		$out      = array();
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$id    = $r['id'];
			$out[] = array(
				'id'           => $id,
				'name'         => get_the_title( $id ),
				'phone'        => (string) get_post_meta( $id, '_sam_phone', true ),
				'email'        => (string) get_post_meta( $id, '_sam_email', true ),
				'status'       => $statuses[ $r['st'] ] ?? $r['st'],
				'services'     => $names( $id, 'sam_teenus' ),
				'hair'         => trim( $names( $id, 'sam_juuksed' ) . ', ' . mb_strtolower( $names( $id, 'sam_pikkus' ) ), ', ' ),
				'availability' => (string) get_post_meta( $id, '_sam_availability', true ),
				'last_contact' => $r['last'] ? wp_date( 'd.m.Y', $r['last'] ) : '',
				'url'          => (string) self::model_url( $id ),
			);
		}
		return $out;
	}

	/** Modelliga võeti ühendust (tehti broneering): uuendab viimase kontakti kuupäeva. */
	public static function touch( int $model_id ): void {
		if ( self::active() && $model_id && SAM_CPT === get_post_type( $model_id ) ) {
			update_post_meta( $model_id, '_sam_last_contact', time() );
		}
	}

	/** Klient soovis, et ta lisataks modellide andmebaasi. Tagastab modelli ID või null. */
	public static function opt_in( int $client_id, array $client ): ?int {
		do_action( 'sb_model_db_optin', $client_id, $client );
		if ( ! self::active() ) {
			return null;
		}
		$id = self::find_model_id( (string) ( $client['email'] ?? '' ), (string) ( $client['phone'] ?? '' ) );
		if ( ! $id ) {
			$res = sam_save_model(
				array(
					'name'         => trim( ( $client['first_name'] ?? '' ) . ' ' . ( $client['last_name'] ?? '' ) ),
					'email'        => (string) ( $client['email'] ?? '' ),
					'phone'        => (string) ( $client['phone'] ?? '' ),
					'status'       => 'uus',
					'consent'      => time(),
					'last_contact' => time(),
					'source'       => 'broneering',
				)
			);
			if ( is_wp_error( $res ) ) {
				return null;
			}
			$id = (int) $res;
		}
		global $wpdb;
		$wpdb->update( \SB\Install::table( 'clients' ), array( 'model_id' => $id ), array( 'id' => $client_id ) );
		return $id;
	}

	/** Link modelli kaardile juhendaja töölaual. */
	public static function model_url( ?int $model_id ): ?string {
		if ( ! $model_id ) {
			return null;
		}
		if ( self::active() && function_exists( 'sam_dashboard_url' ) ) {
			return sam_dashboard_url(
				array(
					'vaade' => 'modell',
					'id'    => $model_id,
				)
			);
		}
		$url = apply_filters( 'sb_model_admin_url', null, $model_id );
		return $url ? (string) $url : null;
	}
}
