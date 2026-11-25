<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Admini lehed: päevaplaan, juuksuriõpilased, teenused, kliendid, seaded.
 */
final class Admin {

	/** Admini lehe slug => töölaua vaate nimi. */
	public const VIEWS = array(
		'sb-planner'  => 'paevaplaan',
		'sb-kassa'    => 'kassa',
		'sb-month'    => 'toograafik',
		'sb-clients'  => 'kliendid',
		'sb-staff'    => 'praktikandid',
		'sb-services' => 'teenused',
		'sb-products' => 'tooted',
		'sb-colors'   => 'varviladu',
		'sb-pay'      => 'tasud',
		'sb-vat'      => 'kaibemaks',
		'sb-settings' => 'seaded',
		'sb-users'    => 'kasutajad',
	);

	/** Kas lehti näidatakse töölaual (true) või WP adminis (false). */
	public static bool $front = false;

	public static function init(): void {
		// Töölaualt saadetud vorm suunatakse pärast salvestamist töölauale tagasi.
		if ( Dashboard::from_dashboard() ) {
			self::$front = true;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		Kassa::init();
		Colors::init();
		foreach ( array( 'save_service', 'save_category', 'delete_service', 'save_staff', 'add_exception', 'delete_exception', 'save_settings', 'save_client', 'save_month', 'save_product', 'add_sale', 'delete_sale', 'pay_csv', 'vat_csv', 'shop_sync' ) as $a ) {
			add_action( 'admin_post_sb_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	public static function menu(): void {
		add_menu_page( __( 'Broneeringud', 'sasipea-broneering' ), __( 'Broneeringud', 'sasipea-broneering' ), 'sb_view_own', 'sb-planner', array( self::class, 'page_planner' ), 'dashicons-calendar-alt', 3 );
		add_submenu_page( 'sb-planner', __( 'Päevaplaan', 'sasipea-broneering' ), __( 'Päevaplaan', 'sasipea-broneering' ), 'sb_view_own', 'sb-planner', array( self::class, 'page_planner' ) );
		add_submenu_page( 'sb-planner', __( 'Kassa', 'sasipea-broneering' ), __( 'Kassa', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-kassa', array( Kassa::class, 'page' ) );
		add_submenu_page( 'sb-planner', __( 'Töögraafik', 'sasipea-broneering' ), __( 'Töögraafik', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-month', array( self::class, 'page_month' ) );
		add_submenu_page( 'sb-planner', __( 'Kliendid', 'sasipea-broneering' ), __( 'Kliendid', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-clients', array( self::class, 'page_clients' ) );
		add_submenu_page( 'sb-planner', __( 'Värviladu', 'sasipea-broneering' ), __( 'Värviladu', 'sasipea-broneering' ), 'sb_manage_bookings', 'sb-colors', array( Colors::class, 'page' ) );
		add_submenu_page( 'sb-planner', __( 'Juuksuriõpilased', 'sasipea-broneering' ), __( 'Juuksuriõpilased', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-staff', array( self::class, 'page_staff' ) );
		add_submenu_page( 'sb-planner', __( 'Teenused', 'sasipea-broneering' ), __( 'Teenused', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-services', array( self::class, 'page_services' ) );
		add_submenu_page( 'sb-planner', __( 'Tooted', 'sasipea-broneering' ), __( 'Tooted', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-products', array( self::class, 'page_products' ) );
		if ( current_user_can( 'sb_manage_settings' ) ) {
			add_submenu_page( 'sb-planner', __( 'Tasud', 'sasipea-broneering' ), __( 'Tasud', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-pay', array( self::class, 'page_pay' ) );
		} elseif ( ! empty( Settings::get()['trainee_view']['pay'] ) && Roles::current_staff_id() ) {
			add_submenu_page( 'sb-planner', __( 'Minu tasu', 'sasipea-broneering' ), __( 'Minu tasu', 'sasipea-broneering' ), 'sb_view_own', 'sb-pay', array( self::class, 'page_pay' ) );
		}
		add_submenu_page( 'sb-planner', __( 'Käibemaks', 'sasipea-broneering' ), __( 'Käibemaks', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-vat', array( self::class, 'page_vat' ) );
		add_submenu_page( 'sb-planner', __( 'Seaded', 'sasipea-broneering' ), __( 'Seaded', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-settings', array( self::class, 'page_settings' ) );
		add_submenu_page( 'sb-planner', __( 'Kasutajad', 'sasipea-broneering' ), __( 'Kasutajad', 'sasipea-broneering' ), 'sb_manage_settings', 'sb-users', array( Users::class, 'page' ) );
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
				'models'    => current_user_can( 'sb_manage_bookings' ) && Integrations\Modellid::active(),
				'today'     => ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m-d' ),
				'kassaUrl'  => current_user_can( 'sb_manage_bookings' ) && $in_admin ? self::url( 'sb-kassa&booking=' ) : '',
				'clientUrl' => $in_admin ? self::url( 'sb-clients&client=' ) : '',
				'colorsUrl' => current_user_can( 'sb_manage_bookings' ) && $in_admin ? self::url( 'sb-colors' ) : '',
			)
		);
	}

	public static function guard( string $cap, string $action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function back( string $page, array $args = array() ): void {
		$args = array_merge( array( 'sb_saved' => 1 ), $args );
		if ( isset( $args['sb_error'] ) ) {
			$args['sb_error'] = rawurlencode( (string) $args['sb_error'] );
		}
		wp_safe_redirect( add_query_arg( $args, self::url( $page ) ) );
		exit;
	}

	/**
	 * Lehe aadress: töölaual (/salong/) töölaua vaade, muidu WP admini leht.
	 * $page võib sisaldada lisaparameetreid kujul "sb-staff&edit=3".
	 */
	public static function url( string $page ): string {
		if ( ! self::$front ) {
			return admin_url( 'admin.php?page=' . $page );
		}
		[ $slug, $rest ] = array_pad( explode( '&', $page, 2 ), 2, '' );
		return Dashboard::url( self::VIEWS[ $slug ] ?? '' ) . ( '' !== $rest ? '&' . $rest : '' );
	}

	public static function notice(): void {
		if ( isset( $_GET['sb_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Salvestatud.', 'sasipea-broneering' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Arve kustutatud.', 'sasipea-broneering' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Kviitung saadetud.', 'sasipea-broneering' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['sb_error'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	public static function post( string $k, string $default = '' ): string {
		return isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification -- kontrollitud guard()'is.
	}

	/** "12:00-15:00, 15:30-19:00" -> [[720,900],[930,1140]] */
	public static function parse_ranges( string $s ): array {
		return Settings::parse_ranges( $s );
	}

	/** Kellaaja valiku skript ja stiil (algus ja lõpp valitakse, mitte ei kirjutata). Üks kord lehe kohta. */
	public static function range_picker(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo '<style>
			.sb-tr{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
			.sb-tr select,.sb-tr-pop select{min-width:92px}
			.sb-tr-dash{color:#646970}
			.sb-tr-manual{background:none;border:0;padding:0;margin:0 0 0 6px;color:#50575e;font-size:12px;text-decoration:underline;cursor:pointer}
			.sb-tr-pop{position:fixed;z-index:100000;display:grid;gap:8px;width:230px;padding:12px;background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.18);text-align:left;font-size:13px}
			.sb-tr-pop .sb-tr-row{display:flex;align-items:center;gap:6px}
			.sb-tr-pop .sb-tr-row select{flex:1;min-width:0}
			.sb-tr-pop .sb-tr-manual{margin:0;text-align:left}
		</style>';
		echo '<script src="' . esc_url( SB_URL . 'assets/js/timerange.js?ver=' . SB_VERSION ) . '" defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources -- sama leht töötab ka töölaual ilma wp_head'ita.
	}

	/** Valiku samm minutites: sama mis broneerimise ajasamm, aga mitte üle 30 minuti. */
	private static function range_step(): int {
		return (int) ( Settings::get()['slot_step'] ?? 30 ) < 30 ? 15 : 30;
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
					esc_url( self::url( 'sb-services&edit=' . $s['id'] ) ),
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
			// Uus teenus: vaikimisi teevad seda kõik aktiivsed juuksuriõpilased.
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

	/* ================= Juuksuriõpilased ================= */

	public static function page_staff(): void {
		$staff = Repo::staff_list( false );
		$id    = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit  = $id ? Repo::staff( $id ) : null;
		echo '<div class="wrap"><h1>' . esc_html__( 'Juuksuriõpilased', 'sasipea-broneering' ) . '</h1>';
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
				esc_url( self::url( 'sb-staff&edit=' . $s['id'] ) ),
				esc_html__( 'Muuda', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( self::url( 'sb-staff&edit=new' ) ) . '">' . esc_html__( 'Lisa juuksuriõpilane', 'sasipea-broneering' ) . '</a></p>';

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

		echo '<h2>' . ( $edit ? esc_html( $s['name'] ) : esc_html__( 'Uus juuksuriõpilane', 'sasipea-broneering' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_staff' );
		echo '<input type="hidden" name="action" value="sb_save_staff"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><table class="form-table">';
		printf( '<tr><th><label for="sb-sn">%s</label></th><td><input id="sb-sn" name="name" class="regular-text" required value="%s"></td></tr>', esc_html__( 'Nimi', 'sasipea-broneering' ), esc_attr( $s['name'] ) );
		printf( '<tr><th><label for="sb-sp">%s</label></th><td><input id="sb-sp" name="photo_url" type="url" class="regular-text" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Pildi URL', 'sasipea-broneering' ), esc_attr( $s['photo_url'] ), esc_html__( 'Lae pilt meediateeki ja kopeeri selle aadress siia.', 'sasipea-broneering' ) );
		echo '<tr><th><label for="sb-su">' . esc_html__( 'WordPressi kasutaja', 'sasipea-broneering' ) . '</label></th><td><select id="sb-su" name="user_id"><option value="0">—</option>';
		foreach ( get_users( array( 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( (int) $u->ID, (int) $s['user_id'], false ), esc_html( $u->display_name ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Seotud kasutaja näeb päevaplaanis oma graafikut. Talle antakse roll "Juuksuriõpilane", kui tal pole juhendaja ega admini õigusi.', 'sasipea-broneering' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Värv päevaplaanis', 'sasipea-broneering' ) . '</th><td><fieldset class="sb-colors"><legend class="screen-reader-text">' . esc_html__( 'Värv päevaplaanis', 'sasipea-broneering' ) . '</legend>';
		printf( '<label><input type="radio" name="color" value=""%s> %s</label> ', checked( '' === ( $s['color'] ?? '' ), true, false ), esc_html__( 'automaatne', 'sasipea-broneering' ) );
		foreach ( Repo::COLORS as $hex => $label ) {
			printf( '<label title="%2$s"><input type="radio" name="color" value="%1$s"%3$s> <span style="display:inline-block;width:22px;height:22px;border-radius:50%%;background:%1$s;vertical-align:middle" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></label> ', esc_attr( $hex ), esc_html( $label ), checked( $hex === ( $s['color'] ?? '' ), true, false ) );
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Juuksuriõpilane saab oma värvi ka ise päevaplaanis muuta, klõpsates oma nimetähtedega ringil.', 'sasipea-broneering' ) . '</p></td></tr>';
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="active" value="1"%s> %s</label></td></tr>', esc_html__( 'Olek', 'sasipea-broneering' ), checked( $s['active'], true, false ), esc_html__( 'Aktiivne (saab broneerida)', 'sasipea-broneering' ) );
		printf( '<tr><th><label for="sb-so">%s</label></th><td><input id="sb-so" name="sort_order" type="number" value="%d"></td></tr>', esc_html__( 'Järjekord', 'sasipea-broneering' ), (int) $s['sort_order'] );

		echo '<tr><th><label for="sb-lvl">' . esc_html__( 'Tase', 'sasipea-broneering' ) . '</label></th><td><select id="sb-lvl" name="level"><option value="">' . esc_html__( '— tasemeta (täishind, tavakestus) —', 'sasipea-broneering' ) . '</option>';
		foreach ( (array) Settings::get()['levels'] as $l ) {
			/* translators: 1: level name, 2: discount percent, 3: extra minutes */
			printf( '<option value="%s"%s>%s</option>', esc_attr( $l['id'] ), selected( $l['id'], $s['level'], false ), esc_html( sprintf( __( '%1$s (soodustus %2$d%%, lisaaeg %3$d min)', 'sasipea-broneering' ), $l['name'], (int) $l['discount_pct'], (int) $l['extra_min'] ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Tase määrab, kui palju on teenus selle juuksuriõpilase juures soodsam ja kui palju kauem see kestab. Tasemeid saab muuta Seadete lehel.', 'sasipea-broneering' ) . '</p></td></tr>';
		printf(
			'<tr><th>%s</th><td><label>%s <input name="discount_pct" type="number" min="0" max="100" style="width:80px" value="%s" placeholder="—"> %%</label> &nbsp; <label>%s <input name="extra_min" type="number" min="0" max="240" step="5" style="width:80px" value="%s" placeholder="—"> min</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Erisused', 'sasipea-broneering' ),
			esc_html__( 'Soodustus', 'sasipea-broneering' ),
			esc_attr( null === $s['discount_override'] ? '' : (string) $s['discount_override'] ),
			esc_html__( 'Lisaaeg', 'sasipea-broneering' ),
			esc_attr( null === $s['extra_override'] ? '' : (string) $s['extra_override'] ),
			esc_html__( 'Täida ainult siis, kui see juuksuriõpilane erineb oma tasemest. Tühi = taseme järgi.', 'sasipea-broneering' )
		);

		echo '<tr><th>' . esc_html__( 'Tasu', 'sasipea-broneering' ) . '</th><td>';
		$pay    = $s['pay'] ?? Payroll::normalize( null );
		$plabel = array(
			'service'  => array( __( 'Teenustelt', 'sasipea-broneering' ), __( '€ iga tehtud teenuse eest', 'sasipea-broneering' ) ),
			'material' => array( __( 'Materjali müügilt', 'sasipea-broneering' ), __( '€ iga müügirea eest', 'sasipea-broneering' ) ),
			'product'  => array( __( 'Toodete müügilt', 'sasipea-broneering' ), __( '€ iga müüdud toote eest', 'sasipea-broneering' ) ),
		);
		foreach ( $plabel as $k => $l ) {
			$v = 'fixed' === $pay[ $k ]['type'] ? $pay[ $k ]['value'] / 100 : $pay[ $k ]['value'];
			printf(
				'<p><label style="display:inline-block;width:150px" for="sb-pay-%1$s">%2$s</label><input id="sb-pay-%1$s" name="pay[%1$s][value]" inputmode="decimal" style="width:90px" value="%3$s"> <select name="pay[%1$s][type]" aria-label="%6$s"><option value="pct"%4$s>%% %7$s</option><option value="fixed"%5$s>%8$s</option></select></p>',
				esc_attr( $k ),
				esc_html( $l[0] ),
				esc_attr( str_replace( '.', ',', (string) round( $v, 2 ) ) ),
				selected( $pay[ $k ]['type'], 'pct', false ),
				selected( $pay[ $k ]['type'], 'fixed', false ),
				esc_attr__( 'Tasu liik', 'sasipea-broneering' ),
				esc_html__( 'käibest', 'sasipea-broneering' ),
				esc_html( $l[1] )
			);
		}
		echo '<p class="description">' . esc_html__( 'Teenuse tasu arvestatakse broneeringutelt staatusega "Kohal", kliendi makstud hinnast. 0 = tasu ei arvestata.', 'sasipea-broneering' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Nädalapõhi', 'sasipea-broneering' ) . '</th><td><p class="description">' . esc_html__( 'Tavaline nädal, mille järgi kuu töögraafik eeltäidetakse. Vali iga päeva algus ja lõpp. Pausiga päeva jaoks vajuta "kirjuta käsitsi": 10:00-13:00, 13:30-16:00.', 'sasipea-broneering' ) . '</p>';
		self::range_picker();
		$long = Format::days();
		foreach ( $long as $wd => $name ) {
			printf( '<p><label style="display:inline-block;width:110px" for="sb-wd%1$d">%2$s</label><input id="sb-wd%1$d" name="schedule[%1$d]" value="%3$s" placeholder="—" data-sb-range="inline" data-empty="%4$s" data-default="12:00-19:00" data-step="%5$d" aria-label="%2$s"></p>', (int) $wd, esc_html( ucfirst( $name ) ), esc_attr( self::ranges_str( $schedule[ $wd ] ?? array() ) ), esc_attr__( 'ei tööta', 'sasipea-broneering' ), self::range_step() );
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
			echo '<label>' . esc_html__( 'Tunnid', 'sasipea-broneering' ) . ' <input name="ranges" placeholder="10:00-14:00" data-sb-range="inline" data-empty="—" data-default="10:00-14:00" data-step="' . self::range_step() . '" aria-label="' . esc_attr__( 'Tunnid', 'sasipea-broneering' ) . '"></label> ';
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
			'pay'        => wp_json_encode( self::pay_from_post() ),
			'color'      => isset( Repo::COLORS[ strtolower( self::post( 'color' ) ) ] ) ? strtolower( self::post( 'color' ) ) : null,
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

	/** Tasumäärad vormist: protsent jääb protsendiks, kindel summa eurodes teisendatakse sentideks. */
	private static function pay_from_post(): array {
		$in  = (array) wp_unslash( $_POST['pay'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- kontrollitud guard()'is, väärtused teisendatakse arvuks.
		$raw = array();
		foreach ( Payroll::KINDS as $k ) {
			$type      = ( $in[ $k ]['type'] ?? 'pct' ) === 'fixed' ? 'fixed' : 'pct';
			$value     = (float) str_replace( ',', '.', sanitize_text_field( (string) ( $in[ $k ]['value'] ?? '0' ) ) );
			$raw[ $k ] = array( 'type' => $type, 'value' => 'fixed' === $type ? round( $value * 100 ) : $value );
		}
		return Payroll::normalize( $raw );
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
		$saved  = Repo::month_saved( $ym );
		$gated  = ! empty( Settings::get()['require_month'] ); // kas avalikuks tegemine on eraldi samm
		$staff  = Repo::staff_list( true );
		$counts = Repo::booking_counts( $ym );
		$title  = ucfirst( Format::months()[ (int) $first->format( 'n' ) ] ) . ' ' . $first->format( 'Y' );
		$url    = static fn( \DateTimeImmutable $d ) => esc_url( self::url( 'sb-month&month=' . $d->format( 'Y-m' ) ) );
		$short  = array( 1 => 'E', 'T', 'K', 'N', 'R', 'L', 'P' );

		echo '<div class="wrap sb-month"><h1>' . esc_html__( 'Töögraafik', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<style>
			.sb-month .sb-m-nav{display:flex;gap:8px;align-items:center;margin:12px 0;flex-wrap:wrap}
			.sb-month .sb-m-nav h2{margin:0 8px;min-width:180px;text-align:center}
			.sb-month .sb-m-scroll{overflow:auto;max-width:100%;max-height:80vh;border:1px solid #dcdcde;background:#fff}
			.sb-month table{border-collapse:separate;border-spacing:0;background:#fff}
			.sb-month th,.sb-month td{border:0;border-right:1px solid #dcdcde;border-bottom:1px solid #dcdcde;padding:4px;text-align:center;vertical-align:middle;white-space:nowrap}
			.sb-month thead th{background:#f6f7f7;position:sticky;top:0;z-index:2;font-size:12px;line-height:1.3;min-width:66px}
			.sb-month .sb-m-name{position:sticky;left:0;z-index:3;background:#fff;text-align:left;padding:6px 10px;min-width:170px;max-width:220px;white-space:normal;line-height:1.25;box-shadow:2px 0 0 #c3c4c7}
			.sb-month thead .sb-m-name{z-index:4;background:#f6f7f7}
			.sb-month a.sb-m-day{display:block;font-weight:600;text-decoration:underline;text-underline-offset:2px}
			.sb-month .is-closed{background:#f0f0f1;color:#8c8f94;min-width:26px}
			.sb-month thead th.is-closed{min-width:26px}
			.sb-month .is-week-start{border-left:2px solid #8c8f94}
			.sb-month thead th.is-today{background:#fff3c4}
			.sb-month td.is-on{background:#edf7ed}
			.sb-month td.sb-m-cell{position:relative;height:50px;vertical-align:top;padding-top:8px}
			.sb-month td.sb-m-cell input[type=checkbox]{display:block;margin:0 auto 3px}
			.sb-month .sb-m-hours{display:block;box-sizing:border-box;width:70px;max-width:none;min-height:0;height:22px;margin:0 auto;padding:0 1px;font-size:10.5px;line-height:20px;text-align:center}
			.sb-month .sb-m-hours:disabled{visibility:hidden}
			.sb-month td.is-closed .sb-m-hours:disabled{display:none}
			.sb-month td.sb-m-cell.is-closed.is-on{background:#e3f1e3}
			.sb-month .sb-m-hours[readonly]{cursor:pointer}
			.sb-month .sb-m-hours:focus:not([readonly]){position:absolute;left:2px;bottom:4px;width:180px;z-index:1;font-size:13px;text-align:left;padding:0 6px}
			.sb-month .sb-m-count{position:absolute;top:2px;right:2px;min-width:14px;padding:0 3px;border-radius:8px;background:#8a2424;color:#fff;font-size:10px;line-height:14px;font-weight:600}
			.sb-month .sb-m-sub{display:block;font-weight:400;font-size:11.5px;color:#50575e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.sb-month .sb-m-name input[type=checkbox]{width:14px;height:14px;min-width:0;margin:0 4px 0 0;vertical-align:-2px}
			.sb-month thead .sb-m-sub{font-size:10.5px}
			.sb-month .sb-m-badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600}
			.sb-month .sb-m-badge.is-ready{background:#e3f4ea;color:#00572c}
			.sb-month .sb-m-badge.is-draft{background:#fcf0d6;color:#6b4a00}
			.sb-month .sb-m-public{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #00733a;border-radius:6px}
			.sb-month .sb-m-public-hint{flex:1;min-width:220px;color:#50575e;font-size:13px}
			@media (max-width:700px){.sb-month .sb-m-name{min-width:100px;max-width:112px;padding:4px 6px;font-size:13px}.sb-month .sb-m-sub{font-size:10.5px}}
		</style>';
		echo '<div class="sb-m-nav"><a class="button" href="' . $url( $first->modify( '-1 month' ) ) . '">‹ ' . esc_html__( 'Eelmine kuu', 'sasipea-broneering' ) . '</a><h2>' . esc_html( $title ) . '</h2><a class="button" href="' . $url( $first->modify( '+1 month' ) ) . '">' . esc_html__( 'Järgmine kuu', 'sasipea-broneering' ) . ' ›</a> ';
		if ( $ready ) {
			echo '<span class="sb-m-badge is-ready">' . esc_html__( 'Avalik klientidele', 'sasipea-broneering' ) . '</span>';
		} elseif ( $saved ) {
			echo '<span class="sb-m-badge is-draft">' . esc_html__( 'Salvestatud, pole avalik', 'sasipea-broneering' ) . '</span>';
		} else {
			echo '<span class="sb-m-badge is-draft">' . esc_html__( 'Graafik on koostamata', 'sasipea-broneering' ) . '</span>';
		}
		echo '</div>';

		if ( ! $staff ) {
			echo '<p>' . esc_html__( 'Lisa kõigepealt juuksuriõpilased.', 'sasipea-broneering' ) . '</p></div>';
			return;
		}
		if ( $ready ) {
			echo '<div class="notice notice-success sb-m-state"><p><strong>' . esc_html__( 'See kuu on klientidele avalik: kliendid saavad selle kuu aegu broneerida.', 'sasipea-broneering' ) . '</strong></p></div>';
		} elseif ( $saved && $gated ) {
			echo '<div class="notice notice-warning sb-m-state"><p><strong>' . esc_html__( 'Selle kuu graafik on salvestatud, aga klientidele veel avalikuks tegemata.', 'sasipea-broneering' ) . '</strong> ' . esc_html__( 'Salongi töötajad näevad seda päevaplaanis, kliendid selle kuu aegu broneerida ei saa. Avalikuks tegemiseks pane linnuke "Avalik klientidele" ja salvesta.', 'sasipea-broneering' ) . '</p></div>';
		} elseif ( $gated ) {
			echo '<div class="notice notice-warning sb-m-state"><p><strong>' . esc_html__( 'See kuu on veel salvestamata ja kliendid selle kuu aegu broneerida ei saa.', 'sasipea-broneering' ) . '</strong> ' . esc_html__( 'Allolevad linnukesed on ainult nädalapõhja järgi eeltäidetud ettepanek. Vaata üle ja salvesta. Graafiku võib teha pikalt ette: klientidele läheb avalikuks ainult kuu, millel on linnuke "Avalik klientidele".', 'sasipea-broneering' ) . '</p></div>';
		}
		if ( ! $saved && ! $gated ) {
			echo '<p class="description">' . esc_html__( 'Allpool on eeltäidetud iga juuksuriõpilase nädalapõhi. Märgi, kes mis päeval töötab, muuda vajadusel kellaaegu ja salvesta. Kuni salvestamiseni kehtib selles kuus nädalapõhi.', 'sasipea-broneering' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Linnuke = töötab. Kellaaja muutmiseks klõpsa kellaajal ja vali algus ja lõpp. Olemasolevaid broneeringuid graafiku muutmine ei tühista.', 'sasipea-broneering' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Hall veerg on päev, mil salong on lahtiolekuaegade järgi suletud. Kui paned sinna kellelegi linnukese ja kellaaja, on salong sel päeval nendeks tundideks avatud ja kliendid saavad neid aegu broneerida. Samamoodi võib tavalisel päeval panna tunnid lahtiolekuajast varasemaks või hilisemaks.', 'sasipea-broneering' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_month' );
		echo '<input type="hidden" name="action" value="sb_save_month"><input type="hidden" name="month" value="' . esc_attr( $ym ) . '">';
		if ( $gated ) {
			echo '<p class="sb-m-public"><label><input type="checkbox" name="public" value="1"' . checked( $ready, true, false ) . '> <strong>' . esc_html__( 'Avalik klientidele', 'sasipea-broneering' ) . '</strong></label> <span class="sb-m-public-hint">' . esc_html__( 'Linnukesega kuu aegu saavad kliendid broneerida. Ilma linnukeseta on graafik ainult salongi enda jaoks.', 'sasipea-broneering' ) . '</span> <input type="submit" class="button button-primary" value="' . esc_attr__( 'Salvesta', 'sasipea-broneering' ) . '"></p>';
		}
		// Kuupäevad on veergudes ja juuksuriõpilased ridades, et palju õpilasi mahuks korraga ekraanile.
		$today    = ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m-d' );
		$fallback = self::fallback_hours();
		$step     = self::range_step();
		self::range_picker();
		$days     = array();
		for ( $d = $first; $d->format( 'Y-m' ) === $ym; $d = $d->modify( '+1 day' ) ) {
			$date   = $d->format( 'Y-m-d' );
			$wd     = (int) $d->format( 'N' );
			$days[] = array(
				'date'    => $date,
				'd'       => $d,
				'wd'      => $wd,
				'opening' => Settings::opening_for( $date ),
				'class'   => ( 1 === $wd ? ' is-week-start' : '' ) . ( $date === $today ? ' is-today' : '' ),
			);
		}
		echo '<div class="sb-m-scroll"><table><thead><tr><th scope="col" class="sb-m-name">' . esc_html__( 'Juuksuriõpilane', 'sasipea-broneering' ) . '</th>';
		foreach ( $days as $day ) {
			$d = $day['d'];
			/* translators: %s: date */
			$day_link = '<a class="sb-m-day" href="' . esc_url( self::url( 'sb-planner&day=' . $day['date'] ) ) . '" title="' . esc_attr( sprintf( __( 'Ava %s päevaplaan', 'sasipea-broneering' ), $d->format( 'j.m.Y' ) ) ) . '">' . esc_html( $short[ $day['wd'] ] ) . '<br>' . esc_html( $d->format( 'j.m' ) ) . '</a>';
			if ( ! $day['opening'] ) {
				echo '<th scope="col" class="is-closed' . esc_attr( $day['class'] ) . '" title="' . esc_attr__( 'suletud', 'sasipea-broneering' ) . '">' . $day_link . '</th>';
				continue;
			}
			echo '<th scope="col" class="' . esc_attr( trim( $day['class'] ) ) . '">' . $day_link . '<span class="sb-m-sub">' . esc_html( str_replace( '-', '–', self::ranges_str( $day['opening'] ) ) ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $staff as $s ) {
			printf(
				'<tr><th scope="row" class="sb-m-name">%s<span class="sb-m-sub">%s</span><label class="sb-m-sub"><input type="checkbox" class="sb-m-all" data-staff="%d"> %s</label></th>',
				esc_html( $s['name'] ),
				esc_html( self::terms_label( $s ) ),
				(int) $s['id'],
				esc_html__( 'kõik päevad', 'sasipea-broneering' )
			);
			foreach ( $days as $day ) {
				$date = $day['date'];
				$d    = $day['d'];
				// Suletud päeval nädalapõhi ei kehti: tunnid tulevad ainult siis, kui need on sellele päevale eraldi märgitud.
				$closed = ! $day['opening'];
				$ranges = $closed ? Repo::explicit_ranges( $s['id'], $date ) : Repo::planned_ranges( $s['id'], $date );
				$n      = (int) ( $counts[ $s['id'] ][ $date ] ?? 0 );
				printf(
					'<td class="%1$s"><input type="checkbox" name="on[%2$d][%3$s]" value="1" data-staff="%2$d" data-default="%4$s" aria-label="%5$s"%6$s%11$s><input class="sb-m-hours" name="hours[%2$d][%3$s]" value="%7$s" placeholder="%4$s" title="%7$s" aria-label="%8$s" data-sb-range="pop" data-step="%12$d"%9$s>%10$s</td>',
					esc_attr( 'sb-m-cell' . ( $closed ? ' is-closed' : '' ) . ( $ranges ? ' is-on' : '' ) . $day['class'] ),
					(int) $s['id'],
					esc_attr( $date ),
					esc_attr( self::ranges_str( $closed ? $fallback : $day['opening'] ) ),
					/* translators: 1: trainee name, 2: date */
					esc_attr( sprintf( __( '%1$s töötab %2$s', 'sasipea-broneering' ), $s['name'], $d->format( 'j.m' ) ) ),
					checked( (bool) $ranges, true, false ),
					esc_attr( self::ranges_str( $ranges ) ),
					/* translators: 1: trainee name, 2: date */
					esc_attr( sprintf( __( '%1$s kellaajad %2$s', 'sasipea-broneering' ), $s['name'], $d->format( 'j.m' ) ) ),
					$ranges ? '' : ' disabled',
					/* translators: %d: number of bookings */
					$n ? '<span class="sb-m-count" title="' . esc_attr( sprintf( _n( '%d broneering', '%d broneeringut', $n, 'sasipea-broneering' ), $n ) ) . '">' . (int) $n . '</span>' : '',
					$closed ? ' data-closed="1" title="' . esc_attr__( 'Salong on sel päeval tavaliselt suletud. Linnuke avab selle päeva nendeks tundideks ka klientidele.', 'sasipea-broneering' ) . '"' : '',
					$step
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		submit_button( __( 'Salvesta', 'sasipea-broneering' ) );
		echo '</form>';
		echo "<script>
		(function(){
			var f=document.querySelector('.sb-month form');
			function sync(cb){var t=cb.parentNode.querySelector('.sb-m-hours');t.disabled=!cb.checked;if(cb.checked&&!t.value)t.value=cb.getAttribute('data-default');cb.parentNode.classList.toggle('is-on',cb.checked);}
			var td=f.querySelector('th.is-today'),sc=f.querySelector('.sb-m-scroll');if(td&&sc){sc.scrollLeft=Math.max(0,td.offsetLeft-f.querySelector('.sb-m-name').offsetWidth-70);}
			f.addEventListener('change',function(e){
				var el=e.target;
				if(el.classList.contains('sb-m-all')){f.querySelectorAll('td input[type=checkbox][data-staff=\"'+el.getAttribute('data-staff')+'\"]:not([data-closed])').forEach(function(cb){cb.checked=el.checked;sync(cb);});}
				else if(el.type==='checkbox'){sync(el);}
				else if(el.classList.contains('sb-m-hours')){el.title=el.value;}
			});
		})();
		</script>";
		echo '</div>';
	}

	/** Vaikimisi tunnid suletud päeva jaoks: esimese avatud nädalapäeva lahtiolekuaeg. */
	private static function fallback_hours(): array {
		foreach ( Settings::get()['opening_hours'] ?? array() as $day ) {
			if ( $day ) {
				return array_map( fn( $r ) => array( Settings::to_min( $r[0] ), Settings::to_min( $r[1] ) ), $day );
			}
		}
		return array( array( 600, 1080 ) );
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
				if ( empty( $p['on'][ $sid ][ $date ] ) ) {
					$plan[ $sid ][ $date ] = array();
					continue;
				}
				// Linnuke ilma loetava kellaajata tähendab tervet lahtiolekuaega; suletud päeval tavalist tööpäeva.
				$plan[ $sid ][ $date ] = self::parse_ranges( sanitize_text_field( (string) ( $p['hours'][ $sid ][ $date ] ?? '' ) ) ) ?: ( Settings::opening_for( $date ) ?: self::fallback_hours() );
			}
		}
		// Kui seade "ainult koostatud kuud" on väljas, kehtib salvestatud graafik kohe ka klientidele.
		Repo::save_month( $ym, $plan, empty( Settings::get()['require_month'] ) || ! empty( $p['public'] ) );
		self::back( 'sb-month', array( 'month' => $ym ) );
	}

	/* ================= Tooted ================= */

	public static function page_products(): void {
		$products = Repo::products( false );
		$id       = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit     = array( 'id' => 0, 'name' => '', 'price_cents' => 0, 'active' => true, 'sort_order' => count( $products ) );
		foreach ( $products as $p ) {
			if ( $p['id'] === $id ) {
				$edit = $p;
			}
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Tooted', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<p class="description">' . esc_html__( 'Müügitooted, mida saab päevaplaanis broneeringu juurde või Tasude lehel eraldi müügina märkida.', 'sasipea-broneering' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>' . esc_html__( 'Toode', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Hind', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Olek', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $products ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Tooteid pole veel lisatud.', 'sasipea-broneering' ) . '</td></tr>';
		}
		foreach ( $products as $p ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
				esc_html( $p['name'] ),
				esc_html( $p['price_label'] ),
				$p['active'] ? esc_html__( 'müügis', 'sasipea-broneering' ) : '<em>' . esc_html__( 'peidetud', 'sasipea-broneering' ) . '</em>',
				esc_url( self::url( 'sb-products&edit=' . $p['id'] ) ),
				esc_html__( 'Muuda', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';
		echo '<h2>' . ( $edit['id'] ? esc_html__( 'Muuda toodet', 'sasipea-broneering' ) : esc_html__( 'Lisa toode', 'sasipea-broneering' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_save_product' );
		echo '<input type="hidden" name="action" value="sb_save_product"><input type="hidden" name="id" value="' . (int) $edit['id'] . '"><table class="form-table">';
		printf( '<tr><th><label for="sb-pn">%s</label></th><td><input id="sb-pn" name="name" class="regular-text" required value="%s"></td></tr>', esc_html__( 'Nimi', 'sasipea-broneering' ), esc_attr( $edit['name'] ) );
		printf( '<tr><th><label for="sb-pp">%s</label></th><td><input id="sb-pp" name="price" inputmode="decimal" required value="%s"></td></tr>', esc_html__( 'Hind (€)', 'sasipea-broneering' ), esc_attr( number_format( $edit['price_cents'] / 100, 2, ',', '' ) ) );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="active" value="1"%s> %s</label></td></tr>', esc_html__( 'Olek', 'sasipea-broneering' ), checked( $edit['active'], true, false ), esc_html__( 'Müügis', 'sasipea-broneering' ) );
		printf( '<tr><th><label for="sb-po">%s</label></th><td><input id="sb-po" name="sort_order" type="number" value="%d"></td></tr>', esc_html__( 'Järjekord', 'sasipea-broneering' ), (int) $edit['sort_order'] );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public static function handle_save_product(): void {
		global $wpdb;
		self::guard( 'sb_manage_settings', 'sb_save_product' );
		$id   = (int) self::post( 'id' );
		$data = array(
			'name'        => self::post( 'name' ),
			'price_cents' => max( 0, (int) round( (float) str_replace( ',', '.', self::post( 'price' ) ) * 100 ) ),
			'active'      => self::post( 'active' ) ? 1 : 0,
			'sort_order'  => (int) self::post( 'sort_order' ),
		);
		if ( $id ) {
			$wpdb->update( Install::table( 'products' ), $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( Install::table( 'products' ), $data );
		}
		self::back( 'sb-products' );
	}

	/* ================= Tasud ================= */

	/** Kuu tasude read: iga nähtava juuksuriõpilase kokkuvõte ja alusandmed. */
	private static function pay_rows( string $ym ): array {
		$data  = Repo::payroll_data( $ym );
		$all   = current_user_can( 'sb_manage_settings' );
		$own   = Roles::current_staff_id();
		$rows  = array();
		foreach ( Repo::staff_list( false ) as $s ) {
			if ( ! $all && $s['id'] !== $own ) {
				continue;
			}
			$d = $data[ $s['id'] ] ?? array( 'services' => array(), 'sales' => array() );
			if ( ! $s['active'] && ! $d['services'] && ! $d['sales'] ) {
				continue;
			}
			$rows[] = array(
				'staff'   => $s,
				'data'    => $d,
				'summary' => Payroll::summarize( $s['pay'], array_map( 'intval', array_column( $d['services'], 'price_cents' ) ), $d['sales'] ),
			);
		}
		return $rows;
	}

	private static function rate_label( array $rate, string $unit ): string {
		if ( 'fixed' === $rate['type'] ) {
			return Settings::money( (int) $rate['value'] ) . '/' . $unit;
		}
		return rtrim( rtrim( number_format( $rate['value'], 2, ',', '' ), '0' ), ',' ) . '%';
	}

	public static function page_pay(): void {
		$manager = current_user_can( 'sb_manage_settings' );
		if ( ! $manager && ( empty( Settings::get()['trainee_view']['pay'] ) || ! Roles::current_staff_id() ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		$ym    = self::month_param();
		$first = new \DateTimeImmutable( $ym . '-01', Settings::tz() );
		$title = ucfirst( Format::months()[ (int) $first->format( 'n' ) ] ) . ' ' . $first->format( 'Y' );
		$url   = static fn( \DateTimeImmutable $d ) => esc_url( self::url( 'sb-pay&month=' . $d->format( 'Y-m' ) ) );
		$rows  = self::pay_rows( $ym );
		$m     = static fn( int $c ) => esc_html( Settings::money( $c ) );

		echo '<div class="wrap sb-pay"><h1>' . esc_html( $manager ? __( 'Tasud', 'sasipea-broneering' ) : __( 'Minu tasu', 'sasipea-broneering' ) ) . '</h1>';
		self::notice();
		echo '<style>.sb-pay .sb-m-nav{display:flex;gap:8px;align-items:center;margin:12px 0}.sb-pay .sb-m-nav h2{margin:0 8px;min-width:180px;text-align:center}.sb-pay td.num,.sb-pay th.num{text-align:right;white-space:nowrap}.sb-pay tfoot th{font-weight:700}.sb-pay .sb-pay-rate{display:block;font-size:12px;color:#50575e}.sb-pay details{margin:8px 0;max-width:1100px}.sb-pay summary{cursor:pointer;font-weight:600}</style>';
		echo '<div class="sb-m-nav"><a class="button" href="' . $url( $first->modify( '-1 month' ) ) . '">‹ ' . esc_html__( 'Eelmine kuu', 'sasipea-broneering' ) . '</a><h2>' . esc_html( $title ) . '</h2><a class="button" href="' . $url( $first->modify( '+1 month' ) ) . '">' . esc_html__( 'Järgmine kuu', 'sasipea-broneering' ) . ' ›</a>';
		if ( $manager ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_pay_csv&month=' . $ym ), 'sb_pay_csv' ) ) . '">' . esc_html__( 'Laadi alla CSV', 'sasipea-broneering' ) . '</a>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Teenused lähevad arvesse siis, kui broneeringu staatus on "Kohal". Materjal ja tooted märgitakse päevaplaanis broneeringu juurde. See on tasu arvestus, mitte palgaleht: makse siin ei arvutata.', 'sasipea-broneering' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Juuksuriõpilane', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Teenuseid', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Teenuste käive', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Tasu teenustelt', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Materjali müük', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Tasu materjalilt', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Toodete müük', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Tasu toodetelt', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Tasu kokku', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
		$tot = array_fill_keys( array( 'n', 'st', 'sp', 'mt', 'mp', 'pt', 'pp', 'all' ), 0 );
		foreach ( $rows as $r ) {
			$x   = $r['summary'];
			$pay = $r['staff']['pay'];
			printf(
				'<tr><td><strong>%s</strong></td><td class="num">%d</td><td class="num">%s</td><td class="num">%s<span class="sb-pay-rate">%s</span></td><td class="num">%s</td><td class="num">%s<span class="sb-pay-rate">%s</span></td><td class="num">%s</td><td class="num">%s<span class="sb-pay-rate">%s</span></td><td class="num"><strong>%s</strong></td></tr>',
				esc_html( $r['staff']['name'] ),
				(int) $x['service']['count'],
				$m( $x['service']['turnover'] ),
				$m( $x['service']['pay'] ),
				esc_html( self::rate_label( $pay['service'], __( 'teenus', 'sasipea-broneering' ) ) ),
				$m( $x['material']['turnover'] ),
				$m( $x['material']['pay'] ),
				esc_html( self::rate_label( $pay['material'], __( 'rida', 'sasipea-broneering' ) ) ),
				$m( $x['product']['turnover'] ),
				$m( $x['product']['pay'] ),
				esc_html( self::rate_label( $pay['product'], __( 'tk', 'sasipea-broneering' ) ) ),
				$m( $x['total_pay'] )
			);
			$tot['n']   += $x['service']['count'];
			$tot['st']  += $x['service']['turnover'];
			$tot['sp']  += $x['service']['pay'];
			$tot['mt']  += $x['material']['turnover'];
			$tot['mp']  += $x['material']['pay'];
			$tot['pt']  += $x['product']['turnover'];
			$tot['pp']  += $x['product']['pay'];
			$tot['all'] += $x['total_pay'];
		}
		echo '</tbody>';
		if ( count( $rows ) > 1 ) {
			printf( '<tfoot><tr><th>%s</th><th class="num">%d</th><th class="num">%s</th><th class="num">%s</th><th class="num">%s</th><th class="num">%s</th><th class="num">%s</th><th class="num">%s</th><th class="num">%s</th></tr></tfoot>', esc_html__( 'Kokku', 'sasipea-broneering' ), (int) $tot['n'], $m( $tot['st'] ), $m( $tot['sp'] ), $m( $tot['mt'] ), $m( $tot['mp'] ), $m( $tot['pt'] ), $m( $tot['pp'] ), $m( $tot['all'] ) );
		}
		echo '</table>';

		// Alusandmed juuksuriõpilase kaupa.
		foreach ( $rows as $r ) {
			if ( ! $r['data']['services'] && ! $r['data']['sales'] ) {
				continue;
			}
			echo '<details' . ( $manager ? '' : ' open' ) . '><summary>' . esc_html( $r['staff']['name'] ) . ': ' . esc_html__( 'read', 'sasipea-broneering' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kuupäev', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Liik', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Kirjeldus', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Kogus', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Summa', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Tasu', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( self::pay_lines( $r ) as $l ) {
				echo '<tr><td>' . esc_html( $l['date'] ) . '</td><td>' . esc_html( $l['kind_label'] ) . '</td><td>' . esc_html( $l['label'] ) . '</td><td class="num">' . esc_html( $l['qty'] ) . '</td><td class="num">' . $m( $l['total'] ) . '</td><td class="num">' . $m( $l['pay'] ) . '</td><td>';
				if ( $manager && $l['sale_id'] ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( 'sb_delete_sale' );
					echo '<input type="hidden" name="action" value="sb_delete_sale"><input type="hidden" name="id" value="' . (int) $l['sale_id'] . '"><input type="hidden" name="month" value="' . esc_attr( $ym ) . '">';
					submit_button( __( 'Eemalda', 'sasipea-broneering' ), 'link-delete small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></details>';
		}

		if ( $manager ) {
			$today = ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m-d' );
			echo '<h2>' . esc_html__( 'Lisa müük ilma broneeringuta', 'sasipea-broneering' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'sb_add_sale' );
			echo '<input type="hidden" name="action" value="sb_add_sale"><input type="hidden" name="month" value="' . esc_attr( $ym ) . '">';
			echo '<label>' . esc_html__( 'Müüja', 'sasipea-broneering' ) . ' <select name="staff_id" required>';
			foreach ( Repo::staff_list( true ) as $s ) {
				printf( '<option value="%d">%s</option>', (int) $s['id'], esc_html( $s['name'] ) );
			}
			echo '</select></label> <label>' . esc_html__( 'Mida', 'sasipea-broneering' ) . ' <select name="item" required><optgroup label="' . esc_attr__( 'Tooted (tükkides)', 'sasipea-broneering' ) . '">';
			foreach ( Repo::products() as $p ) {
				printf( '<option value="product:%d">%s (%s)</option>', (int) $p['id'], esc_html( $p['name'] ), esc_html( $p['price_label'] ) );
			}
			echo '</optgroup><optgroup label="' . esc_attr__( 'Materjal (grammides)', 'sasipea-broneering' ) . '">';
			foreach ( (array) Settings::get()['materials'] as $mat ) {
				printf( '<option value="material:%s">%s (%s/g)</option>', esc_attr( $mat['id'] ), esc_html( $mat['name'] ), esc_html( Settings::money( (int) $mat['cents_per_g'] ) ) );
			}
			echo '</optgroup></select></label> ';
			if ( Integrations\Woo::enabled() ) {
				echo '<label>' . esc_html__( 'või poe triipkood / tootekood', 'sasipea-broneering' ) . ' <input name="shop_code" style="width:150px" autocomplete="off"></label> ';
			}
			echo '<label>' . esc_html__( 'Kogus', 'sasipea-broneering' ) . ' <input name="qty" inputmode="decimal" style="width:80px" value="1" required></label> <label>' . esc_html__( 'Kuupäev', 'sasipea-broneering' ) . ' <input type="date" name="day" value="' . esc_attr( $today ) . '" required></label> ';
			submit_button( __( 'Lisa müük', 'sasipea-broneering' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/** Ühe juuksuriõpilase read kuupäeva järgi: teenused ja müügid koos tasuga. */
	private static function pay_lines( array $r ): array {
		$pay   = $r['staff']['pay'];
		$kinds = array( 'material' => __( 'materjal', 'sasipea-broneering' ), 'product' => __( 'toode', 'sasipea-broneering' ) );
		$out   = array();
		foreach ( $r['data']['services'] as $b ) {
			$out[] = array(
				'sort'       => $b['start_utc'],
				'date'       => Format::local( $b['start_utc'] )->format( 'd.m.Y' ),
				'kind_label' => __( 'teenus', 'sasipea-broneering' ),
				'label'      => $b['service_name'] . ' · ' . trim( $b['first_name'] . ' ' . $b['last_name'] ),
				'qty'        => '1',
				'total'      => (int) $b['price_cents'],
				'pay'        => Payroll::amount( $pay['service'], (int) $b['price_cents'] ),
				'sale_id'    => 0,
			);
		}
		foreach ( $r['data']['sales'] as $s ) {
			$k     = 'product' === $s['kind'] ? 'product' : 'material';
			$out[] = array(
				'sort'       => $s['sold_at'],
				'date'       => Format::local( $s['sold_at'] )->format( 'd.m.Y' ),
				'kind_label' => $kinds[ $k ],
				'label'      => $s['label'] . ( $s['woo_order_id'] ? ' · ' . sprintf( /* translators: %d: shop order number */ __( 'poe tellimus %d', 'sasipea-broneering' ), $s['woo_order_id'] ) : '' ),
				'qty'        => $s['qty_label'],
				'total'      => $s['total_cents'],
				'pay'        => Payroll::amount( $pay[ $k ], $s['total_cents'], 'product' === $k ? $s['qty'] : 1.0 ),
				'sale_id'    => $s['id'],
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['sort'], $b['sort'] ) );
		return $out;
	}

	public static function handle_add_sale(): void {
		self::guard( 'sb_manage_settings', 'sb_add_sale' );
		$day = self::post( 'day' );
		$ym  = self::post( 'month' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$ts  = ( new \DateTimeImmutable( $day . ' 12:00:00', Settings::tz() ) )->getTimestamp();
			$item = self::post( 'item' );
			$res  = null;
			$code = self::post( 'shop_code' );
			if ( '' !== $code ) {
				// Veebipoe toode tootekoodi või triipkoodi järgi: peab leiduma täpselt üks vaste.
				$found = Integrations\Woo::search( $code );
				$exact = is_wp_error( $found ) ? array() : array_values( array_filter( $found, static fn( $f ) => ! empty( $f['exact'] ) ) );
				if ( is_wp_error( $found ) ) {
					$res = $found;
				} elseif ( 1 !== count( $exact ) ) {
					$res = new \WP_Error( 'sb_invalid', __( 'Selle koodiga toodet poest ei leitud.', 'sasipea-broneering' ) );
				} else {
					$item = $exact[0]['key'];
				}
			}
			$res = $res ?? Repo::add_sale( (int) self::post( 'staff_id' ), null, $item, (float) str_replace( ',', '.', self::post( 'qty' ) ), $ts );
			if ( is_wp_error( $res ) ) {
				self::back( 'sb-pay', array( 'month' => $ym, 'sb_saved' => null, 'sb_error' => $res->get_error_message() ) );
			}
			$ym = substr( $day, 0, 7 );
		}
		self::back( 'sb-pay', array( 'month' => $ym ) );
	}

	public static function handle_delete_sale(): void {
		self::guard( 'sb_manage_settings', 'sb_delete_sale' );
		$res = Repo::delete_sale( (int) self::post( 'id' ) );
		if ( is_wp_error( $res ) ) {
			self::back( 'sb-pay', array( 'month' => self::post( 'month' ), 'sb_saved' => null, 'sb_error' => $res->get_error_message() ) );
		}
		self::back( 'sb-pay', array( 'month' => self::post( 'month' ) ) );
	}

	public static function handle_pay_csv(): void {
		if ( ! current_user_can( 'sb_manage_settings' ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		check_admin_referer( 'sb_pay_csv' );
		$ym  = self::month_param();
		$eur = static fn( int $c ) => number_format( $c / 100, 2, ',', '' );
		// Valemisüsti vältimiseks ei tohi lahter alata märgiga = + - @.
		$safe = static fn( string $v ) => preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tasud-' . $ym . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM, et Excel loeks täpitähti õigesti.
		fputcsv( $out, array( 'Juuksuriõpilane', 'Kuupäev', 'Liik', 'Kirjeldus', 'Kogus', 'Summa (€)', 'Tasu (€)' ), ';' );
		foreach ( self::pay_rows( $ym ) as $r ) {
			foreach ( self::pay_lines( $r ) as $l ) {
				fputcsv( $out, array( $safe( $r['staff']['name'] ), $l['date'], $l['kind_label'], $safe( $l['label'] ), $l['qty'], $eur( $l['total'] ), $eur( $l['pay'] ) ), ';' );
			}
			fputcsv( $out, array( $safe( $r['staff']['name'] ), '', 'KOKKU', '', '', $eur( $r['summary']['total_turnover'] ), $eur( $r['summary']['total_pay'] ) ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ================= Käibemaks ================= */

	/** Kuu käibemaksu aruanne: tehtud teenused (staatus "Kohal") ning materjali ja toodete müük. */
	private static function vat_report( string $ym ): array {
		$rows = array();
		$shop = array( 'count' => 0, 'gross' => 0 );
		foreach ( Repo::payroll_data( $ym ) as $d ) {
			foreach ( $d['services'] as $b ) {
				$rows[] = array( 'kind' => 'service', 'date' => Format::local( $b['start_utc'] )->format( 'Y-m-d' ), 'cents' => (int) $b['price_cents'] );
			}
			foreach ( $d['sales'] as $s ) {
				// Veebipoe laost müüdud toode on poe (teise ettevõtte) tellimus ja käive, mitte salongi oma.
				if ( $s['woo_order_id'] ) {
					$shop['count']++;
					$shop['gross'] += (int) $s['total_cents'];
					continue;
				}
				$rows[] = array( 'kind' => 'product' === $s['kind'] ? 'product' : 'material', 'date' => Format::local( $s['sold_at'] )->format( 'Y-m-d' ), 'cents' => (int) $s['total_cents'] );
			}
		}
		return Vat::report( (array) Settings::get()['vat_history'], $rows ) + array( 'shop' => $shop );
	}

	private static function vat_kinds(): array {
		return array(
			'service'  => __( 'Teenused', 'sasipea-broneering' ),
			'material' => __( 'Materjali müük', 'sasipea-broneering' ),
			'product'  => __( 'Toodete müük', 'sasipea-broneering' ),
		);
	}

	public static function page_vat(): void {
		$ym     = self::month_param();
		$first  = new \DateTimeImmutable( $ym . '-01', Settings::tz() );
		$title  = ucfirst( Format::months()[ (int) $first->format( 'n' ) ] ) . ' ' . $first->format( 'Y' );
		$url    = static fn( \DateTimeImmutable $d ) => esc_url( self::url( 'sb-vat&month=' . $d->format( 'Y-m' ) ) );
		$report = self::vat_report( $ym );
		$m      = static fn( int $c ) => esc_html( number_format( $c / 100, 2, ',', ' ' ) . ' €' );
		$kinds  = self::vat_kinds();
		$pct    = static fn( float $r ) => rtrim( rtrim( number_format( $r, 2, ',', '' ), '0' ), ',' ) . '%';

		echo '<div class="wrap sb-pay"><h1>' . esc_html__( 'Käibemaksu aruanne', 'sasipea-broneering' ) . '</h1>';
		echo '<style>.sb-pay .sb-m-nav{display:flex;gap:8px;align-items:center;margin:12px 0}.sb-pay .sb-m-nav h2{margin:0 8px;min-width:180px;text-align:center}.sb-pay td.num,.sb-pay th.num{text-align:right;white-space:nowrap}.sb-pay tfoot th{font-weight:700}</style>';
		echo '<div class="sb-m-nav"><a class="button" href="' . $url( $first->modify( '-1 month' ) ) . '">‹ ' . esc_html__( 'Eelmine kuu', 'sasipea-broneering' ) . '</a><h2>' . esc_html( $title ) . '</h2><a class="button" href="' . $url( $first->modify( '+1 month' ) ) . '">' . esc_html__( 'Järgmine kuu', 'sasipea-broneering' ) . ' ›</a> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_vat_csv&month=' . $ym ), 'sb_vat_csv' ) ) . '">' . esc_html__( 'Laadi alla CSV', 'sasipea-broneering' ) . '</a></div>';
		if ( ! Vat::normalize( Settings::get()['vat_history'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Käibemaksu määra pole seadistatud, seega käibemaksu ei arvestata ja aruanne näitab ainult käivet. Määra saad lisada lehel Seaded → Käibemaks.', 'sasipea-broneering' ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'Arvesse lähevad teenused staatusega "Kohal" teenuse kuupäeva järgi ning materjali ja toodete müük müügi kuupäeva järgi. Hinnad on käibemaksuga; käibemaks arvutatakse rea kogusummalt. Veebipoe laost müüdud tooted siia ei kuulu: need on veebipoe tellimused ja lähevad veebipoe ettevõtte käibesse ja käibemaksu arvestusse. Aruanne on abimaterjal raamatupidajale, mitte KMD.', 'sasipea-broneering' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__( 'Liik', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Määr', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Ridu', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Käive käibemaksuga', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Käibemaksuta', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Käibemaks', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
		if ( ! $report['lines'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Selles kuus müüke ei ole.', 'sasipea-broneering' ) . '</td></tr>';
		}
		foreach ( $report['lines'] as $l ) {
			printf( '<tr><td>%s</td><td class="num">%s</td><td class="num">%d</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>', esc_html( $kinds[ $l['kind'] ] ?? $l['kind'] ), esc_html( $pct( $l['rate'] ) ), (int) $l['count'], $m( $l['gross'] ), $m( $l['net'] ), $m( $l['vat'] ) );
		}
		echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__( 'Kokku', 'sasipea-broneering' ) . '</th><th class="num">' . $m( $report['total']['gross'] ) . '</th><th class="num">' . $m( $report['total']['net'] ) . '</th><th class="num">' . $m( $report['total']['vat'] ) . '</th></tr></tfoot></table>';
		if ( $report['shop']['count'] ) {
			/* translators: 1: number of sales, 2: amount */
			echo '<p>' . esc_html( sprintf( __( 'Lisaks müüdi salongis veebipoe laost %1$d rida summas %2$s. See on veebipoe tellimustes ja ei ole selle aruande käibes.', 'sasipea-broneering' ), $report['shop']['count'], number_format( $report['shop']['gross'] / 100, 2, ',', ' ' ) . ' €' ) ) . '</p>';
		}
		echo '</div>';
	}

	public static function handle_vat_csv(): void {
		if ( ! current_user_can( 'sb_manage_settings' ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		check_admin_referer( 'sb_vat_csv' );
		$ym     = self::month_param();
		$report = self::vat_report( $ym );
		$kinds  = self::vat_kinds();
		$eur    = static fn( int $c ) => number_format( $c / 100, 2, ',', '' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kaibemaks-' . $ym . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Kuu', 'Liik', 'Määr (%)', 'Ridu', 'Käive käibemaksuga (€)', 'Käibemaksuta (€)', 'Käibemaks (€)' ), ';' );
		foreach ( $report['lines'] as $l ) {
			fputcsv( $out, array( $ym, $kinds[ $l['kind'] ] ?? $l['kind'], str_replace( '.', ',', (string) $l['rate'] ), $l['count'], $eur( $l['gross'] ), $eur( $l['net'] ), $eur( $l['vat'] ) ), ';' );
		}
		fputcsv( $out, array( $ym, 'KOKKU', '', '', $eur( $report['total']['gross'] ), $eur( $report['total']['net'] ), $eur( $report['total']['vat'] ) ), ';' );
		if ( $report['shop']['count'] ) {
			fputcsv( $out, array( $ym, 'Veebipoe laost müüdud (ei ole käibes)', '', $report['shop']['count'], $eur( $report['shop']['gross'] ), '', '' ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ================= Kliendid ================= */

	public static function page_clients(): void {
		global $wpdb;
		$q  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$id = isset( $_GET['client'] ) ? (int) $_GET['client'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap"><h1>' . esc_html__( 'Kliendid', 'sasipea-broneering' ) . '</h1>';
		self::notice();
		echo '<form method="get"><input type="hidden" name="' . ( self::$front ? 'vaade' : 'page' ) . '" value="' . ( self::$front ? 'kliendid' : 'sb-clients' ) . '"><label class="screen-reader-text" for="sb-q">' . esc_html__( 'Otsi', 'sasipea-broneering' ) . '</label><input id="sb-q" type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Nimi, telefon või e-post', 'sasipea-broneering' ) . '"> ';
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
				$formulas = Colors::client_formulas( $id );
				echo '<h3>' . esc_html__( 'Värvid', 'sasipea-broneering' ) . '</h3>';
				if ( ! $formulas ) {
					echo '<p class="description">' . esc_html__( 'Värve pole veel märgitud. Lisa need päevaplaanis broneeringu juures.', 'sasipea-broneering' ) . '</p>';
				} else {
					echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Kuupäev', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Teenus', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Juuksuriõpilane', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Värvid ja grammid', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
					foreach ( $formulas as $f ) {
						echo '<tr><td>' . esc_html( $f['date'] ) . '</td><td>' . esc_html( $f['service'] ) . '</td><td>' . esc_html( $f['staff'] ) . '</td><td>';
						foreach ( $f['items'] as $it ) {
							echo '<div><strong>' . esc_html( $it['code'] ) . '</strong> ' . esc_html( $it['name'] ) . ' · ' . esc_html( Colors::g( $it['grams'] ) ) . '</div>';
						}
						echo '</td></tr>';
					}
					echo '</tbody></table>';
				}
			}
		} elseif ( '' !== $q ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-top:12px"><tbody>';
			foreach ( Repo::search_clients( $q, 50 ) as $c ) {
				printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_url( self::url( 'sb-clients&client=' . $c['id'] ) ), esc_html( trim( $c['first_name'] . ' ' . $c['last_name'] ) ), esc_html( $c['phone'] ), esc_html( $c['email'] ) );
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

		self::range_picker();
		echo '<h2>' . esc_html__( 'Lahtiolekuajad', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Vali iga päeva algus ja lõpp. "suletud" = salong on sel päeval kinni.', 'sasipea-broneering' ) . '</p><table class="form-table">';
		foreach ( Format::days() as $wd => $name ) {
			$r = array_map( fn( $x ) => array( Settings::to_min( $x[0] ), Settings::to_min( $x[1] ) ), $s['opening_hours'][ (string) $wd ] ?? array() );
			printf( '<tr><th><label for="sb-oh%1$d">%2$s</label></th><td><input id="sb-oh%1$d" name="opening[%1$d]" value="%3$s" placeholder="%4$s" data-sb-range="inline" data-empty="%4$s" data-default="12:00-19:00" data-step="%5$d" aria-label="%2$s"></td></tr>', (int) $wd, esc_html( ucfirst( $name ) ), esc_attr( self::ranges_str( $r ) ), esc_attr__( 'suletud', 'sasipea-broneering' ), self::range_step() );
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Broneerimise reeglid', 'sasipea-broneering' ) . '</h2><table class="form-table">';
		$num = array(
			'slot_step'        => array( __( 'Ajasamm (min)', 'sasipea-broneering' ), __( 'Mitme minuti tagant algusaegu pakutakse, nt 15 või 30.', 'sasipea-broneering' ) ),
			'buffer'           => array( __( 'Puhver teenuste vahel (min)', 'sasipea-broneering' ), __( 'Sama juuksuriõpilase kahe kliendi vahele jääv aeg.', 'sasipea-broneering' ) ),
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
			esc_html__( 'Aegu pakutakse ainult nendes kuudes, mille töögraafik on koostatud (Broneeringud → Töögraafik). Muidu kehtib koostamata kuus iga juuksuriõpilase nädalapõhi.', 'sasipea-broneering' )
		);

		echo '<h2>' . esc_html__( 'Juuksuriõpilase vaade', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Sisseloginud juuksuriõpilane näeb päevaplaanis oma veergu ja oma broneeringuid ega saa midagi muuta. Siin määrad, mida ta veel näeb.', 'sasipea-broneering' ) . '</p>';
		$tv = array(
			'phone'   => __( 'Kliendi telefoninumber', 'sasipea-broneering' ),
			'price'   => __( 'Teenuse hind', 'sasipea-broneering' ),
			'details' => __( 'Kliendi e-post ja kommentaar', 'sasipea-broneering' ),
			'others'  => __( 'Teiste juuksuriõpilaste veerud (ajad ja teenused, ilma klientide andmeteta)', 'sasipea-broneering' ),
			'pay'     => __( 'Enda kuu tasu kokkuvõte (menüüs "Minu tasu")', 'sasipea-broneering' ),
		);
		foreach ( $tv as $k => $l ) {
			printf( '<p><label><input type="checkbox" name="trainee_view[%s]" value="1"%s> %s</label></p>', esc_attr( $k ), checked( ! empty( $s['trainee_view'][ $k ] ), true, false ), esc_html( $l ) );
		}

		echo '<h2>' . esc_html__( 'Juuksuriõpilaste tasemed', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Tase annab kliendile soodustuse teenuse hinnast ja pikendab teenuse aega selle juuksuriõpilase juures. Taseme eemaldamiseks tühjenda nimi.', 'sasipea-broneering' ) . '</p>';
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

		echo '<h2>' . esc_html__( 'Materjalid', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Materjal, mida müüakse kliendile grammi kaupa ja märgitakse päevaplaanis broneeringu juurde. Eemaldamiseks tühjenda nimi.', 'sasipea-broneering' ) . '</p>';
		echo '<table class="widefat" style="max-width:480px"><thead><tr><th>' . esc_html__( 'Materjal', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Hind (€/g)', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
		$mats   = (array) $s['materials'];
		$mats[] = array( 'id' => '', 'name' => '', 'cents_per_g' => 0 );
		foreach ( $mats as $i => $m ) {
			printf(
				'<tr><td><input type="hidden" name="materials[%1$d][id]" value="%2$s"><input name="materials[%1$d][name]" value="%3$s" placeholder="%5$s" aria-label="%6$s"></td><td><input name="materials[%1$d][price]" inputmode="decimal" style="width:90px" value="%4$s" aria-label="%7$s"></td></tr>',
				(int) $i,
				esc_attr( $m['id'] ),
				esc_attr( $m['name'] ),
				esc_attr( number_format( (int) $m['cents_per_g'] / 100, 2, ',', '' ) ),
				esc_attr__( 'Lisa uus materjal', 'sasipea-broneering' ),
				esc_attr__( 'Materjali nimi', 'sasipea-broneering' ),
				esc_attr__( 'Hind eurodes grammi kohta', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sisselogimine Google\'i kontoga', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Kui see on seadistatud, on töölaua sisselogimise lehel nupp "Logi sisse Google\'iga". Sisse saab ainult see, kelle Google\'i konto e-post on sama mis siin töölaual loodud konto e-post; uusi kontosid Google\'i kaudu ei teki. OAuth kliendi teed Google Cloud Console\'is: APIs & Services → Credentials → Create credentials → OAuth client ID → Web application.', 'sasipea-broneering' ) . '</p><table class="form-table">';
		printf( '<tr><th>%s</th><td><code style="user-select:all">%s</code><p class="description">%s</p></td></tr>', esc_html__( 'Tagasisuunamise aadress', 'sasipea-broneering' ), esc_html( Dashboard::google_redirect() ), esc_html__( 'Kopeeri see Google Cloudis väljale "Authorized redirect URIs" täpselt sellisel kujul.', 'sasipea-broneering' ) );
		printf( '<tr><th><label for="sb-g-id">%s</label></th><td><input id="sb-g-id" name="google_client_id" class="large-text" autocomplete="off" value="%s" placeholder="…apps.googleusercontent.com"></td></tr>', esc_html__( 'Kliendi ID (Client ID)', 'sasipea-broneering' ), esc_attr( (string) $s['google_client_id'] ) );
		printf( '<tr><th><label for="sb-g-secret">%s</label></th><td><input id="sb-g-secret" name="google_client_secret" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Kliendi saladus (Client secret)', 'sasipea-broneering' ), esc_attr( '' !== (string) $s['google_client_secret'] ? __( 'salvestatud, muutmiseks sisesta uus', 'sasipea-broneering' ) : 'GOCSPX-…' ), esc_html__( 'Saladust pärast salvestamist enam ei näidata. Väljalülitamiseks tühjenda kliendi ID.', 'sasipea-broneering' ) );
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="google_admins" value="1"%s> %s</label><p class="description">%s</p></td></tr>', esc_html__( 'Administraatorid', 'sasipea-broneering' ), checked( ! empty( $s['google_admins'] ), true, false ), esc_html__( 'Luba Google\'iga sisse logida ka WordPressi administraatoril', 'sasipea-broneering' ), esc_html__( 'Vaikimisi väljas: administraatori kontol on ligipääs kogu veebilehele, seega on turvalisem logida sellega sisse parooliga.', 'sasipea-broneering' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Veebipoe ladu (WooCommerce)', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Kui ühendus on seadistatud, saab päevaplaanis toodet otsida poest nime, tootekoodi või triipkoodi järgi. Iga salongimüük salvestatakse poodi lõpetatud tellimusena ja kogus arvatakse poe laost maha. API-võtme teed poe adminis: WooCommerce → Seaded → Täpsemalt → REST API → Lisa võti, õigus "Lugemine/kirjutamine".', 'sasipea-broneering' ) . '</p><table class="form-table">';
		printf( '<tr><th><label for="sb-woo-url">%s</label></th><td><input id="sb-woo-url" name="woo_url" type="url" class="regular-text" value="%s" placeholder="https://sasipea.ee"></td></tr>', esc_html__( 'Poe aadress', 'sasipea-broneering' ), esc_attr( $s['woo_url'] ) );
		printf( '<tr><th><label for="sb-woo-key">%s</label></th><td><input id="sb-woo-key" name="woo_key" class="regular-text" autocomplete="off" value="%s" placeholder="ck_…"></td></tr>', esc_html__( 'Kliendi võti (Consumer key)', 'sasipea-broneering' ), esc_attr( $s['woo_key'] ) );
		printf( '<tr><th><label for="sb-woo-secret">%s</label></th><td><input id="sb-woo-secret" name="woo_secret" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Salajane võti (Consumer secret)', 'sasipea-broneering' ), esc_attr( '' !== (string) $s['woo_secret'] ? __( 'salvestatud, muutmiseks sisesta uus', 'sasipea-broneering' ) : 'cs_…' ), esc_html__( 'Salajast võtit pärast salvestamist enam ei näidata. Ühenduse eemaldamiseks tühjenda poe aadress.', 'sasipea-broneering' ) );
		if ( Integrations\Woo::enabled() ) {
			$test = Integrations\Woo::test();
			echo '<tr><th>' . esc_html__( 'Ühendus', 'sasipea-broneering' ) . '</th><td>' . ( is_wp_error( $test ) ? '<span style="color:#b32d2e;font-weight:600">' . esc_html( $test->get_error_message() ) . '</span>' : '<span style="color:#00733a;font-weight:600">' . esc_html__( 'Ühendus poega töötab.', 'sasipea-broneering' ) . '</span>' ) . '</td></tr>';
			$idx   = Integrations\Woo::index();
			$gtins = count( array_filter( array_column( $idx['items'], 'g' ) ) );
			echo '<tr><th>' . esc_html__( 'Tootenimekiri', 'sasipea-broneering' ) . '</th><td>';
			if ( $idx['time'] ) {
				/* translators: 1: number of products, 2: number with barcode, 3: date and time */
				echo esc_html( sprintf( __( '%1$d toodet, neist %2$d triipkoodiga. Viimati uuendatud %3$s.', 'sasipea-broneering' ), count( $idx['items'] ), $gtins, wp_date( 'd.m.Y H:i', $idx['time'], Settings::tz() ) ) );
				if ( ! empty( $idx['shop_total'] ) ) {
					/* translators: 1: products in shop, 2: pages read */
					echo ' ' . esc_html( sprintf( __( 'Poe andmetel on avaldatud tooteid %1$d (loetud %2$d lehte; variatsioonid on nimekirjas eraldi ridadena).', 'sasipea-broneering' ), (int) $idx['shop_total'], (int) ( $idx['pages'] ?? 0 ) ) );
				}
			} else {
				echo '<strong>' . esc_html__( 'Tootenimekiri on laadimata. Triipkoodiga otsing hakkab tööle pärast esimest uuendamist.', 'sasipea-broneering' ) . '</strong>';
			}
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sb_shop_sync' ), 'sb_shop_sync' ) ) . '">' . esc_html__( 'Uuenda tootenimekirja', 'sasipea-broneering' ) . '</a><p class="description">' . esc_html__( 'Otsing käib poe tootenimekirja koopiast (nimi, triipkood GTIN/EAN väljalt, tootekood SKU). Koopia uueneb ise kord ööpäevas; uue toote kohe leidmiseks vajuta nuppu. Hind ja laoseis küsitakse alati värskelt poest.', 'sasipea-broneering' ) . '</p></td></tr>';
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Käibemaks', 'sasipea-broneering' ) . '</h2><p class="description">' . esc_html__( 'Jäta tühjaks, kui ettevõte ei ole käibemaksukohustuslane. Kohustuse tekkimisel lisa rida: mis kuupäevast ja mis määraga. Määra muutumisel lisa uus rida, vanu ära muuda. Hinnad loetakse käibemaksuga hindadeks. Aruanne on lehel Broneeringud → Käibemaks.', 'sasipea-broneering' ) . '</p>';
		echo '<table class="widefat" style="max-width:420px"><thead><tr><th>' . esc_html__( 'Kehtib alates', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Määr (%)', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
		$vh   = Vat::normalize( $s['vat_history'] );
		$vh[] = array( 'from' => '', 'rate' => '' );
		foreach ( $vh as $i => $v ) {
			printf(
				'<tr><td><input type="date" name="vat_history[%1$d][from]" value="%2$s" aria-label="%4$s"></td><td><input name="vat_history[%1$d][rate]" inputmode="decimal" style="width:90px" value="%3$s" aria-label="%5$s"></td></tr>',
				(int) $i,
				esc_attr( $v['from'] ),
				esc_attr( '' === $v['rate'] ? '' : str_replace( '.', ',', (string) $v['rate'] ) ),
				esc_attr__( 'Kehtib alates', 'sasipea-broneering' ),
				esc_attr__( 'Käibemaksu määr protsentides', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Salongi andmed ja lingid', 'sasipea-broneering' ) . '</h2><table class="form-table">';
		$txt = array(
			'dashboard_slug'   => __( 'Töölaua aadressi lõpp', 'sasipea-broneering' ),
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

	public static function handle_shop_sync(): void {
		if ( ! current_user_can( 'sb_manage_settings' ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		check_admin_referer( 'sb_shop_sync' );
		$res = Integrations\Woo::sync();
		if ( is_wp_error( $res ) ) {
			self::back( 'sb-settings', array( 'sb_saved' => null, 'sb_error' => $res->get_error_message() ) );
		}
		self::back( 'sb-settings' );
	}

	public static function handle_save_settings(): void {
		self::guard( 'sb_manage_settings', 'sb_save_settings' );
		$p    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$vals = array( 'opening_hours' => array() );
		$bad  = array();
		foreach ( range( 1, 7 ) as $wd ) {
			$text   = trim( sanitize_text_field( (string) ( $p['opening'][ $wd ] ?? '' ) ) );
			$ranges = self::parse_ranges( $text );
			// Arusaamatu tekst ei tee päeva vaikselt suletuks: senine aeg jääb alles ja kasutaja saab teate.
			if ( '' !== $text && ! $ranges && ! preg_match( '/^(suletud|kinni|-|–)$/iu', $text ) ) {
				$bad[]                                  = Format::days()[ $wd ];
				$vals['opening_hours'][ (string) $wd ] = Settings::get()['opening_hours'][ (string) $wd ] ?? array();
				continue;
			}
			$vals['opening_hours'][ (string) $wd ] = array_map(
				fn( $r ) => array( Settings::from_min( $r[0] ), Settings::from_min( $r[1] ) ),
				$ranges
			);
		}
		foreach ( array( 'slot_step', 'buffer', 'min_notice_hours', 'max_days_ahead', 'max_concurrent', 'cancel_hours' ) as $k ) {
			$vals[ $k ] = max( 0, (int) ( $p[ $k ] ?? 0 ) );
		}
		$vals['slot_step']     = max( 5, $vals['slot_step'] );
		$vals['require_month'] = ! empty( $p['require_month'] );
		$vals['trainee_view']  = array();
		foreach ( array( 'phone', 'price', 'details', 'others', 'pay' ) as $k ) {
			$vals['trainee_view'][ $k ] = ! empty( $p['trainee_view'][ $k ] );
		}
		$woo_url = esc_url_raw( trim( (string) ( $p['woo_url'] ?? '' ) ) );
		if ( '' !== $woo_url && ! Integrations\Woo::valid_url( $woo_url ) ) {
			$woo_url = '';
		}
		$vals['woo_url'] = untrailingslashit( $woo_url );
		$vals['woo_key'] = '' === $woo_url ? '' : sanitize_text_field( (string) ( $p['woo_key'] ?? '' ) );
		$new_secret      = sanitize_text_field( (string) ( $p['woo_secret'] ?? '' ) );
		if ( '' === $woo_url ) {
			$vals['woo_secret'] = '';
		} elseif ( '' !== $new_secret ) {
			$vals['woo_secret'] = $new_secret;
		}
		$vals['google_client_id'] = sanitize_text_field( trim( (string) ( $p['google_client_id'] ?? '' ) ) );
		$new_gsecret              = sanitize_text_field( trim( (string) ( $p['google_client_secret'] ?? '' ) ) );
		if ( '' === $vals['google_client_id'] ) {
			$vals['google_client_secret'] = '';
		} elseif ( '' !== $new_gsecret ) {
			$vals['google_client_secret'] = $new_gsecret;
		}
		$vals['google_admins'] = ! empty( $p['google_admins'] );
		$vat = array();
		foreach ( (array) ( $p['vat_history'] ?? array() ) as $v ) {
			if ( '' !== trim( (string) ( $v['rate'] ?? '' ) ) ) {
				$vat[] = array( 'from' => sanitize_text_field( (string) ( $v['from'] ?? '' ) ), 'rate' => (float) str_replace( ',', '.', sanitize_text_field( (string) $v['rate'] ) ) );
			}
		}
		$vals['vat_history']   = Vat::normalize( $vat );
		$vals['materials']     = array();
		foreach ( (array) ( $p['materials'] ?? array() ) as $m ) {
			$name = sanitize_text_field( (string) ( $m['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$mid = sanitize_key( (string) ( $m['id'] ?? '' ) );
			if ( '' === $mid ) {
				$mid = substr( sanitize_title( $name ), 0, 30 ) ?: 'materjal';
				while ( in_array( $mid, array_column( $vals['materials'], 'id' ), true ) ) {
					$mid .= '2';
				}
			}
			$vals['materials'][] = array(
				'id'          => substr( $mid, 0, 40 ),
				'name'        => $name,
				'cents_per_g' => max( 0, (int) round( (float) str_replace( ',', '.', sanitize_text_field( (string) ( $m['price'] ?? '0' ) ) ) * 100 ) ),
			);
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
		$vals['dashboard_slug'] = sanitize_title( (string) ( $p['dashboard_slug'] ?? '' ) ) ?: 'salong';
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
		if ( $bad ) {
			/* translators: %s: comma separated weekday names */
			self::back( 'sb-settings', array( 'sb_saved' => null, 'sb_error' => sprintf( __( 'Lahtiolekuaeg jäi muutmata, sest kellaajast ei saanud aru: %s. Kirjuta näiteks 12:00-19:00.', 'sasipea-broneering' ), implode( ', ', $bad ) ) ) );
		}
		self::back( 'sb-settings' );
	}
}
