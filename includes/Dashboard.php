<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Töölaud: oma kujundusega vaade väljaspool WP adminni (vaikimisi /salong/).
 * Omanik, juhendaja ja praktikant logivad siin sisse ning näevad ainult oma lubatud vaateid.
 */
final class Dashboard {

	/** Rollid, mille kasutajad töötavad ainult töölaual (WP adminni neid ei lasta). */
	private const SALON_ROLES = array( Roles::PRAKTIKANT, Roles::JUHENDAJA, Roles::OMANIK );

	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ) );
		add_action( 'admin_init', array( self::class, 'keep_out_of_admin' ) );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 10, 3 );
	}

	public static function slug(): string {
		$slug = sanitize_title( (string) ( Settings::get()['dashboard_slug'] ?? '' ) );
		return '' !== $slug ? $slug : 'salong';
	}

	private static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/** Töölaua vaate aadress; lõpeb alati päringuosaga, nii et parameetreid saab &-ga lisada. */
	public static function url( string $view = '' ): string {
		$view = '' !== $view ? $view : 'avaleht';
		$base = self::pretty() ? home_url( '/' . self::slug() . '/' ) : add_query_arg( 'sb_toolaud', 1, home_url( '/' ) );
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 'vaade=' . $view;
	}

	/** Kas see aadress (tee + päring) on töölaud. */
	private static function is_dashboard_uri( string $uri ): bool {
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		parse_str( $query, $q );
		if ( ! empty( $q['sb_toolaud'] ) ) {
			return true;
		}
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		return $path === self::slug();
	}

	/** Kas praegune päring (vormi salvestamine, CSV link) tuli töölaualt. */
	public static function from_dashboard(): bool {
		$ref = wp_get_raw_referer();
		return $ref ? self::is_dashboard_uri( (string) $ref ) : false;
	}

	public static function is_salon_only( ?\WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! $user->roles ) {
			return false;
		}
		return ! array_diff( $user->roles, self::SALON_ROLES );
	}

	public static function admin_bar( $show ) {
		return self::is_salon_only() ? false : $show;
	}

	/** Salongi rollid ei näe WP adminni: suuname töölaua samale vaatele. */
	public static function keep_out_of_admin(): void {
		global $pagenow;
		if ( wp_doing_ajax() || 'admin-post.php' === $pagenow || ! self::is_salon_only() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( self::url( Admin::VIEWS[ $page ] ?? '' ) );
		exit;
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof \WP_User && self::is_salon_only( $user ) ) {
			return self::url();
		}
		return $to;
	}

	public static function can_view(): bool {
		return current_user_can( 'sb_view_own' ) || current_user_can( 'sb_manage_bookings' ) || current_user_can( 'sb_manage_settings' );
	}

	public static function role_label(): string {
		if ( current_user_can( 'sb_manage_settings' ) ) {
			return __( 'Omanik', 'sasipea-broneering' );
		}
		if ( current_user_can( 'sb_manage_bookings' ) ) {
			return __( 'Juhendaja', 'sasipea-broneering' );
		}
		return __( 'Praktikant', 'sasipea-broneering' );
	}

	/**
	 * Vaated, mida praegune kasutaja tohib näha.
	 *
	 * @return array<string, array{label:string, icon:string, group:string, render:callable}>
	 */
	public static function views(): array {
		$owner   = current_user_can( 'sb_manage_settings' );
		$manager = $owner || current_user_can( 'sb_manage_bookings' );
		$v       = array();
		$add     = static function ( string $key, string $label, string $icon, string $group, callable $render ) use ( &$v ): void {
			$v[ $key ] = compact( 'label', 'icon', 'group', 'render' );
		};
		$add( 'avaleht', __( 'Avaleht', 'sasipea-broneering' ), 'home', '', array( self::class, 'view_home' ) );
		$add( 'paevaplaan', __( 'Päevaplaan', 'sasipea-broneering' ), 'calendar', '', array( Admin::class, 'page_planner' ) );
		if ( $manager ) {
			$add( 'kassa', __( 'Kassa', 'sasipea-broneering' ), 'cash', '', array( Kassa::class, 'page' ) );
			$add( 'toograafik', __( 'Töögraafik', 'sasipea-broneering' ), 'grid', '', array( Admin::class, 'page_month' ) );
			$add( 'kliendid', __( 'Kliendid', 'sasipea-broneering' ), 'users', '', array( Admin::class, 'page_clients' ) );
		}
		if ( $owner ) {
			$add( 'tasud', __( 'Tasud', 'sasipea-broneering' ), 'coins', 'raha', array( Admin::class, 'page_pay' ) );
			$add( 'kaibemaks', __( 'Käibemaks', 'sasipea-broneering' ), 'percent', 'raha', array( Admin::class, 'page_vat' ) );
			$add( 'tooted', __( 'Tooted', 'sasipea-broneering' ), 'box', 'raha', array( Admin::class, 'page_products' ) );
			$add( 'praktikandid', __( 'Praktikandid', 'sasipea-broneering' ), 'scissors', 'seadistus', array( Admin::class, 'page_staff' ) );
			$add( 'teenused', __( 'Teenused', 'sasipea-broneering' ), 'list', 'seadistus', array( Admin::class, 'page_services' ) );
			$add( 'kasutajad', __( 'Kasutajad', 'sasipea-broneering' ), 'users', 'seadistus', array( Users::class, 'page' ) );
			$add( 'seaded', __( 'Seaded', 'sasipea-broneering' ), 'gear', 'seadistus', array( Admin::class, 'page_settings' ) );
		} elseif ( ! empty( Settings::get()['trainee_view']['pay'] ) && Roles::current_staff_id() ) {
			$add( 'tasud', __( 'Minu tasu', 'sasipea-broneering' ), 'coins', '', array( Admin::class, 'page_pay' ) );
		}
		$add( 'konto', __( 'Minu konto', 'sasipea-broneering' ), 'user', 'konto', array( self::class, 'view_account' ) );
		return $v;
	}

	public static function maybe_render(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! self::is_dashboard_uri( $uri ) ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );

		$error = '';
		$vaade = isset( $_GET['vaade'] ) ? sanitize_key( wp_unslash( $_GET['vaade'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'kutse' === $vaade ) {
			self::invite_page();
			exit;
		}
		if ( ! is_user_logged_in() ) {
			if ( 'google' === $vaade && self::google_enabled() ) {
				$error = self::handle_google();
			} elseif ( isset( $_POST['sb_login'] ) ) {
				$error = self::handle_login();
			}
			self::render_login( $error, false, isset( $_GET['sb_set'] ) ? __( 'Parool on seatud. Logi nüüd sisse.', 'sasipea-broneering' ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			exit;
		}
		if ( ! self::can_view() ) {
			self::render_login( __( 'Sellel kontol pole salongi töölauale ligipääsu. Palu omanikul või juhendajal sulle õigused anda.', 'sasipea-broneering' ), true );
			exit;
		}

		$views = self::views();
		$view  = isset( $_GET['vaade'] ) ? sanitize_key( wp_unslash( $_GET['vaade'] ) ) : 'avaleht'; // phpcs:ignore WordPress.Security.NonceVerification
		$known = array_merge( array_values( Admin::VIEWS ), array( 'avaleht', 'konto' ) );
		if ( ! isset( $views[ $view ] ) ) {
			// Tundmatu vaade viib avalehele; olemasolev, kuid keelatud vaade annab selge teate.
			if ( ! in_array( $view, $known, true ) ) {
				wp_safe_redirect( self::url() );
				exit;
			}
			status_header( 403 );
			self::render_shell( $views, '', '<div class="wrap"><h1>' . esc_html__( 'Seda vaadet sul näha ei ole lubatud', 'sasipea-broneering' ) . '</h1><p>' . esc_html__( 'Kui vajad ligipääsu, räägi juhendaja või omanikuga.', 'sasipea-broneering' ) . '</p></div>' );
			exit;
		}

		$notice = '';
		if ( 'konto' === $view && isset( $_POST['sb_password'] ) ) {
			$notice = self::handle_password();
		} elseif ( 'konto' === $view && isset( $_GET['sb_pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$notice = 'ok:' . __( 'Parool on muudetud.', 'sasipea-broneering' );
		}

		Admin::$front = true;
		require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button()
		ob_start();
		if ( 'konto' === $view ) {
			self::view_account( $notice );
		} else {
			call_user_func( $views[ $view ]['render'] );
		}
		$body = (string) ob_get_clean();
		self::render_shell( $views, $view, $body );
		exit;
	}

	/* ================= Kutse: kasutaja seab endale parooli ================= */

	/** Töölaua aadress inimesele näitamiseks (ilma vaate parameetrita). */
	public static function home(): string {
		return self::pretty() ? home_url( '/' . self::slug() . '/' ) : add_query_arg( 'sb_toolaud', 1, home_url( '/' ) );
	}

	private static function invite_page(): void {
		$key   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$login = isset( $_REQUEST['u'] ) ? sanitize_user( wp_unslash( $_REQUEST['u'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$set   = Settings::get();
		$error = '';
		$limit = Security::rate_limit( 'invite', 20, 10 * MINUTE_IN_SECONDS );
		$user  = is_wp_error( $limit ) || '' === $key || '' === $login ? null : check_password_reset_key( $key, $login );
		$valid = $user instanceof \WP_User && ! Users::is_disabled( (int) $user->ID ) && null !== Users::managed_role( (array) $user->roles );
		if ( $valid && isset( $_POST['sb_invite'] ) ) {
			$new = isset( $_POST['new'] ) ? (string) wp_unslash( $_POST['new'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- parooli ei puhastata; võti on ühekordne.
			$rep = isset( $_POST['rep'] ) ? (string) wp_unslash( $_POST['rep'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
			if ( strlen( $new ) < 10 ) {
				$error = __( 'Parool peab olema vähemalt 10 märki pikk.', 'sasipea-broneering' );
			} elseif ( $new !== $rep ) {
				$error = __( 'Paroolid ei ühti.', 'sasipea-broneering' );
			} else {
				reset_password( $user, $new );
				delete_user_meta( $user->ID, Users::INVITED );
				wp_safe_redirect( self::url() . '&sb_set=1' );
				exit;
			}
		}
		self::head( __( 'Sea parool', 'sasipea-broneering' ) );
		echo '<body class="sbd sbd-login"><main class="sbd-login-card"><p class="sbd-brand">' . esc_html( $set['from_name'] ) . '</p><h1>' . esc_html__( 'Sea endale parool', 'sasipea-broneering' ) . '</h1>';
		if ( ! $valid ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( is_wp_error( $limit ) ? __( 'Liiga palju katseid. Proovi kümne minuti pärast uuesti.', 'sasipea-broneering' ) : __( 'See link ei kehti enam. Palu salongi omanikul saata uus kutse.', 'sasipea-broneering' ) ) . '</p></div><p class="sbd-login-foot"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Sisselogimise lehele', 'sasipea-broneering' ) . '</a></p></main></body></html>';
			return;
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		/* translators: %s: e-mail address */
		echo '<p class="sbd-login-lead">' . esc_html( sprintf( __( 'Konto: %s. Vali parool, mida sa mujal ei kasuta (vähemalt 10 märki).', 'sasipea-broneering' ), $user->user_email ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( self::url( 'kutse' ) ) . '"><input type="hidden" name="sb_invite" value="1"><input type="hidden" name="key" value="' . esc_attr( $key ) . '"><input type="hidden" name="u" value="' . esc_attr( $login ) . '">';
		echo '<input type="text" name="username" value="' . esc_attr( $user->user_email ) . '" autocomplete="username" hidden>';
		echo '<label for="sbd-new">' . esc_html__( 'Uus parool', 'sasipea-broneering' ) . '</label><input id="sbd-new" type="password" name="new" autocomplete="new-password" minlength="10" required autofocus>';
		echo '<label for="sbd-rep">' . esc_html__( 'Uus parool uuesti', 'sasipea-broneering' ) . '</label><input id="sbd-rep" type="password" name="rep" autocomplete="new-password" minlength="10" required>';
		echo '<p></p><button class="button button-primary" type="submit">' . esc_html__( 'Salvesta parool', 'sasipea-broneering' ) . '</button></form></main></body></html>';
	}

	/* ================= Sisselogimine Google'i kontoga ================= */

	public static function google_enabled(): bool {
		$s = Settings::get();
		return '' !== (string) $s['google_client_id'] && '' !== (string) $s['google_client_secret'];
	}

	/** Aadress, kuhu Google pärast sisselogimist tagasi suunab. Peab Google Cloudis täpselt samal kujul kirjas olema. */
	public static function google_redirect(): string {
		return self::url( 'google' );
	}

	/** @return array{auth:string,token:string,userinfo:string} */
	private static function google_endpoints(): array {
		return (array) apply_filters(
			'sb_google_endpoints',
			array(
				'auth'     => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'    => 'https://oauth2.googleapis.com/token',
				'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
			)
		);
	}

	/**
	 * Kas selle kontoga tohib Google'i kaudu sisse logida. Puhas funktsioon (testitav).
	 *
	 * @param string[] $roles    kasutaja rollid
	 * @param bool     $can_view kas kontol on töölaua õigus
	 */
	public static function google_allowed( array $roles, bool $can_view, bool $disabled, bool $admins_ok ): bool {
		if ( $disabled || ! $can_view ) {
			return false;
		}
		return $admins_ok || ! in_array( 'administrator', $roles, true );
	}

	/** Alustab Google'i sisselogimist või lõpetab selle. Tagastab veateate; õnnestumisel suunab töölauale. */
	private static function handle_google(): string {
		$s    = Settings::get();
		$ep   = self::google_endpoints();
		$fail = __( 'Google\'iga sisselogimine ebaõnnestus. Proovi uuesti või logi sisse parooliga.', 'sasipea-broneering' );
		// phpcs:disable WordPress.Security.NonceVerification -- OAuth vastust kaitseb state väärtus.
		if ( isset( $_GET['algus'] ) ) {
			$state = wp_generate_password( 40, false );
			setcookie( 'sb_gstate', $state, array( 'expires' => time() + 600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$url = add_query_arg(
				array(
					'client_id'     => rawurlencode( (string) $s['google_client_id'] ),
					'redirect_uri'  => rawurlencode( self::google_redirect() ),
					'response_type' => 'code',
					'scope'         => rawurlencode( 'openid email' ),
					'state'         => $state,
					'prompt'        => 'select_account',
				),
				$ep['auth']
			);
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Google'i sisselogimise leht.
			exit;
		}
		if ( ! isset( $_GET['code'], $_GET['state'] ) ) {
			return isset( $_GET['error'] ) ? __( 'Google\'iga sisselogimine katkestati.', 'sasipea-broneering' ) : '';
		}
		$limit = Security::rate_limit( 'google', 20, 10 * MINUTE_IN_SECONDS );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$mine  = isset( $_COOKIE['sb_gstate'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['sb_gstate'] ) ) : '';
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable
		setcookie( 'sb_gstate', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		if ( is_wp_error( $limit ) || '' === $mine || ! hash_equals( $mine, $state ) ) {
			return $fail;
		}
		$res = wp_remote_post(
			$ep['token'],
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => (string) $s['google_client_id'],
					'client_secret' => (string) $s['google_client_secret'],
					'redirect_uri'  => self::google_redirect(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$tok = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $tok ) || empty( $tok['access_token'] ) ) {
			return $fail;
		}
		$res  = wp_remote_get( $ep['userinfo'], array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $tok['access_token'] ) ) );
		$info = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$mail = is_array( $info ) ? sanitize_email( (string) ( $info['email'] ?? '' ) ) : '';
		// Google peab kinnitama, et e-post kuulub sellele inimesele.
		if ( '' === $mail || true !== filter_var( $info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return $fail;
		}
		$user = get_user_by( 'email', $mail );
		$none = __( 'Selle Google\'i konto e-postiga ei ole salongi töölaual kontot. Palu omanikul sulle konto luua.', 'sasipea-broneering' );
		if ( ! $user ) {
			return $none;
		}
		$can = user_can( $user, 'sb_view_own' ) || user_can( $user, 'sb_manage_bookings' ) || user_can( $user, 'sb_manage_settings' );
		if ( ! self::google_allowed( (array) $user->roles, $can, Users::is_disabled( (int) $user->ID ), ! empty( $s['google_admins'] ) ) ) {
			return in_array( 'administrator', (array) $user->roles, true ) && $can && ! Users::is_disabled( (int) $user->ID )
				? __( 'Administraatori kontoga saab sisse logida ainult parooliga.', 'sasipea-broneering' )
				: $none;
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		delete_user_meta( $user->ID, Users::INVITED );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPressi enda sündmus.
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ================= Sisselogimine ================= */

	private static function handle_login(): string {
		$bad = __( 'Vale kasutajanimi või parool.', 'sasipea-broneering' );
		if ( ! isset( $_POST['_sbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_sbnonce'] ) ), 'sb_login' ) ) {
			return __( 'Leht aegus. Proovi uuesti.', 'sasipea-broneering' );
		}
		$limit = Security::rate_limit( 'login', 10, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return __( 'Liiga palju katseid. Proovi kümne minuti pärast uuesti.', 'sasipea-broneering' );
		}
		$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$pass  = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parooli ei puhastata.
		if ( '' === $login || '' === $pass ) {
			return $bad;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $pass,
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return $bad;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function handle_password(): string {
		if ( ! isset( $_POST['_sbnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_sbnonce'] ) ), 'sb_password' ) ) {
			return 'error:' . __( 'Leht aegus. Proovi uuesti.', 'sasipea-broneering' );
		}
		$user = wp_get_current_user();
		$old  = isset( $_POST['old'] ) ? (string) wp_unslash( $_POST['old'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$new  = isset( $_POST['new'] ) ? (string) wp_unslash( $_POST['new'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rep  = isset( $_POST['rep'] ) ? (string) wp_unslash( $_POST['rep'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$limit = Security::rate_limit( 'pass', 10, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return 'error:' . __( 'Liiga palju katseid. Proovi kümne minuti pärast uuesti.', 'sasipea-broneering' );
		}
		if ( ! wp_check_password( $old, $user->user_pass, $user->ID ) ) {
			return 'error:' . __( 'Praegune parool ei ole õige.', 'sasipea-broneering' );
		}
		if ( strlen( $new ) < 10 ) {
			return 'error:' . __( 'Uus parool peab olema vähemalt 10 märki pikk.', 'sasipea-broneering' );
		}
		if ( $new !== $rep ) {
			return 'error:' . __( 'Uued paroolid ei ühti.', 'sasipea-broneering' );
		}
		$res = wp_update_user(
			array(
				'ID'        => $user->ID,
				'user_pass' => $new,
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'error:' . $res->get_error_message();
		}
		// Parooli muutus uuendab seansi, seega laeme lehe uuesti (muidu aeguks vormi turvakood).
		wp_safe_redirect( self::url( 'konto' ) . '&sb_pw=1' );
		exit;
	}

	/* ================= Vaated ================= */

	public static function view_home(): void {
		$tz      = Settings::tz();
		$now     = new \DateTimeImmutable( 'now', $tz );
		$today   = $now->format( 'Y-m-d' );
		$manager = current_user_can( 'sb_manage_bookings' );
		$own     = Roles::current_staff_id();
		$set     = Settings::get();
		$user    = wp_get_current_user();
		$mine    = static fn( array $rows ): array => $manager ? $rows : array_values( array_filter( $rows, static fn( $b ) => $own && (int) $b['staff_id'] === $own ) );

		[ $from, $to ] = Repo::day_bounds( $today );
		$day           = $mine( Repo::bookings_between( $from, $to ) );
		$monday        = $now->modify( 'monday this week' )->format( 'Y-m-d' );
		$week          = $mine( Repo::bookings_between( Repo::day_bounds( $monday )[0], Repo::day_bounds( $monday )[0] + 7 * DAY_IN_SECONDS ) );
		$next          = null;
		foreach ( $mine( Repo::bookings_between( $now->getTimestamp(), $now->getTimestamp() + 14 * DAY_IN_SECONDS ) ) as $b ) {
			if ( 'booked' === $b['status'] && Repo::ts( $b['start_utc'] ) >= $now->getTimestamp() ) {
				$next = $b;
				break;
			}
		}

		echo '<div class="wrap sbd-home">';
		echo '<p class="sbd-eyebrow">' . esc_html( Format::date_long( $now ) ) . '</p>';
		/* translators: %s: user's first name */
		echo '<h1>' . esc_html( sprintf( __( 'Tere, %s!', 'sasipea-broneering' ), $user->first_name ?: $user->display_name ) ) . '</h1>';

		if ( ! $manager && ! $own ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Sinu konto ei ole veel praktikandiga seotud, seepärast broneeringuid ei näe. Palu omanikul sind praktikandi kaardil oma kontoga siduda.', 'sasipea-broneering' ) . '</p></div>';
		}

		echo '<div class="sbd-cards">';
		self::card( $manager ? __( 'Täna broneeringuid', 'sasipea-broneering' ) : __( 'Minu kliente täna', 'sasipea-broneering' ), (string) count( $day ), self::url( 'paevaplaan' ) );
		if ( $next ) {
			$s    = Format::local( $next['start_utc'] );
			$when = ( $s->format( 'Y-m-d' ) === $today ? __( 'täna', 'sasipea-broneering' ) : Format::days()[ (int) $s->format( 'N' ) ] ?? '' ) . ' ' . $s->format( 'H:i' );
			self::card( __( 'Järgmine klient', 'sasipea-broneering' ), trim( $when ), '', (string) $next['service_name'] . ( $manager ? ' · ' . $next['staff_name'] : '' ) );
		} else {
			self::card( __( 'Järgmine klient', 'sasipea-broneering' ), '–', '', __( 'Lähima kahe nädala sees broneeringuid pole.', 'sasipea-broneering' ) );
		}
		self::card( __( 'Sel nädalal', 'sasipea-broneering' ), (string) count( $week ), '', __( 'broneeringut esmaspäevast pühapäevani', 'sasipea-broneering' ) );
		if ( $manager ) {
			$working = array();
			foreach ( Repo::staff_list( true ) as $st ) {
				$r = Repo::planned_ranges( (int) $st['id'], $today );
				if ( $r ) {
					$working[] = $st['name'] . ' ' . Admin::ranges_str( $r );
				}
			}
			self::card( __( 'Täna tööl', 'sasipea-broneering' ), (string) count( $working ), self::url( 'toograafik' ), $working ? implode( ', ', $working ) : __( 'Täna pole kedagi graafikus.', 'sasipea-broneering' ) );
		}
		echo '</div>';

		echo '<div class="sbd-panel"><div class="sbd-panel-head"><h2>' . esc_html__( 'Tänased broneeringud', 'sasipea-broneering' ) . '</h2><a class="button" href="' . esc_url( self::url( 'paevaplaan' ) ) . '">' . esc_html__( 'Ava päevaplaan', 'sasipea-broneering' ) . '</a></div>';
		if ( ! $day ) {
			echo '<p class="sbd-empty">' . esc_html__( 'Täna broneeringuid ei ole.', 'sasipea-broneering' ) . '</p>';
		} else {
			$phone = $manager || ! empty( $set['trainee_view']['phone'] );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Aeg', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Klient', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Teenus', 'sasipea-broneering' ) . '</th>';
			if ( $manager ) {
				echo '<th>' . esc_html__( 'Praktikant', 'sasipea-broneering' ) . '</th>';
			}
			echo '<th>' . esc_html__( 'Olek', 'sasipea-broneering' ) . '</th></tr></thead><tbody>';
			foreach ( $day as $b ) {
				$s = Format::local( $b['start_utc'] );
				$e = Format::local( $b['end_utc'] );
				echo '<tr><td class="sbd-time">' . esc_html( $s->format( 'H:i' ) . '–' . $e->format( 'H:i' ) ) . '</td><td><strong>' . esc_html( trim( $b['first_name'] . ' ' . $b['last_name'] ) ) . '</strong>';
				if ( $phone && $b['phone'] ) {
					echo '<br><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', (string) $b['phone'] ) ) . '">' . esc_html( $b['phone'] ) . '</a>';
				}
				echo '</td><td>' . esc_html( (string) $b['service_name'] ) . '</td>';
				if ( $manager ) {
					echo '<td>' . esc_html( (string) $b['staff_name'] ) . '</td>';
				}
				echo '<td><span class="sbd-status is-' . esc_attr( $b['status'] ) . '">' . esc_html( Repo::STATUSES[ $b['status'] ] ?? $b['status'] ) . '</span></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		if ( $manager ) {
			echo '<p class="sbd-links"><a href="' . esc_url( Settings::booking_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ava klientide broneerimise leht', 'sasipea-broneering' ) . ' ↗</a></p>';
		}
		echo '</div>';
	}

	private static function card( string $label, string $value, string $href = '', string $sub = '' ): void {
		$tag = $href ? 'a href="' . esc_url( $href ) . '"' : 'div';
		echo '<' . $tag . ' class="sbd-card"><span class="sbd-card-label">' . esc_html( $label ) . '</span><span class="sbd-card-value">' . esc_html( $value ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $tag on ülal escape'itud.
		if ( '' !== $sub ) {
			echo '<span class="sbd-card-sub">' . esc_html( $sub ) . '</span>';
		}
		echo '</' . ( $href ? 'a' : 'div' ) . '>';
	}

	public static function view_account( string $notice = '' ): void {
		$user = wp_get_current_user();
		echo '<div class="wrap"><h1>' . esc_html__( 'Minu konto', 'sasipea-broneering' ) . '</h1>';
		if ( '' !== $notice ) {
			[ $type, $msg ] = explode( ':', $notice, 2 );
			echo '<div class="notice ' . ( 'ok' === $type ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Nimi', 'sasipea-broneering' ) . '</th><td>' . esc_html( $user->display_name ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Kasutajanimi', 'sasipea-broneering' ) . '</th><td>' . esc_html( $user->user_login ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'E-post', 'sasipea-broneering' ) . '</th><td>' . esc_html( $user->user_email ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Roll', 'sasipea-broneering' ) . '</th><td>' . esc_html( self::role_label() ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Muuda parooli', 'sasipea-broneering' ) . '</h2><form method="post" action="' . esc_url( self::url( 'konto' ) ) . '" autocomplete="off">';
		wp_nonce_field( 'sb_password', '_sbnonce', false );
		echo '<input type="hidden" name="sb_password" value="1"><table class="form-table"><tbody>';
		echo '<tr><th><label for="sbd-old">' . esc_html__( 'Praegune parool', 'sasipea-broneering' ) . '</label></th><td><input class="regular-text" id="sbd-old" type="password" name="old" autocomplete="current-password" required></td></tr>';
		echo '<tr><th><label for="sbd-new">' . esc_html__( 'Uus parool', 'sasipea-broneering' ) . '</label></th><td><input class="regular-text" id="sbd-new" type="password" name="new" autocomplete="new-password" minlength="10" required><p class="description">' . esc_html__( 'Vähemalt 10 märki.', 'sasipea-broneering' ) . '</p></td></tr>';
		echo '<tr><th><label for="sbd-rep">' . esc_html__( 'Uus parool uuesti', 'sasipea-broneering' ) . '</label></th><td><input class="regular-text" id="sbd-rep" type="password" name="rep" autocomplete="new-password" minlength="10" required></td></tr>';
		echo '</tbody></table><p class="submit"><button class="button button-primary" type="submit">' . esc_html__( 'Salvesta uus parool', 'sasipea-broneering' ) . '</button></p></form></div>';
	}

	/* ================= Raam ================= */

	private static function icon( string $name ): string {
		$paths = array(
			'home'     => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
			'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
			'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.8a3.5 3.5 0 010 6.4M18.5 14.8c1.7.8 2.7 2.5 3 5.2"/>',
			'coins'    => '<ellipse cx="12" cy="7" rx="8" ry="3.5"/><path d="M4 7v5c0 1.9 3.6 3.5 8 3.5s8-1.6 8-3.5V7"/><path d="M4 12v5c0 1.9 3.6 3.5 8 3.5s8-1.6 8-3.5v-5"/>',
			'percent'  => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
			'box'      => '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
			'scissors' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M8.5 8l12 10M8.5 16l12-10"/>',
			'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
			'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
			'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6"/>',
			'cash'     => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5v5M17.5 9.5v5"/>',
			'logout'   => '<path d="M9 4H5v16h4M15 8l4 4-4 4M19 12H9"/>',
		);
		return '<svg class="sbd-ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	private static function head( string $title ): void {
		$set = Settings::get();
		echo '<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( $title . ' · ' . $set['from_name'] ) . '</title>';
		$icon = get_site_icon_url( 64 );
		if ( $icon ) {
			echo '<link rel="icon" href="' . esc_url( $icon ) . '">';
		}
		if ( wp_style_is( 'sb-planner', 'enqueued' ) ) {
			wp_print_styles( array( 'sb-planner' ) );
		}
		echo '<link rel="stylesheet" href="' . esc_url( SB_URL . 'assets/css/dashboard.css?ver=' . SB_VERSION ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources -- oma leht ilma teemata.
		echo '</head>';
	}

	private static function render_login( string $error = '', bool $logged_in = false, string $ok = '' ): void {
		$set = Settings::get();
		self::head( __( 'Sisselogimine', 'sasipea-broneering' ) );
		echo '<body class="sbd sbd-login"><main class="sbd-login-card"><p class="sbd-brand">' . esc_html( $set['from_name'] ) . '</p><h1>' . esc_html__( 'Salongi töölaud', 'sasipea-broneering' ) . '</h1>';
		if ( '' !== $error ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( '' !== $ok ) {
			echo '<div class="notice notice-success" role="status"><p>' . esc_html( $ok ) . '</p></div>';
		}
		if ( $logged_in ) {
			echo '<p><a class="button button-primary" href="' . esc_url( wp_logout_url( self::url() ) ) . '">' . esc_html__( 'Logi välja', 'sasipea-broneering' ) . '</a></p>';
		} else {
			$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<p class="sbd-login-lead">' . esc_html__( 'Logi sisse oma kasutajaga, et näha päevaplaani ja oma broneeringuid.', 'sasipea-broneering' ) . '</p>';
			echo '<form method="post" action="' . esc_url( self::url() ) . '">';
			wp_nonce_field( 'sb_login', '_sbnonce', false );
			echo '<input type="hidden" name="sb_login" value="1">';
			echo '<label for="sbd-log">' . esc_html__( 'Kasutajanimi või e-post', 'sasipea-broneering' ) . '</label><input id="sbd-log" type="text" name="log" value="' . esc_attr( $login ) . '" autocomplete="username" autocapitalize="none" required autofocus>';
			echo '<label for="sbd-pwd">' . esc_html__( 'Parool', 'sasipea-broneering' ) . '</label><input id="sbd-pwd" type="password" name="pwd" autocomplete="current-password" required>';
			echo '<label class="sbd-check"><input type="checkbox" name="rememberme" value="1"> ' . esc_html__( 'Jäta mind selles seadmes meelde', 'sasipea-broneering' ) . '</label>';
			echo '<button class="button button-primary" type="submit">' . esc_html__( 'Logi sisse', 'sasipea-broneering' ) . '</button></form>';
			if ( self::google_enabled() ) {
				echo '<p class="sbd-login-or"><span>' . esc_html__( 'või', 'sasipea-broneering' ) . '</span></p><a class="button sbd-google" href="' . esc_url( self::url( 'google' ) . '&algus=1' ) . '"><span class="sbd-g" aria-hidden="true">G</span> ' . esc_html__( 'Logi sisse Google\'iga', 'sasipea-broneering' ) . '</a>';
			}
			echo '<p class="sbd-login-foot"><a href="' . esc_url( wp_lostpassword_url( self::url() ) ) . '">' . esc_html__( 'Unustasid parooli?', 'sasipea-broneering' ) . '</a></p>';
		}
		echo '</main></body></html>';
	}

	private static function render_shell( array $views, string $current, string $body ): void {
		$set    = Settings::get();
		$user   = wp_get_current_user();
		$groups = array(
			''          => '',
			'raha'      => __( 'Raha ja ladu', 'sasipea-broneering' ),
			'seadistus' => __( 'Seadistus', 'sasipea-broneering' ),
			'konto'     => __( 'Konto', 'sasipea-broneering' ),
		);
		self::head( $views[ $current ]['label'] ?? __( 'Töölaud', 'sasipea-broneering' ) );
		echo '<body class="sbd sbd-view-' . esc_attr( $current ) . '"><a class="sbd-skip" href="#sbd-main">' . esc_html__( 'Liigu sisu juurde', 'sasipea-broneering' ) . '</a>';
		echo '<header class="sbd-top"><button type="button" class="sbd-burger" aria-controls="sbd-nav" aria-expanded="false" aria-label="' . esc_attr__( 'Menüü', 'sasipea-broneering' ) . '"><span></span><span></span><span></span></button><span class="sbd-top-title">' . esc_html( $views[ $current ]['label'] ?? '' ) . '</span></header>';
		echo '<nav id="sbd-nav" class="sbd-side" aria-label="' . esc_attr__( 'Töölaua menüü', 'sasipea-broneering' ) . '"><a class="sbd-brand" href="' . esc_url( self::url() ) . '">' . esc_html( $set['from_name'] ) . '<small>' . esc_html__( 'Salongi töölaud', 'sasipea-broneering' ) . '</small></a>';
		$open = null;
		foreach ( $views as $key => $v ) {
			if ( $v['group'] !== $open ) {
				if ( null !== $open ) {
					echo '</ul>';
				}
				$open = $v['group'];
				if ( '' !== $groups[ $open ] ) {
					echo '<p class="sbd-group">' . esc_html( $groups[ $open ] ) . '</p>';
				}
				echo '<ul>';
			}
			echo '<li><a href="' . esc_url( self::url( $key ) ) . '"' . ( $key === $current ? ' class="is-current" aria-current="page"' : '' ) . '>' . self::icon( $v['icon'] ) . '<span>' . esc_html( $v['label'] ) . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- ikoon on staatiline SVG.
		}
		echo '<li><a href="' . esc_url( wp_logout_url( self::url() ) ) . '">' . self::icon( 'logout' ) . '<span>' . esc_html__( 'Logi välja', 'sasipea-broneering' ) . '</span></a></li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sbd-who"><span class="sbd-avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) ) . '</span><span><strong>' . esc_html( $user->display_name ) . '</strong><small>' . esc_html( self::role_label() ) . '</small></span></div>';
		echo '</nav><div class="sbd-shade"></div>';
		echo '<main id="sbd-main" class="sbd-main">' . $body . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput -- vaated escape'ivad ise.
		echo "<script>(function(){var b=document.querySelector('.sbd-burger'),s=document.querySelector('.sbd-shade');function t(o){document.body.classList.toggle('sbd-open',o);b.setAttribute('aria-expanded',o?'true':'false');}b.addEventListener('click',function(){t(!document.body.classList.contains('sbd-open'));});s.addEventListener('click',function(){t(false);});document.addEventListener('keydown',function(e){if(e.key==='Escape')t(false);});document.querySelectorAll('.sbd-main table.widefat').forEach(function(x){if(x.closest('.sb-m-scroll,.sbd-scroll'))return;var w=document.createElement('div');w.className='sbd-scroll';x.parentNode.insertBefore(w,x);w.appendChild(x);});})();</script>";
		if ( wp_script_is( 'sb-planner', 'enqueued' ) ) {
			wp_print_scripts( array( 'sb-planner' ) );
		}
		echo '</body></html>';
	}
}
