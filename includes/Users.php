<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Töölaua kasutajakontod: omanik loob praktikandi, juhendaja või teise omaniku konto, saadab
 * kutse e-postiga, muudab rolli ja peatab konto. Parooli ei näe ega määra siin keegi peale
 * kasutaja enda: konto luuakse juhusliku parooliga ja kasutaja seab oma parooli kutse lingilt.
 *
 * Hallata saab ainult selle plugina rollidega kontosid. WordPressi administraatoreid ja teiste
 * pluginate rolle siit muuta ei saa.
 */
final class Users {

	public const DISABLED = 'sb_disabled';
	public const INVITED  = 'sb_invited';

	public static function init(): void {
		add_filter( 'authenticate', array( self::class, 'block_disabled' ), 99 );
		foreach ( array( 'save', 'invite', 'toggle' ) as $a ) {
			add_action( 'admin_post_sb_user_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	/** @return array<string,string> roll => nimi */
	public static function roles(): array {
		return array(
			Roles::PRAKTIKANT => __( 'Praktikant', 'sasipea-broneering' ),
			Roles::JUHENDAJA  => __( 'Juhendaja', 'sasipea-broneering' ),
			Roles::OMANIK     => __( 'Omanik', 'sasipea-broneering' ),
		);
	}

	/**
	 * Rollide põhjal: kas kontot tohib töölaualt hallata ja mis roll sellel on.
	 * Puhas funktsioon, et seda saaks eraldi testida.
	 *
	 * @param string[] $user_roles
	 * @return ?string hallatav roll (kõige suuremate õigustega) või null, kui kontot hallata ei tohi
	 */
	public static function managed_role( array $user_roles ): ?string {
		if ( in_array( 'administrator', $user_roles, true ) ) {
			return null;
		}
		foreach ( array( Roles::OMANIK, Roles::JUHENDAJA, Roles::PRAKTIKANT ) as $r ) {
			if ( in_array( $r, $user_roles, true ) ) {
				return $r;
			}
		}
		return null;
	}

	/** Kasutajanimi e-posti algusest: ainult a-z, 0-9, punkt, kriips ja alakriips. */
	public static function login_base( string $email ): string {
		$local = strtolower( (string) strstr( $email, '@', true ) );
		$base  = trim( (string) preg_replace( '/[^a-z0-9._-]+/', '', $local ), '._-' );
		return '' !== $base ? substr( $base, 0, 40 ) : 'kasutaja';
	}

	public static function is_disabled( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::DISABLED, true );
	}

	/** Peatatud konto ei saa sisse logida (ei töölaual ega WordPressis). */
	public static function block_disabled( $user ) {
		if ( $user instanceof \WP_User && self::is_disabled( (int) $user->ID ) ) {
			return new \WP_Error( 'sb_disabled', __( 'See konto on peatatud. Pöördu salongi omaniku poole.', 'sasipea-broneering' ) );
		}
		return $user;
	}

	/* ================= Vaade ================= */

	private static function form_open( string $action, string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		wp_nonce_field( 'sb_user_' . $action );
		echo '<input type="hidden" name="action" value="sb_user_' . esc_attr( $action ) . '">';
	}

	/** @return \WP_User[] */
	private static function accounts(): array {
		return get_users(
			array(
				'role__in' => array_merge( array_keys( self::roles() ), array( 'administrator' ), Roles::juhendaja_roles() ),
				'orderby'  => 'display_name',
				'number'   => 500,
			)
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'sb_manage_settings' ) ) {
			return;
		}
		$roles = self::roles();
		$staff = Repo::staff_list( false );
		$by_u  = array();
		foreach ( $staff as $s ) {
			if ( $s['user_id'] ) {
				$by_u[ (int) $s['user_id'] ] = $s;
			}
		}
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit    = $edit_id ? get_userdata( $edit_id ) : null;
		if ( $edit && null === self::managed_role( (array) $edit->roles ) ) {
			$edit = null;
		}
		$me = get_current_user_id();

		echo '<div class="wrap sb-users"><h1>' . esc_html__( 'Kasutajad', 'sasipea-broneering' ) . '</h1>';
		Admin::notice();
		if ( isset( $_GET['sb_invited'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Kutse saadetud. Link kehtib 24 tundi.', 'sasipea-broneering' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Siin saad luua kontod praktikantidele, juhendajatele ja teistele omanikele. Uus kasutaja saab e-kirja lingiga, kust ta seab endale ise parooli.', 'sasipea-broneering' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Nimi', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'E-post', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Roll', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Seotud praktikant', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Seis', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( self::accounts() as $u ) {
			$role     = self::managed_role( (array) $u->roles );
			$disabled = self::is_disabled( (int) $u->ID );
			$invited  = (int) get_user_meta( $u->ID, self::INVITED, true );
			if ( $role ) {
				$role_label = $roles[ $role ];
			} elseif ( in_array( 'administrator', (array) $u->roles, true ) ) {
				$role_label = __( 'WordPressi administraator', 'sasipea-broneering' );
			} else {
				$role_label = __( 'Juhendaja (modellide andmebaas)', 'sasipea-broneering' );
			}
			if ( $disabled ) {
				$state = '<span class="sb-u-state is-off">' . esc_html__( 'Peatatud', 'sasipea-broneering' ) . '</span>';
			} elseif ( $invited ) {
				/* translators: %s: date */
				$state = '<span class="sb-u-state is-wait">' . esc_html( sprintf( __( 'Kutse saadetud %s', 'sasipea-broneering' ), wp_date( 'd.m.Y', $invited, Settings::tz() ) ) ) . '</span>';
			} else {
				$state = '<span class="sb-u-state is-on">' . esc_html__( 'Aktiivne', 'sasipea-broneering' ) . '</span>';
			}
			echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong>' . ( (int) $u->ID === $me ? ' <span class="description">(' . esc_html__( 'sina', 'sasipea-broneering' ) . ')</span>' : '' ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( $role_label ) . '</td><td>' . esc_html( $by_u[ (int) $u->ID ]['name'] ?? '–' ) . '</td><td>' . $state . '</td><td class="sb-u-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $state on eespool puhastatud.
			if ( $role ) {
				echo '<a class="button button-small" href="' . esc_url( Admin::url( 'sb-users&edit=' . (int) $u->ID ) ) . '">' . esc_html__( 'Muuda', 'sasipea-broneering' ) . '</a> ';
				if ( ! $disabled ) {
					self::form_open( 'invite', 'sb-inline' );
					echo '<input type="hidden" name="id" value="' . (int) $u->ID . '"><button class="button button-small" type="submit">' . esc_html( $invited ? __( 'Saada kutse uuesti', 'sasipea-broneering' ) : __( 'Saada parooli link', 'sasipea-broneering' ) ) . '</button></form> ';
				}
				if ( (int) $u->ID !== $me ) {
					self::form_open( 'toggle', 'sb-inline' );
					echo '<input type="hidden" name="id" value="' . (int) $u->ID . '"><button class="button button-small' . ( $disabled ? '' : ' button-link-delete' ) . '" type="submit">' . esc_html( $disabled ? __( 'Taasta', 'sasipea-broneering' ) : __( 'Peata', 'sasipea-broneering' ) ) . '</button></form>';
				}
			} else {
				echo '<span class="description">' . esc_html__( 'Hallatakse WordPressis', 'sasipea-broneering' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$v = array(
			'id'    => $edit ? (int) $edit->ID : 0,
			'name'  => $edit ? $edit->display_name : '',
			'email' => $edit ? $edit->user_email : '',
			'role'  => $edit ? self::managed_role( (array) $edit->roles ) : Roles::PRAKTIKANT,
			'staff' => $edit && isset( $by_u[ (int) $edit->ID ] ) ? (int) $by_u[ (int) $edit->ID ]['id'] : 0,
		);
		echo '<h2 id="sb-u-form">' . esc_html( $edit ? __( 'Muuda kontot', 'sasipea-broneering' ) : __( 'Lisa uus kasutaja', 'sasipea-broneering' ) ) . '</h2>';
		self::form_open( 'save' );
		echo '<input type="hidden" name="id" value="' . (int) $v['id'] . '"><table class="form-table"><tbody>';
		echo '<tr><th><label for="sb-u-name">' . esc_html__( 'Nimi', 'sasipea-broneering' ) . '</label></th><td><input id="sb-u-name" name="name" class="regular-text" value="' . esc_attr( $v['name'] ) . '" required autocomplete="off"></td></tr>';
		echo '<tr><th><label for="sb-u-email">' . esc_html__( 'E-post', 'sasipea-broneering' ) . '</label></th><td><input id="sb-u-email" name="email" type="email" class="regular-text" value="' . esc_attr( $v['email'] ) . '" required autocomplete="off"><p class="description">' . esc_html__( 'Sellele aadressile saadetakse kutse. Sama aadressiga saab hiljem sisse logida.', 'sasipea-broneering' ) . '</p></td></tr>';
		echo '<tr><th><label for="sb-u-role">' . esc_html__( 'Roll', 'sasipea-broneering' ) . '</label></th><td>';
		if ( $edit && (int) $edit->ID === $me ) {
			echo '<input type="hidden" name="role" value="' . esc_attr( (string) $v['role'] ) . '"><strong>' . esc_html( $roles[ $v['role'] ] ) . '</strong><p class="description">' . esc_html__( 'Enda rolli muuta ei saa.', 'sasipea-broneering' ) . '</p>';
		} else {
			echo '<select id="sb-u-role" name="role">';
			foreach ( $roles as $k => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $v['role'], false ), esc_html( $label ) );
			}
			echo '</select><p class="description">' . esc_html__( 'Praktikant näeb ainult enda päevaplaani ja broneeringuid. Juhendaja näeb kõiki broneeringuid, kassat, töögraafikut ja kliente. Omanik näeb lisaks tasusid, käibemaksu, seadeid ja kasutajaid.', 'sasipea-broneering' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr><th><label for="sb-u-staff">' . esc_html__( 'Seotud praktikant', 'sasipea-broneering' ) . '</label></th><td><select id="sb-u-staff" name="staff_id"><option value="0">' . esc_html__( '— ei ole seotud —', 'sasipea-broneering' ) . '</option>';
		foreach ( $staff as $s ) {
			$taken = $s['user_id'] && (int) $s['user_id'] !== (int) $v['id'];
			printf( '<option value="%d"%s%s>%s%s</option>', (int) $s['id'], selected( (int) $s['id'], (int) $v['staff'], false ), disabled( $taken, true, false ), esc_html( $s['name'] ), $taken ? ' (' . esc_html__( 'juba seotud', 'sasipea-broneering' ) . ')' : '' );
		}
		echo '</select><p class="description">' . esc_html__( 'Praktikandi konto peab olema seotud praktikandiga, kelle broneeringuid ta näeb. Uue praktikandi lisad enne vaates Praktikandid.', 'sasipea-broneering' ) . '</p></td></tr>';
		echo '</tbody></table><p><button class="button button-primary" type="submit">' . esc_html( $edit ? __( 'Salvesta', 'sasipea-broneering' ) : __( 'Loo konto ja saada kutse', 'sasipea-broneering' ) ) . '</button>';
		if ( $edit ) {
			echo ' <a class="button" href="' . esc_url( Admin::url( 'sb-users' ) ) . '">' . esc_html__( 'Loobu', 'sasipea-broneering' ) . '</a>';
		}
		echo '</p></form></div>';
		echo '<style>.sb-users .sb-inline{display:inline}.sb-users .sb-u-actions{white-space:nowrap}.sb-users .sb-u-state{display:inline-block;padding:2px 10px;border-radius:999px;white-space:nowrap;font-size:13px;font-weight:600}.sb-users .sb-u-state.is-on{background:#e3f4ea;color:#0f5c30}.sb-users .sb-u-state.is-off{background:#fbe9e9;color:#8a1f1f}.sb-users .sb-u-state.is-wait{background:#fcf3e6;color:#6b4300}@media (max-width:782px){.sb-users .sb-u-actions{white-space:normal}}</style>';
	}

	/* ================= Salvestamine ================= */

	private static function fail( string $msg, int $edit = 0 ): void {
		Admin::back( 'sb-users' . ( $edit ? '&edit=' . $edit : '' ), array( 'sb_saved' => null, 'sb_error' => $msg ) );
	}

	/** Hallatav kasutaja POST-ist; administraatorit ja võõraid rolle ei anta. */
	private static function target( int $id ): ?\WP_User {
		$u = $id ? get_userdata( $id ) : null;
		return $u && null !== self::managed_role( (array) $u->roles ) ? $u : null;
	}

	public static function handle_save(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_settings', 'sb_user_save' );
		$id    = (int) Admin::post( 'id' );
		$name  = sanitize_text_field( Admin::post( 'name' ) );
		$email = sanitize_email( Admin::post( 'email' ) );
		$role  = Admin::post( 'role' );
		$staff = (int) Admin::post( 'staff_id' );
		$me    = get_current_user_id();
		if ( '' === $name || ! is_email( $email ) ) {
			self::fail( __( 'Sisesta nimi ja korrektne e-posti aadress.', 'sasipea-broneering' ), $id );
		}
		if ( ! isset( self::roles()[ $role ] ) ) {
			self::fail( __( 'Vali roll.', 'sasipea-broneering' ), $id );
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== $id ) {
			self::fail( __( 'Selle e-posti aadressiga konto on juba olemas.', 'sasipea-broneering' ), $id );
		}
		if ( Roles::PRAKTIKANT === $role && ! $staff ) {
			self::fail( __( 'Praktikandi konto tuleb siduda praktikandiga.', 'sasipea-broneering' ), $id );
		}
		$table = Install::table( 'staff' );
		if ( $staff ) {
			$linked = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM $table WHERE id = %d", $staff ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $linked || ( $linked['user_id'] && (int) $linked['user_id'] !== $id ) ) {
				self::fail( __( 'See praktikant on juba teise kontoga seotud.', 'sasipea-broneering' ), $id );
			}
		}

		$created = false;
		if ( $id ) {
			$u = self::target( $id );
			if ( ! $u ) {
				self::fail( __( 'Seda kontot siit muuta ei saa.', 'sasipea-broneering' ) );
			}
			$res = wp_update_user( array( 'ID' => $id, 'display_name' => $name, 'first_name' => $name, 'user_email' => $email ) );
			if ( is_wp_error( $res ) ) {
				self::fail( $res->get_error_message(), $id );
			}
			// Enda rolli ei saa muuta, et omanik ennast kogemata välja ei lukustaks.
			if ( $id !== $me ) {
				foreach ( array_keys( self::roles() ) as $r ) {
					$u->remove_role( $r );
				}
				$u->add_role( $role );
			}
		} else {
			$base  = self::login_base( $email );
			$login = $base;
			for ( $i = 2; username_exists( $login ); $i++ ) {
				$login = $base . $i;
			}
			$id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'display_name' => $name,
					'first_name'   => $name,
					'user_pass'    => wp_generate_password( 32, true, true ), // kasutaja seab oma parooli kutse lingilt
					'role'         => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				self::fail( $id->get_error_message() );
			}
			$id      = (int) $id;
			$created = true;
		}
		$wpdb->update( $table, array( 'user_id' => null ), array( 'user_id' => $id ) );
		if ( $staff ) {
			$wpdb->update( $table, array( 'user_id' => $id ), array( 'id' => $staff ) );
		}
		if ( $created ) {
			$sent = self::send_invite( $id );
			Admin::back( 'sb-users', $sent ? array( 'sb_saved' => null, 'sb_invited' => 1 ) : array( 'sb_error' => __( 'Konto on loodud, aga kutse saatmine e-postiga ebaõnnestus. Proovi nupuga "Saada kutse uuesti".', 'sasipea-broneering' ) ) );
		}
		Admin::back( 'sb-users' );
	}

	public static function handle_invite(): void {
		Admin::guard( 'sb_manage_settings', 'sb_user_invite' );
		$u = self::target( (int) Admin::post( 'id' ) );
		if ( ! $u || self::is_disabled( (int) $u->ID ) ) {
			self::fail( __( 'Sellele kontole ei saa kutset saata.', 'sasipea-broneering' ) );
		}
		if ( ! self::send_invite( (int) $u->ID ) ) {
			self::fail( __( 'Kutse saatmine e-postiga ebaõnnestus. Proovi uuesti.', 'sasipea-broneering' ) );
		}
		Admin::back( 'sb-users', array( 'sb_saved' => null, 'sb_invited' => 1 ) );
	}

	public static function handle_toggle(): void {
		Admin::guard( 'sb_manage_settings', 'sb_user_toggle' );
		$u = self::target( (int) Admin::post( 'id' ) );
		if ( ! $u || (int) $u->ID === get_current_user_id() ) {
			self::fail( __( 'Seda kontot ei saa peatada.', 'sasipea-broneering' ) );
		}
		if ( self::is_disabled( (int) $u->ID ) ) {
			delete_user_meta( $u->ID, self::DISABLED );
		} else {
			update_user_meta( $u->ID, self::DISABLED, time() );
			// Peatatud konto logitakse kõikjalt välja.
			\WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
		}
		Admin::back( 'sb-users' );
	}

	/** Saadab lingi, kust kasutaja seab endale parooli. Link kehtib nii kaua kui WordPressi parooli lähtestamise link (vaikimisi 24 h). */
	public static function send_invite( int $user_id ): bool {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return false;
		}
		$key = get_password_reset_key( $u );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$set  = Settings::get();
		$link = Dashboard::url( 'kutse' ) . '&key=' . rawurlencode( $key ) . '&u=' . rawurlencode( $u->user_login );
		/* translators: %s: salon name */
		$subject = sprintf( __( '%s: sinu konto salongi töölaual', 'sasipea-broneering' ), $set['from_name'] );
		$body    = sprintf(
			/* translators: 1: name, 2: salon name, 3: link, 4: e-mail, 5: dashboard address */
			__( "Tere, %1\$s!\n\n%2\$s lõi sulle konto salongi töölaual. Parooli seadmiseks ava see link (kehtib 24 tundi):\n%3\$s\n\nEdaspidi logid sisse aadressil %5\$s oma e-posti aadressiga %4\$s.\n\nKui sa seda kutset ei oodanud, võid selle kirja lihtsalt kustutada.", 'sasipea-broneering' ),
			$u->display_name,
			$set['from_name'],
			$link,
			$u->user_email,
			Dashboard::home()
		);
		$ok = wp_mail( $u->user_email, $subject, $body );
		if ( $ok ) {
			update_user_meta( $user_id, self::INVITED, time() );
		}
		return (bool) $ok;
	}
}
