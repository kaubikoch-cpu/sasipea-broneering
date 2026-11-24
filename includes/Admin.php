<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Admini lehed: päevaplaan, praktikandid, teenused, kliendid, seaded.
 */
final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		foreach ( array( 'save_service', 'save_category', 'delete_service', 'save_staff', 'add_exception', 'delete_exception', 'save_settings', 'save_client', 'save_month' ) as $a ) {
			add_action( 'admin_post_sb_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	public static function menu(): void {
		add_menu_page( __( 'Broneeringud', 'sasipea-broneering' ), __( 'Broneeringud', 'sasipea-broneering' ), 'sb_view_own', 'sb-planner', array( self::class, 'page_planner' ), 'dashicons-calendar-alt', 3 );
		add_submenu_page( 'sb-planner', __( 'Päevaplaan', 'sasipea-broneering' ), __( 'Päevaplaan', 'sasipea-broneering' ), 'sb_view_own', 'sb-planner', array( self::class, 'page_planner' ) );
		add_submenu_page( 'sb-planner', __( 'Töögraafik', 'sasipea-broneering' ), __( 'Töögraafik', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-month', array( self::class, 'page_month' ) );
		add_submenu_page( 'sb-planner', __( 'Kliendid', 'sasipea-broneering' ), __( 'Kliendid', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-clients', array( self::class, 'page_clients' ) );
		add_submenu_page( 'sb-planner', __( 'Praktikandid', 'sasipea-broneering' ), __( 'Praktikandid', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-staff', array( self::class, 'page_staff' ) );
		add_submenu_page( 'sb-planner', __( 'Teenused', 'sasipea-broneering' ), __( 'Teenused', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-services', array( self::class, 'page_services' ) );
		add_submenu_page( 'sb-planner', __( 'Seaded', 'sasipea-broneering' ), __( 'Seaded', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-settings', array( self::class, 'page_settings' ) );
	}

	public static function enqueue_planner( bool $in_admin = true ): void {
		wp_enqueue_style( 'sb-planner', SB_URL . 'assets/css/planner.css', array(), SB_VERSION );
		wp_enqueue_script( 'sb-planner', SB_URL . 'assets/js/planner.js', array(), SB_VERSION, true );
		wp_localize_script(
			'sb-planner',
			'SB_PLANNER',
			array(
				'rest'      => esc_url_raw( rest_url( Rest\PublicController::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'canManage' => current_user_can( 'sb_manage_bookings' ),
				'today'     => ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m-d' ),
				'clientUrl' => $in_admin ? admin_url( 'admin.php?page=sb-clients&client=' ) : '',
			)
		);
	}

	private static function guard( string $cap, string $action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( string $page, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'sb_saved' => 1 ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function notice(): void {
		if ( isset( $_GET['sb_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Salvestatud.', 'sasipea-broneering' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['sb_error'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	private static function post( string $k, string $default = '' ): string {
		return isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification -- kontrollitud guard()'is.
	}

	/** "12:00-15:00, 15:30-19:00" -> [[720,900],[930,1140]] */
	public static function parse_ranges( string $s ): array {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', trim( $s ) ) as $part ) {
			if ( preg_match( '/^(\d{1,2})[:.](\d{2})\s*[-–]\s*(\d{1,2})[:.](\d{2})$/', $part, $m ) ) {
				$a = (int) $m[1] * 60 + (int) $m[2];
				$b = (int) $m[3] * 60 + (int) $m[4];
				if ( $b > $a && $b <= 1440 ) {
					$out[] = array( $a, $b );
				}
			}
		}
		usort( $out, fn( $x, $y ) => $x[0] <=> $y[0] );
		return $out;
	}

	public static function ranges_str( array $ranges ): string {
		return implode( ', ', array_map( fn( $r ) => Settings::from_min( (int) $r[0] ) . '-' . Settings::from_min( (int) $r[1] ), $ranges ) );
	}

	/* ================= Päevaplaan ================= */

	public static function page_planner(): void {
		self::enqueue_planner();
		echo '<div class="wrap"><h1>' . esc_html__( 'Päevaplaan', 'sasipea-broneering' ) . '</h1><div id="sb-planner"></div></div>';
	}

	/* ================= Teenused ================= */

	public static function page_services(): void {
		$catalog = Repo::catalog( true );
		$edit    = isset( $_GET['edit'] ) ? Repo::service( (int) $_GET['edit'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap"><h1>' . esc_html__( 'Teenused', 'sasipea-broneering' ) . '</h1>';
		self::notice();

		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Teenus', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Kestus', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Hind', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Järjekord', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $catalog as $cat ) {
			printf(
				'<tr><th colspan="5" style="background:#f0f0f1"><strong>%s</strong>%s</th></tr>',
				esc_html( $cat['name'] ),
				$cat['hidden'] ? ' <em>(' . esc_html__( 'peidetud', 'sasipea-broneering' ) . ')</em>' : ''
			);
			foreach ( $cat['services'] as $s ) {
				printf(
					'<tr><td>%s%s%s</td><td>%s</td><td>%s%s</td><td>%d</td><td><a href="%s">%s</a></td></tr>',
					esc_html( $s['name'] ),
					$s['hidden'] ? ' <em>(' . esc_html__( 'peidetud', 'sasipea-broneering' ) . ')</em>' : '',
					$s['note'] ? '<br><small>' . esc_html( $s['note'] ) . '</small>' : '',
					esc_html( $s['duration_label'] ),
					esc_html( $s['price_label'] ),
					$s['material_extra'] ? ' + ' . esc_html__( 'materjal', 'sasipea-broneering' ) : '',
					(int) $s['sort_order'],
					esc_url( admin_url( 'admin.php?page=sb-services&edit=' . $s['id'] ) ),
					esc_html__( 'Muuda', 'sasipea-broneering' )
				);
			}
		}
		echo '</tbody></table>';

		$s = $edit ?: array( 'id' => 0, 'category_id' => 0, 'name' => '', 'duration' => 60, 'price_cents' => 0, 'material_extra' => false, 'note' => '', 'sort_order' => 0, 'hidden' => false );
		echo '<h2>' . ( $edit ? esc_html__( 'Muuda teenust', 'sasipea-broneering' ) : esc_html__( 'Lisa teenus', 'sasipea-broneering' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_service' );
		echo '<input type="hidden" name="action" value="sb_save_service"><input type="hidden" name="id" value="' . (int) $s['id'] . '">';
		echo '<table class="form-table"><tr><th><label for="sb-cat">' . esc_html__( 'Kategooria', 'sasipea-broneering' ) . '</label></th><td><select id="sb-cat" name="category_id">';
		foreach ( $catalog as $cat ) {
			printf( '<option value="%d"%s>%s</option>', (int) $cat['id'], selected( (int) $cat['id'], (int) $s['category_id'], false ), esc_html( $cat['name'] ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th><label for="sb-name">%s</label></th><td><input id="sb-name" name="name" class="regular-text" required value="%s"></td></tr>', esc_html__( 'Nimi', 'sasipea-broneering' ), esc_attr( $s['name'] ) );
		printf( '<tr><th><label for="sb-dur">%s</label></th><td><input id="sb-dur" name="duration" type="number" min="5" step="5" required value="%d"></td></tr>', esc_html__( 'Kestus (min)', 'sasipea-broneering' ), (int) $s['duration'] );
		printf( '<tr><th><label for="sb-price">%s</label></th><td><input id="sb-price" name="price" inputmode="decimal" required value="%s"></td></tr>', esc_html__( 'Hind (€)', 'sasipea-broneering' ), esc_attr( number_format( $s['price_cents'] / 100, 2, ',', '' ) ) );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="material_extra" value="1"%s> %s</label></td></tr>', esc_html__( 'Materjal', 'sasipea-broneering' ), checked( $s['material_extra'], true, false ), esc_html__( 'Lisandub materjal', 'sasipea-broneering' ) );
		printf( '<tr><th><label for="sb-note">%s</label></th><td><input id="sb-note" name="note" class="large-text" value="%s"></td></tr>', esc_html__( 'Märkus kliendile', 'sasipea-broneering' ), esc_attr( $s['note'] ) );
		printf( '<tr><th><label for="sb-sort">%s</label></th><td><input id="sb-sort" name="sort_order" type="number" value="%d"></td></tr>', esc_html__( 'Järjekord', 'sasipea-broneering' ), (int) $s['sort_order'] );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="hidden" value="1"%s> %s</label></td></tr>', esc_html__( 'Nähtavus', 'sasipea-broneering' ), checked( $s['hidden'], true, false ), esc_html__( 'Peida online-broneeringust', 'sasipea-broneering' ) );
		echo '</table>';
		submit_button( $edit ? __( 'Salvesta', 'sasipea-broneering' ) : __( 'Lisa teenus', 'sasipea-broneering' ) );
		echo '</form>';
		if ( $edit ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'sb_delete_service' );
			echo '<input type="hidden" name="action" value="sb_delete_service"><input type="hidden" name="id" value="' . (int) $s['id'] . '">';
			submit_button( __( 'Kustuta teenus', 'sasipea-broneering' ), 'delete', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'Teenust, millel on broneeringuid, kustutada ei saa. Peida see siis.', 'sasipea-broneering' ) . '</span></form>';
		}

		echo '<h2>' . esc_html__( 'Kategooriad', 'sasipea-broneering' ) . '</h2>';
		foreach ( array_merge( $catalog, array( array( 'id' => 0, 'name' => '', 'sort_order' => count( $catalog ), 'hidden' => false ) ) ) as $cat ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:6px">';
			wp_nonce_field( 'sb_save_category' );
			printf(
				'<input type="hidden" name="action" value="sb_save_category"><input type="hidden" name="id" value="%1$d">
				<label class="screen-reader-text" for="sb-c-%1$d">%2$s</label><input id="sb-c-%1$d" name="name" value="%3$s" placeholder="%4$s" required>
				<label>%5$s <input name="sort_order" type="number" style="width:70px" value="%6$d"></label>
				<label><input type="checkbox" name="hidden" value="1"%7$s> %8$s</label> ',
				(int) $cat['id'],
				esc_html__( 'Kategooria nimi', 'sasipea-broneering' ),
				esc_attr( $cat['name'] ),
				esc_attr__( 'Uus kategooria', 'sasipea-broneering' ),
				esc_html__( 'Järjekord', 'sasipea-broneering' ),
				(int) $cat['sort_order'],
				checked( (bool) $cat['hidden'], true, false ),
				esc_html__( 'peidetud', 'sasipea-broneering' )
			);
			submit_button( $cat['id'] ? __( 'Salvesta', 'sasipea-broneering' ) : __( 'Lisa', 'sasipea-broneering' ), 'secondary small', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	public static function handle_save_service(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_save_service' );
		$data = array(
			'category_id'    => (int) self::post( 'category_id' ),
			'name'           => self::post( 'name' ),
			'duration_min'   => max( 5, (int) self::post( 'duration' ) ),
			'price_cents'    => (int) round( (float) str_replace( ',', '.', self::post( 'price' ) ) * 100 ),
			'material_extra' => self::post( 'material_extra' ) ? 1 : 0,
			'note'           => self::post( 'note' ),
			'sort_order'     => (int) self::post( 'sort_order' ),
			'hidden'         => self::post( 'hidden' ) ? 1 : 0,
		);
		$id = (int) self::post( 'id' );
		if ( $id ) {
			$wpdb->update( Install::table( 'services' ), $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( Install::table( 'services' ), $data );
			$id = (int) $wpdb->insert_id;
			// Uus teenus: vaikimisi teevad seda kõik aktiivsed praktikandid.
			foreach ( Repo::staff_list( true ) as $st ) {
				$wpdb->replace( Install::table( 'staff_services' ), array( 'staff_id' => $st['id'], 'service_id' => $id ) );
			}
		}
		self::back( 'sb-services' );
	}

	public static function handle_delete_service(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_delete_service' );
		$id = (int) self::post( 'id' );
		$n  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Install::table( 'bookings' ) . ' WHERE service_id = %d', $id ) );
		if ( $n ) {
			self::back( 'sb-services', array( 'sb_saved' => null, 'sb_error' => __( 'Teenusel on broneeringuid. Peida see kustutamise asemel.', 'sasipea-broneering' ), 'edit' => $id ) );
		}
		$wpdb->delete( Install::table( 'services' ), array( 'id' => $id ) );
		$wpdb->delete( Install::table( 'staff_services' ), array( 'service_id' => $id ) );
		self::back( 'sb-services' );
	}

	public static function handle_save_category(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_save_category' );
		$data = array(
			'name'       => self::post( 'name' ),
			'sort_order' => (int) self::post( 'sort_order' ),
			'hidden'     => self::post( 'hidden' ) ? 1 : 0,
		);
		$id = (int) self::post( 'id' );
		if ( $id ) {
			$wpdb->update( Install::table( 'categories' ), $data, array( 'id' => $id ) );
		} elseif ( '' !== $data['name'] ) {
			$wpdb->insert( Install::table( 'categories' ), $data );
		}
		self::back( 'sb-services' );
	}

	/* ================= Praktikandid ================= */

	public static function page_staff(): void {
		$staff = Repo::staff_list( false );
		$id    = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit  = $id ? Repo::staff( $id ) : null;
		echo '<div class="wrap"><h1>' . esc_html__( 'Praktikandid', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Nimi', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Tase', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Olek', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Nädalapõhi', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
		$days = array( 1 => 'E', 'T', 'K', 'N', 'R', 'L', 'P' );
		foreach ( $staff as $s ) {
			$sched = array();
			foreach ( Repo::schedule( $s['id'] ) as $r ) {
				$sched[ (int) $r['weekday'] ][] = Settings::from_min( (int) $r['start_min'] ) . '–' . Settings::from_min( (int) $r['end_min'] );
			}
			$txt = array();
			foreach ( $sched as $wd => $rs ) {
				$txt[] = $days[ $wd ] . ' ' . implode( ', ', $rs );
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
				esc_html( $s['name'] ),
				esc_html( self::terms_label( $s ) ),
				$s['active'] ? esc_html__( 'aktiivne', 'sasipea-broneering' ) : '<em>' . esc_html__( 'mitteaktiivne', 'sasipea-broneering' ) . '</em>',
				esc_html( implode( '; ', $txt ) ),
				esc_url( admin_url( 'admin.php?page=sb-staff&edit=' . $s['id'] ) ),
				esc_html__( 'Muuda', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=sb-staff&edit=new' ) ) . '">' . esc_html__( 'Lisa praktikant', 'sasipea-broneering' ) . '</a></p>';

		$is_new = isset( $_GET['edit'] ) && 'new' === $_GET['edit']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $edit && ! $is_new ) {
			echo '</div>';
			return;
		}
		$s        = $edit ?: array( 'id' => 0, 'name' => '', 'photo_url' => '', 'active' => true, 'user_id' => null, 'sort_order' => count( $staff ), 'level' => '', 'discount_override' => null, 'extra_override' => null );
		$svc_ids  = $edit ? Repo::staff_service_ids( $s['id'] ) : array_column( array_merge( ...array_column( Repo::catalog( true ), 'services' ) ), 'id' );
		$schedule = array();
		if ( $edit ) {
			foreach ( Repo::schedule( $s['id'] ) as $r ) {
				$schedule[ (int) $r['weekday'] ][] = array( (int) $r['start_min'], (int) $r['end_min'] );
			}
		} else {
			foreach ( Settings::get()['opening_hours'] as $wd => $rs ) {
				foreach ( $rs as $r ) {
					$schedule[ (int) $wd ][] = array( Settings::to_min( $r[0] ), Settings::to_min( $r[1] ) );
				}
			}
		}

		echo '<h2>' . ( $edit ? esc_html( $s['name'] ) : esc_html__( 'Uus praktikant', 'sasipea-broneering' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_staff' );
		echo '<input type="hidden" name="action" value="sb_save_staff"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><table class="form-table">';
		printf( '<tr><th><label for="sb-sn">%s</label></th><td><input id="sb-sn" name="name" class="regular-text" required value="%s"></td></tr>', esc_html__( 'Nimi', 'sasipea-broneering' ), esc_attr( $s['name'] ) );
		printf( '<tr><th><label for="sb-sp">%s</label></th><td><input id="sb-sp" name="photo_url" type="url" class="regular-text" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Pildi URL', 'sasipea-broneering' ), esc_attr( $s['photo_url'] ), esc_html__( 'Lae pilt meediateeki ja kopeeri selle aadress siia.', 'sasipea-broneering' ) );
		echo '<tr><th><label for="sb-su">' . esc_html__( 'WordPressi kasutaja', 'sasipea-broneering' ) . '</label></th><td><select id="sb-su" name="user_id"><option value="0">—</option>';
		foreach ( get_users( array( 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( (int) $u->ID, (int) $s['user_id'], false ), esc_html( $u->display_name ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Seotud kasutaja näeb päevaplaanis oma graafikut. Talle antakse roll "Praktikant", kui tal pole juhendaja ega admini õigusi.', 'sasipea-broneering' ) . '</p></td></tr>';
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="active" value="1"%s> %s</label></td></tr>', esc_html__( 'Olek', 'sasipea-broneering' ), checked( $s['active'], true, false ), esc_html__( 'Aktiivne (saab broneerida)', 'sasipea-broneering' ) );
		printf( '<tr><th><label for="sb-so">%s</label></th><td><input id="sb-so" name="sort_order" type="number" value="%d"></td></tr>', esc_html__( 'Järjekord', 'sasipea-broneering' ), (int) $s['sort_order'] );

		echo '<tr><th><label for="sb-lvl">' . esc_html__( 'Tase', 'sasipea-broneering' ) . '</label></th><td><select id="sb-lvl" name="level"><option value="">' . esc_html__( '— tasemeta (täishind, tavakestus) —', 'sasipea-broneering' ) . '</option>';
		foreach ( (array) Settings::get()['levels'] as $l ) {
			/* translators: 1: level name, 2: discount percent, 3: extra minutes */
			printf( '<option value="%s"%s>%s</option>', esc_attr( $l['id'] ), selected( $l['id'], $s['level'], false ), esc_html( sprintf( __( '%1$s (soodustus %2$d%%, lisaaeg %3$d min)', 'sasipea-broneering' ), $l['name'], (int) $l['discount_pct'], (int) $l['extra_min'] ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Tase määrab, kui palju on teenus selle praktikandi juures soodsam ja kui palju kauem see kestab. Tasemeid saab muuta Seadete lehel.', 'sasipea-broneering' ) . '</p></td></tr>';
		printf(
			'<tr><th>%s</th><td><label>%s <input name="discount_pct" type="number" min="0" max="100" style="width:80px" value="%s" placeholder="—"> %%</label> &nbsp; <label>%s <input name="extra_min" type="number" min="0" max="240" step="5" style="width:80px" value="%s" placeholder="—"> min</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Erisused', 'sasipea-broneering' ),
			esc_html__( 'Soodustus', 'sasipea-broneering' ),
			esc_attr( null === $s['discount_override'] ? '' : (string) $s['discount_override'] ),
			esc_html__( 'Lisaaeg', 'sasipea-broneering' ),
			esc_attr( null === $s['extra_override'] ? '' : (string) $s['extra_override'] ),
			esc_html__( 'Täida ainult siis, kui see praktikant erineb oma tasemest. Tühi = taseme järgi.', 'sasipea-broneering' )
		);

		echo '<tr><th>' . esc_html__( 'Nädalapõhi', 'sasipea-broneering' ) . '</th><td><p class="description">' . esc_html__( 'Tavaline nädal, mille järgi kuu töögraafik eeltäidetakse. Näiteks 12:00-19:00. Pausiga päev: 10:00-13:00, 13:30-16:00. Tühi = ei tööta.', 'sasipea-broneering' ) . '</p>';
		$long = Format::days();
		foreach ( $long as $wd => $name ) {
			printf( '<p><label style="display:inline-block;width:110px" for="sb-wd%1$d">%2$s</label><input id="sb-wd%1$d" name="schedule[%1$d]" value="%3$s" placeholder="—"></p>', (int) $wd, esc_html( ucfirst( $name ) ), esc_attr( self::ranges_str( $schedule[ $wd ] ?? array() ) ) );
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Teenused', 'sasipea-broneering' ) . '</th><td>';
		foreach ( Repo::catalog( true ) as $cat ) {
			echo '<fieldset style="margin-bottom:8px"><legend><strong>' . esc_html( $cat['name'] ) . '</strong></legend>';
			foreach ( $cat['services'] as $sv ) {
				printf( '<label style="display:block"><input type="checkbox" name="services[]" value="%d"%s> %s</label>', (int) $sv['id'], checked( in_array( $sv['id'], $svc_ids, true ), true, false ), esc_html( $sv['name'] ) );
			}
			echo '</fieldset>';
		}
		echo '</td></tr></table>';
		submit_button();
		echo '</form>';

		if ( $edit ) {
			echo '<h2>' . esc_html__( 'Päevade erandid', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Erand asendab sel päeval nädala graafiku: teised tunnid või vaba päev.', 'sasipea-broneering' ) . '</p>';
			$ex = Repo::exceptions( $s['id'] );
			if ( $ex ) {
				echo '<table class="widefat striped" style="max-width:600px"><tbody>';
				foreach ( $ex as $e ) {
					echo '<tr><td>' . esc_html( $e['day'] ) . '</td><td>' . ( (int) $e['is_off'] ? esc_html__( 'vaba päev', 'sasipea-broneering' ) : esc_html( Settings::from_min( (int) $e['start_min'] ) . '–' . Settings::from_min( (int) $e['end_min'] ) ) ) . '</td><td>';
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( 'sb_delete_exception' );
					echo '<input type="hidden" name="action" value="sb_delete_exception"><input type="hidden" name="id" value="' . (int) $e['id'] . '"><input type="hidden" name="staff_id" value="' . (int) $s['id'] . '">';
					submit_button( __( 'Eemalda', 'sasipea-broneering' ), 'link-delete small', 'submit', false );
					echo '</form></td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
			wp_nonce_field( 'sb_add_exception' );
			echo '<input type="hidden" name="action" value="sb_add_exception"><input type="hidden" name="staff_id" value="' . (int) $s['id'] . '">';
			echo '<label>' . esc_html__( 'Kuupäev', 'sasipea-broneering' ) . ' <input type="date" name="day" required></label> ';
			echo '<label>' . esc_html__( 'Tunnid', 'sasipea-broneering' ) . ' <input name="ranges" placeholder="10:00-14:00"></label> ';
			echo '<label><input type="checkbox" name="is_off" value="1"> ' . esc_html__( 'Vaba päev', 'sasipea-broneering' ) . '</label> ';
			submit_button( __( 'Lisa erand', 'sasipea-broneering' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	public static function handle_save_staff(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_save_staff' );
		$id      = (int) self::post( 'id' );
		$user_id = (int) self::post( 'user_id' );
		$data    = array(
			'name'       => self::post( 'name' ),
			'photo_url'  => esc_url_raw( self::post( 'photo_url' ) ),
			'active'     => self::post( 'active' ) ? 1 : 0,
			'sort_order' => (int) self::post( 'sort_order' ),
			'user_id'    => $user_id ?: null,
			'level'      => in_array( self::post( 'level' ), array_column( (array) Settings::get()['levels'], 'id' ), true ) ? self::post( 'level' ) : '',
			'discount_pct' => '' === self::post( 'discount_pct' ) ? null : max( 0, min( 100, (int) self::post( 'discount_pct' ) ) ),
			'extra_min'  => '' === self::post( 'extra_min' ) ? null : max( 0, min( 240, (int) self::post( 'extra_min' ) ) ),
		);
		if ( $id ) {
			$wpdb->update( Install::table( 'staff' ), $data, array( 'id' => $id ) );
		} else {
			$data['ics_token'] = bin2hex( random_bytes( 16 ) );
			$wpdb->insert( Install::table( 'staff' ), $data );
			$id = (int) $wpdb->insert_id;
		}
		if ( $user_id ) {
			$u = get_userdata( $user_id );
			if ( $u && ! user_can( $u, 'sb_manage_bookings' ) && ! in_array( Roles::PRAKTIKANT, $u->roles, true ) ) {
				$u->add_role( Roles::PRAKTIKANT );
			}
		}
		$wpdb->delete( Install::table( 'staff_services' ), array( 'staff_id' => $id ) );
		foreach ( array_map( 'intval', (array) ( $_POST['services'] ?? array() ) ) as $sid ) { // phpcs:ignore WordPress.Security.NonceVerification
			$wpdb->insert( Install::table( 'staff_services' ), array( 'staff_id' => $id, 'service_id' => $sid ) );
		}
		$wpdb->delete( Install::table( 'schedules' ), array( 'staff_id' => $id ) );
		$sched = (array) wp_unslash( $_POST['schedule'] ?? array() ); // phpcs:ignore
		foreach ( range( 1, 7 ) as $wd ) {
			foreach ( self::parse_ranges( sanitize_text_field( (string) ( $sched[ $wd ] ?? '' ) ) ) as $r ) {
				$wpdb->insert( Install::table( 'schedules' ), array( 'staff_id' => $id, 'weekday' => $wd, 'start_min' => $r[0], 'end_min' => $r[1] ) );
			}
		}
		self::back( 'sb-staff', array( 'edit' => $id ) );
	}

	public static function handle_add_exception(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_add_exception' );
		$staff = (int) self::post( 'staff_id' );
		$day   = self::post( 'day' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			self::back( 'sb-staff', array( 'edit' => $staff ) );
		}
		$wpdb->delete( Install::table( 'schedule_exceptions' ), array( 'staff_id' => $staff, 'day' => $day ) );
		$ranges = self::parse_ranges( self::post( 'ranges' ) );
		if ( self::post( 'is_off' ) || ! $ranges ) {
			$wpdb->insert( Install::table( 'schedule_exceptions' ), array( 'staff_id' => $staff, 'day' => $day, 'is_off' => 1 ) );
		} else {
			foreach ( $ranges as $r ) {
				$wpdb->insert( Install::table( 'schedule_exceptions' ), array( 'staff_id' => $staff, 'day' => $day, 'start_min' => $r[0], 'end_min' => $r[1], 'is_off' => 0 ) );
			}
		}
		self::back( 'sb-staff', array( 'edit' => $staff ) );
	}

	public static function handle_delete_exception(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_delete_exception' );
		$wpdb->delete( Install::table( 'schedule_exceptions' ), array( 'id' => (int) self::post( 'id' ) ) );
		self::back( 'sb-staff', array( 'edit' => (int) self::post( 'staff_id' ) ) );
	}

	/** "Noorempraktikant · −10% · +30 min" */
	public static function terms_label( array $s ): string {
		$parts = array();
		if ( '' !== $s['level_name'] ) {
			$parts[] = $s['level_name'];
		}
		if ( $s['discount_pct'] ) {
			$parts[] = '−' . (int) $s['discount_pct'] . '%';
		}
		if ( $s['extra_min'] ) {
			$parts[] = '+' . (int) $s['extra_min'] . ' min';
		}
		return implode( ' · ', $parts );
	}

	/* ================= Kuu töögraafik ================= */

	private static function month_param(): string {
		$ym = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym ) ? $ym : ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m' );
	}

	public static function page_month(): void {
		$ym     = self::month_param();
		$first  = new \DateTimeImmutable( $ym . '-01', Settings::tz() );
		$ready  = Repo::month_ready( $ym );
		$staff  = Repo::staff_list( true );
		$counts = Repo::booking_counts( $ym );
		$title  = ucfirst( Format::months()[ (int) $first->format( 'n' ) ] ) . ' ' . $first->format( 'Y' );
		$url    = static fn( \DateTimeImmutable $d ) => esc_url( admin_url( 'admin.php?page=sb-month&month=' . $d->format( 'Y-m' ) ) );
		$short  = array( 1 => 'E', 'T', 'K', 'N', 'R', 'L', 'P' );

		echo '<div class="wrap sb-month"><h1>' . esc_html__( 'Töögraafik', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<style>
			.sb-month .sb-m-nav{display:flex;gap:8px;align-items:center;margin:12px 0}
			.sb-month .sb-m-nav h2{margin:0 8px;min-width:180px;text-align:center}
			.sb-month .sb-m-scroll{overflow-x:auto;max-width:100%}
			.sb-month table{border-collapse:collapse;background:#fff}
			.sb-month th,.sb-month td{border:1px solid #dcdcde;padding:6px 8px;text-align:left;vertical-align:middle;white-space:nowrap}
			.sb-month thead th{background:#f6f7f7;position:sticky;top:32px;z-index:2}
			.sb-month tr.is-closed td,.sb-month tr.is-closed th{background:#f0f0f1;color:#646970;padding-top:2px;padding-bottom:2px;font-size:12px}
			.sb-month tr.is-week-start th,.sb-month tr.is-week-start td{border-top:2px solid #8c8f94}
			.sb-month td.is-on{background:#edf7ed}
			.sb-month .sb-m-hours{width:150px}
			.sb-month .sb-m-count{margin-left:6px;color:#8a2424;font-size:12px}
			.sb-month .sb-m-sub{display:block;font-weight:400;font-size:12px;color:#50575e}
			.sb-month .sb-m-badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600}
			.sb-month .sb-m-badge.is-ready{background:#e3f4ea;color:#00572c}
			.sb-month .sb-m-badge.is-draft{background:#fcf0d6;color:#6b4a00}
		</style>';
		echo '<div class="sb-m-nav"><a class="button" href="' . $url( $first->modify( '-1 month' ) ) . '">‹ ' . esc_html__( 'Eelmine kuu', 'sasipea-broneering' ) . '</a><h2>' . esc_html( $title ) . '</h2><a class="button" href="' . $url( $first->modify( '+1 month' ) ) . '">' . esc_html__( 'Järgmine kuu', 'sasipea-broneering' ) . ' ›</a> ';
		echo $ready
			? '<span class="sb-m-badge is-ready">' . esc_html__( 'Graafik on koostatud', 'sasipea-broneering' ) . '</span>'
			: '<span class="sb-m-badge is-draft">' . esc_html__( 'Graafik on koostamata', 'sasipea-broneering' ) . '</span>';
		echo '</div>';

		if ( ! $staff ) {
			echo '<p>' . esc_html__( 'Lisa kõigepealt praktikandid.', 'sasipea-broneering' ) . '</p></div>';
			return;
		}
		if ( ! $ready ) {
			echo '<p class="description">' . esc_html( empty( Settings::get()['require_month'] )
				? __( 'Allpool on eeltäidetud iga praktikandi nädalapõhi. Märgi, kes mis päeval töötab, muuda vajadusel kellaaegu ja salvesta. Kuni salvestamiseni kehtib selles kuus nädalapõhi.', 'sasipea-broneering' )
				: __( 'Allpool on eeltäidetud iga praktikandi nädalapõhi. Märgi, kes mis päeval töötab, muuda vajadusel kellaaegu ja salvesta. Kuni salvestamiseni selles kuus aegu ei pakuta.', 'sasipea-broneering' ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Linnuke = töötab. Kellaaeg näiteks 12:00-19:00, pausiga 10:00-13:00, 13:30-16:00. Olemasolevaid broneeringuid graafiku muutmine ei tühista.', 'sasipea-broneering' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_month' );
		echo '<input type="hidden" name="action" value="sb_save_month"><input type="hidden" name="month" value="' . esc_attr( $ym ) . '">';
		echo '<div class="sb-m-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'Päev', 'sasipea-broneering' ) . '</th>';
		foreach ( $staff as $s ) {
			printf(
				'<th scope="col">%s<span class="sb-m-sub">%s</span><label class="sb-m-sub"><input type="checkbox" class="sb-m-all" data-staff="%d"> %s</label></th>',
				esc_html( $s['name'] ),
				esc_html( self::terms_label( $s ) ),
				(int) $s['id'],
				esc_html__( 'kõik päevad', 'sasipea-broneering' )
			);
		}
		echo '</tr></thead><tbody>';
		for ( $d = $first; $d->format( 'Y-m' ) === $ym; $d = $d->modify( '+1 day' ) ) {
			$date    = $d->format( 'Y-m-d' );
			$wd      = (int) $d->format( 'N' );
			$opening = Settings::opening_for( $date );
			$label   = $short[ $wd ] . ' ' . $d->format( 'j.m' );
			$week    = 1 === $wd ? ' is-week-start' : '';
			if ( ! $opening ) {
				echo '<tr class="is-closed' . esc_attr( $week ) . '"><th scope="row">' . esc_html( $label ) . '</th><td colspan="' . count( $staff ) . '">' . esc_html__( 'suletud', 'sasipea-broneering' ) . '</td></tr>';
				continue;
			}
			echo '<tr class="' . esc_attr( trim( $week ) ) . '"><th scope="row">' . esc_html( $label ) . '<span class="sb-m-sub">' . esc_html( str_replace( '-', '–', self::ranges_str( $opening ) ) ) . '</span></th>';
			foreach ( $staff as $s ) {
				$ranges = Repo::planned_ranges( $s['id'], $date );
				$n      = (int) ( $counts[ $s['id'] ][ $date ] ?? 0 );
				printf(
					'<td class="%1$s"><input type="checkbox" name="on[%2$d][%3$s]" value="1" data-staff="%2$d" data-default="%4$s" aria-label="%5$s"%6$s> <input class="sb-m-hours" name="hours[%2$d][%3$s]" value="%7$s" placeholder="%4$s" aria-label="%8$s"%9$s>%10$s</td>',
					$ranges ? 'is-on' : '',
					(int) $s['id'],
					esc_attr( $date ),
					esc_attr( self::ranges_str( $opening ) ),
					/* translators: 1: trainee name, 2: date */
					esc_attr( sprintf( __( '%1$s töötab %2$s', 'sasipea-broneering' ), $s['name'], $d->format( 'j.m' ) ) ),
					checked( (bool) $ranges, true, false ),
					esc_attr( self::ranges_str( $ranges ) ),
					/* translators: 1: trainee name, 2: date */
					esc_attr( sprintf( __( '%1$s kellaajad %2$s', 'sasipea-broneering' ), $s['name'], $d->format( 'j.m' ) ) ),
					$ranges ? '' : ' disabled',
					/* translators: %d: number of bookings */
					$n ? '<span class="sb-m-count">' . esc_html( sprintf( _n( '%d broneering', '%d broneeringut', $n, 'sasipea-broneering' ), $n ) ) . '</span>' : ''
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		submit_button( $ready ? __( 'Salvesta graafik', 'sasipea-broneering' ) : __( 'Lisa kuu graafik', 'sasipea-broneering' ) );
		echo '</form>';
		echo "<script>
		(function(){
			var f=document.querySelector('.sb-month form');
			function sync(cb){var t=cb.parentNode.querySelector('.sb-m-hours');t.disabled=!cb.checked;if(cb.checked&&!t.value)t.value=cb.getAttribute('data-default');cb.parentNode.classList.toggle('is-on',cb.checked);}
			f.addEventListener('change',function(e){
				var el=e.target;
				if(el.classList.contains('sb-m-all')){f.querySelectorAll('td input[type=checkbox][data-staff=\"'+el.getAttribute('data-staff')+'\"]').forEach(function(cb){cb.checked=el.checked;sync(cb);});}
				else if(el.type==='checkbox'){sync(el);}
			});
		})();
		</script>";
		echo '</div>';
	}

	public static function handle_save_month(): void {
		self::guard( 'sb_manage_bookings', 'sb_save_month' );
		$ym = self::post( 'month' );
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $ym ) ) {
			self::back( 'sb-month' );
		}
		$p     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$first = new \DateTimeImmutable( $ym . '-01', Settings::tz() );
		$plan  = array();
		foreach ( Repo::staff_list( true ) as $s ) {
			$sid = $s['id'];
			for ( $d = $first; $d->format( 'Y-m' ) === $ym; $d = $d->modify( '+1 day' ) ) {
				$date    = $d->format( 'Y-m-d' );
				$opening = Settings::opening_for( $date );
				if ( ! $opening ) {
					continue;
				}
				if ( empty( $p['on'][ $sid ][ $date ] ) ) {
					$plan[ $sid ][ $date ] = array();
					continue;
				}
				// Linnuke ilma loetava kellaajata tähendab tervet lahtiolekuaega.
				$plan[ $sid ][ $date ] = self::parse_ranges( sanitize_text_field( (string) ( $p['hours'][ $sid ][ $date ] ?? '' ) ) ) ?: $opening;
			}
		}
		Repo::save_month( $ym, $plan );
		self::back( 'sb-month', array( 'month' => $ym ) );
	}

	/* ================= Kliendid ================= */

	public static function page_clients(): void {
		global $wpdb;
		$q  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$id = isset( $_GET['client'] ) ? (int) $_GET['client'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap"><h1>' . esc_html__( 'Kliendid', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<form method="get"><input type="hidden" name="page" value="sb-clients"><label class="screen-reader-text" for="sb-q">' . esc_html__( 'Otsi', 'sasipea-broneering' ) . '</label><input id="sb-q" type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Nimi, telefon või e-post', 'sasipea-broneering' ) . '"> ';
		submit_button( __( 'Otsi', 'sasipea-broneering' ), 'secondary', '', false );
		echo '</form>';

		if ( $id ) {
			$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Install::table( 'clients' ) . ' WHERE id = %d', $id ), ARRAY_A );
			if ( $c ) {
				echo '<h2>' . esc_html( trim( $c['first_name'] . ' ' . $c['last_name'] ) ) . '</h2>';
				echo '<p>' . esc_html( $c['phone'] ) . ' · ' . esc_html( $c['email'] ) . '</p>';
				$murl = Integrations\Modellid::model_url( $c['model_id'] ? (int) $c['model_id'] : null );
				if ( $c['model_id'] ) {
					echo '<p><strong>' . esc_html__( 'Modellide andmebaasis', 'sasipea-broneering' ) . '</strong>' . ( $murl ? ' – <a href="' . esc_url( $murl ) . '">' . esc_html__( 'ava modelli kaart', 'sasipea-broneering' ) . '</a>' : '' ) . '</p>';
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'sb_save_client' );
				echo '<input type="hidden" name="action" value="sb_save_client"><input type="hidden" name="id" value="' . (int) $id . '">';
				echo '<p><label for="sb-notes"><strong>' . esc_html__( 'Märkmed', 'sasipea-broneering' ) . '</strong></label><br><textarea id="sb-notes" name="notes" rows="4" class="large-text">' . esc_textarea( (string) $c['notes'] ) . '</textarea></p>';
				submit_button( __( 'Salvesta märkmed', 'sasipea-broneering' ), 'secondary' );
				echo '</form><h3>' . esc_html__( 'Külastused', 'sasipea-broneering' ) . '</h3><table class="widefat striped" style="max-width:900px"><tbody>';
				foreach ( Repo::client_history( $id ) as $b ) {
					printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( Format::local( $b['start_utc'] )->format( 'd.m.Y H:i' ) ), esc_html( $b['service_name'] ), esc_html( $b['staff_name'] ), esc_html( Repo::STATUSES[ $b['status'] ] ?? $b['status'] ), esc_html( (string) $b['comment'] ) );
				}
				echo '</tbody></table>';
			}
		} elseif ( '' !== $q ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-top:12px"><tbody>';
			foreach ( Repo::search_clients( $q, 50 ) as $c ) {
				printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_url( admin_url( 'admin.php?page=sb-clients&client=' . $c['id'] ) ), esc_html( trim( $c['first_name'] . ' ' . $c['last_name'] ) ), esc_html( $c['phone'] ), esc_html( $c['email'] ) );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function handle_save_client(): void {
		global $wpdb;
		self::guard( 'sb_manage_bookings', 'sb_save_client' );
		$id = (int) self::post( 'id' );
		$wpdb->update( Install::table( 'clients' ), array( 'notes' => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ) ), array( 'id' => $id ) ); // phpcs:ignore
		self::back( 'sb-clients', array( 'client' => $id ) );
	}

	/* ================= Seaded ================= */

	public static function page_settings(): void {
		$s = Settings::get();
		echo '<div class="wrap"><h1>' . esc_html__( 'Broneerimise seaded', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_settings' );
		echo '<input type="hidden" name="action" value="sb_save_settings">';

		echo '<h2>' . esc_html__( 'Lahtiolekuajad', 'sasipea-broneering' ) . '</h2><table class="form-table">';
		foreach ( Format::days() as $wd => $name ) {
			$r = array_map( fn( $x ) => array( Settings::to_min( $x[0] ), Settings::to_min( $x[1] ) ), $s['opening_hours'][ (string) $wd ] ?? array() );
			printf( '<tr><th><label for="sb-oh%1$d">%2$s</label></th><td><input id="sb-oh%1$d" name="opening[%1$d]" value="%3$s" placeholder="%4$s"></td></tr>', (int) $wd, esc_html( ucfirst( $name ) ), esc_attr( self::ranges_str( $r ) ), esc_attr__( 'suletud', 'sasipea-broneering' ) );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Broneerimise reeglid', 'sasipea-broneering' ) . '</h2><table class="form-table">';
		$num = array(
			'slot_step'        => array( __( 'Ajasamm (min)', 'sasipea-broneering' ), __( 'Mitme minuti tagant algusaegu pakutakse, nt 15 või 30.', 'sasipea-broneering' ) ),
			'buffer'           => array( __( 'Puhver teenuste vahel (min)', 'sasipea-broneering' ), __( 'Sama praktikandi kahe kliendi vahele jääv aeg.', 'sasipea-broneering' ) ),
			'min_notice_hours' => array( __( 'Miinimumetteteatamine (h)', 'sasipea-broneering' ), __( 'Kui palju varem peab online-broneering olema tehtud.', 'sasipea-broneering' ) ),
			'max_days_ahead'   => array( __( 'Kui kaugele ette saab broneerida (päeva)', 'sasipea-broneering' ), '' ),
			'max_concurrent'   => array( __( 'Korraga juhendatavaid kliente', 'sasipea-broneering' ), __( 'Mitu klienti võib salongis samal ajal olla (juhendaja maht). 0 = piiranguta.', 'sasipea-broneering' ) ),
			'cancel_hours'     => array( __( 'Tühistamine/muutmine kuni (h enne)', 'sasipea-broneering' ), '' ),
		);
		foreach ( $num as $k => $l ) {
			printf( '<tr><th><label for="sb-%1$s">%2$s</label></th><td><input id="sb-%1$s" name="%1$s" type="number" min="0" value="%3$d"><p class="description">%4$s</p></td></tr>', esc_attr( $k ), esc_html( $l[0] ), (int) $s[ $k ], esc_html( $l[1] ) );
		}
		echo '</table>';

		printf(
			'<p><label><input type="checkbox" name="require_month" value="1"%s> %s</label></p>',
			checked( ! empty( $s['require_month'] ), true, false ),
			esc_html__( 'Aegu pakutakse ainult nendes kuudes, mille töögraafik on koostatud (Broneeringud → Töögraafik). Muidu kehtib koostamata kuus iga praktikandi nädalapõhi.', 'sasipea-broneering' )
		);

		echo '<h2>' . esc_html__( 'Praktikandi vaade', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Sisseloginud praktikant näeb päevaplaanis oma veergu ja oma broneeringuid ega saa midagi muuta. Siin määrad, mida ta veel näeb.', 'sasipea-broneering' ) . '</p>';
		$tv = array(
			'phone'   => __( 'Kliendi telefoninumber', 'sasipea-broneering' ),
			'price'   => __( 'Teenuse hind', 'sasipea-broneering' ),
			'details' => __( 'Kliendi e-post ja kommentaar', 'sasipea-broneering' ),
			'others'  => __( 'Teiste praktikantide veerud (ajad ja teenused, ilma klientide andmeteta)', 'sasipea-broneering' ),
		);
		foreach ( $tv as $k => $l ) {
			printf( '<p><label><input type="checkbox" name="trainee_view[%s]" value="1"%s> %s</label></p>', esc_attr( $k ), checked( ! empty( $s['trainee_view'][ $k ] ), true, false ), esc_html( $l ) );
		}

		echo '<h2>' . esc_html__( 'Praktikantide tasemed', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Tase annab kliendile soodustuse teenuse hinnast ja pikendab teenuse aega selle praktikandi juures. Taseme eemaldamiseks tühjenda nimi.', 'sasipea-broneering' ) . '</p>';
		echo '<table class="widefat" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Taseme nimi', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Soodustus (%)', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Lisaaeg (min)', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
		$levels   = (array) $s['levels'];
		$levels[] = array( 'id' => '', 'name' => '', 'discount_pct' => 0, 'extra_min' => 0 );
		foreach ( $levels as $i => $l ) {
			printf(
				'<tr><td><input type="hidden" name="levels[%1$d][id]" value="%2$s"><input name="levels[%1$d][name]" value="%3$s" placeholder="%6$s" aria-label="%7$s"></td><td><input name="levels[%1$d][discount_pct]" type="number" min="0" max="100" style="width:90px" value="%4$d" aria-label="%8$s"></td><td><input name="levels[%1$d][extra_min]" type="number" min="0" max="240" step="5" style="width:90px" value="%5$d" aria-label="%9$s"></td></tr>',
				(int) $i,
				esc_attr( $l['id'] ),
				esc_attr( $l['name'] ),
				(int) $l['discount_pct'],
				(int) $l['extra_min'],
				esc_attr__( 'Lisa uus tase', 'sasipea-broneering' ),
				esc_attr__( 'Taseme nimi', 'sasipea-broneering' ),
				esc_attr__( 'Soodustus protsentides', 'sasipea-broneering' ),
				esc_attr__( 'Lisaaeg minutites', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Salongi andmed ja lingid', 'sasipea-broneering' ) . '</h2><table class="form-table">';
		$txt = array(
			'booking_page_url' => __( 'Broneerimise lehe aadress', 'sasipea-broneering' ),
			'privacy_url'      => __( 'Privaatsuspoliitika aadress', 'sasipea-broneering' ),
			'from_name'        => __( 'Kirjade saatja nimi', 'sasipea-broneering' ),
			'from_email'       => __( 'Kirjade saatja e-post', 'sasipea-broneering' ),
			'notify_email'     => __( 'Teavitused aadressile', 'sasipea-broneering' ),
			'salon_address'    => __( 'Aadress', 'sasipea-broneering' ),
			'salon_phone'      => __( 'Telefon', 'sasipea-broneering' ),
			'salon_web'        => __( 'Koduleht', 'sasipea-broneering' ),
			'consent_version'  => __( 'Nõusolekutekstide versioon', 'sasipea-broneering' ),
		);
		foreach ( $txt as $k => $l ) {
			printf( '<tr><th><label for="sb-%1$s">%2$s</label></th><td><input id="sb-%1$s" name="%1$s" class="regular-text" value="%3$s"></td></tr>', esc_attr( $k ), esc_html( $l ), esc_attr( $s[ $k ] ) );
		}
		printf( '<tr><th><label for="sb-cn">%s</label></th><td><textarea id="sb-cn" name="confirmation_note" rows="3" class="large-text">%s</textarea></td></tr>', esc_html__( 'Märkus kinnituslehel', 'sasipea-broneering' ), esc_textarea( $s['confirmation_note'] ) );
		foreach ( array( 'privacy' => __( 'Privaatsuse nõusolek', 'sasipea-broneering' ), 'models' => __( 'Modellide andmebaasi linnuke', 'sasipea-broneering' ), 'social' => __( 'Sotsiaalmeedia linnuke', 'sasipea-broneering' ) ) as $k => $l ) {
			printf( '<tr><th><label for="sb-ct-%1$s">%2$s</label></th><td><textarea id="sb-ct-%1$s" name="consent_texts[%1$s]" rows="2" class="large-text">%3$s</textarea></td></tr>', esc_attr( $k ), esc_html( $l ), esc_textarea( $s['consent_texts'][ $k ] ) );
		}
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Kui muudad nõusolekute teksti, muuda ka versiooni. Iga nõusolek salvestatakse koos versiooniga.', 'sasipea-broneering' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'E-kirjade mallid', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Kohatäited: {eesnimi} {nimi} {teenus} {kuupaev} {kell} {praktikant} {hind} {materjal} {halda_link} {aadress} {telefon} {koduleht} {kliendi_telefon} {kliendi_email} {kommentaar} {staatus}. Lubatud on lihtne HTML, nt <strong>.', 'sasipea-broneering' ) . '</p><table class="form-table">';
		$labels = array(
			'client_confirm'  => __( 'Kliendile: kinnitus', 'sasipea-broneering' ),
			'client_reminder' => __( 'Kliendile: meeldetuletus 24 h enne', 'sasipea-broneering' ),
			'client_moved'    => __( 'Kliendile: aeg muudetud', 'sasipea-broneering' ),
			'client_cancel'   => __( 'Kliendile: tühistatud', 'sasipea-broneering' ),
			'admin_new'       => __( 'Salongile: uus broneering', 'sasipea-broneering' ),
			'admin_moved'     => __( 'Salongile: muudetud', 'sasipea-broneering' ),
			'admin_cancel'    => __( 'Salongile: tühistatud', 'sasipea-broneering' ),
		);
		foreach ( $labels as $k => $l ) {
			printf(
				'<tr><th>%1$s</th><td><label class="screen-reader-text" for="sb-t-%2$s-s">%3$s</label><input id="sb-t-%2$s-s" name="templates[%2$s_subject]" class="large-text" value="%4$s"><label class="screen-reader-text" for="sb-t-%2$s-b">%5$s</label><textarea id="sb-t-%2$s-b" name="templates[%2$s_body]" rows="7" class="large-text">%6$s</textarea></td></tr>',
				esc_html( $l ),
				esc_attr( $k ),
				esc_html__( 'Teema', 'sasipea-broneering' ),
				esc_attr( $s['templates'][ $k . '_subject' ] ),
				esc_html__( 'Sisu', 'sasipea-broneering' ),
				esc_textarea( $s['templates'][ $k . '_body' ] )
			);
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public static function handle_save_settings(): void {
		self::guard( 'sb_manage_settings', 'sb_save_settings' );
		$p    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$vals = array( 'opening_hours' => array() );
		foreach ( range( 1, 7 ) as $wd ) {
			$vals['opening_hours'][ (string) $wd ] = array_map(
				fn( $r ) => array( Settings::from_min( $r[0] ), Settings::from_min( $r[1] ) ),
				self::parse_ranges( sanitize_text_field( (string) ( $p['opening'][ $wd ] ?? '' ) ) )
			);
		}
		foreach ( array( 'slot_step', 'buffer', 'min_notice_hours', 'max_days_ahead', 'max_concurrent', 'cancel_hours' ) as $k ) {
			$vals[ $k ] = max( 0, (int) ( $p[ $k ] ?? 0 ) );
		}
		$vals['slot_step']     = max( 5, $vals['slot_step'] );
		$vals['require_month'] = ! empty( $p['require_month'] );
		$vals['trainee_view']  = array();
		foreach ( array( 'phone', 'price', 'details', 'others' ) as $k ) {
			$vals['trainee_view'][ $k ] = ! empty( $p['trainee_view'][ $k ] );
		}
		$vals['levels']        = array();
		foreach ( (array) ( $p['levels'] ?? array() ) as $l ) {
			$name = sanitize_text_field( (string) ( $l['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$lid = sanitize_key( (string) ( $l['id'] ?? '' ) );
			if ( '' === $lid ) {
				$lid = substr( sanitize_title( $name ), 0, 30 ) ?: 'tase';
				while ( in_array( $lid, array_column( $vals['levels'], 'id' ), true ) ) {
					$lid .= '2';
				}
			}
			$vals['levels'][] = array(
				'id'           => substr( $lid, 0, 40 ),
				'name'         => $name,
				'discount_pct' => max( 0, min( 100, (int) ( $l['discount_pct'] ?? 0 ) ) ),
				'extra_min'    => max( 0, min( 240, (int) ( $l['extra_min'] ?? 0 ) ) ),
			);
		}
		foreach ( array( 'booking_page_url', 'privacy_url', 'from_name', 'salon_address', 'salon_phone', 'salon_web', 'consent_version' ) as $k ) {
			$vals[ $k ] = sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
		}
		foreach ( array( 'from_email', 'notify_email' ) as $k ) {
			$vals[ $k ] = sanitize_email( (string) ( $p[ $k ] ?? '' ) );
		}
		$vals['confirmation_note'] = sanitize_textarea_field( (string) ( $p['confirmation_note'] ?? '' ) );
		$vals['consent_texts']     = array_map( 'sanitize_textarea_field', array_map( 'strval', (array) ( $p['consent_texts'] ?? array() ) ) );
		$tpl                       = array();
		foreach ( (array) ( $p['templates'] ?? array() ) as $k => $v ) {
			if ( array_key_exists( $k, Mailer::default_templates() ) ) {
				$tpl[ $k ] = wp_kses_post( (string) $v );
			}
		}
		$vals['templates'] = $tpl;
		Settings::update( $vals );
		self::back( 'sb-settings' );
	}
}
