<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Tabelid (dbDelta), rollid ja algandmed.
 */
final class Install {

	public static function activate(): void {
		self::create_tables();
		Roles::install();
		self::seed();
		update_option( 'sb_db_version', SB_DB_VERSION );
		Cron::schedule();
	}

	public static function maybe_upgrade(): void {
		$old = get_option( 'sb_db_version' );
		if ( $old !== SB_DB_VERSION ) {
			self::activate();
			// 0.9.3: aegu pakutakse ainult kuudes, mille töögraafik on koostatud. Seadetes saab selle välja lülitada.
			if ( false !== $old && (int) $old < 9 ) {
				$s = get_option( 'sb_settings' );
				if ( is_array( $s ) ) {
					$s['require_month'] = true;
					update_option( 'sb_settings', $s );
				}
			}
			// 0.11.0: kliendile nähtavas tekstis on "praktikant" asemel "juuksuriõpilane" (ka salvestatud kirjamallides).
			if ( false !== $old && (int) $old < 11 ) {
				$s = get_option( 'sb_settings' );
				if ( is_array( $s ) ) {
					foreach ( (array) ( $s['templates'] ?? array() ) as $k => $v ) {
						if ( 0 === strpos( (string) $k, 'client_' ) ) {
							$s['templates'][ $k ] = str_replace( 'Praktikant: {praktikant}', 'Juuksuriõpilane: {praktikant}', (string) $v );
						}
					}
					if ( isset( $s['confirmation_note'] ) ) {
						$s['confirmation_note'] = str_replace( 'Tööd teevad praktikandid', 'Tööd teevad juuksuriõpilased', (string) $s['confirmation_note'] );
					}
					update_option( 'sb_settings', $s );
				}
			}
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sb_' . $name;
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = static fn( $n ) => self::table( $n );

		$sql = array();

		$sql[] = "CREATE TABLE {$t('categories')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('services')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL,
  duration_min int(11) NOT NULL,
  price_cents int(11) NOT NULL DEFAULT 0,
  material_extra tinyint(1) NOT NULL DEFAULT 0,
  note text NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY category_id (category_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('staff')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NULL,
  name varchar(190) NOT NULL,
  photo_url varchar(255) NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  ics_token char(32) NULL,
  level varchar(40) NOT NULL DEFAULT '',
  discount_pct tinyint(3) unsigned NULL,
  extra_min smallint(5) unsigned NULL,
  pay text NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('staff_services')} (
  staff_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (staff_id,service_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('schedules')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  staff_id bigint(20) unsigned NOT NULL,
  weekday tinyint(1) NOT NULL,
  start_min smallint(5) NOT NULL,
  end_min smallint(5) NOT NULL,
  PRIMARY KEY  (id),
  KEY staff_day (staff_id,weekday)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('schedule_exceptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  staff_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  start_min smallint(5) NULL,
  end_min smallint(5) NULL,
  is_off tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY staff_day (staff_id,day)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('blocks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  staff_id bigint(20) unsigned NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  reason varchar(190) NULL,
  created_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY span (start_utc,end_utc)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('clients')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  notes text NULL,
  model_id bigint(20) unsigned NULL,
  last_contact_at datetime NULL,
  anonymized_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY phone (phone)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('bookings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL,
  staff_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  service_ids varchar(100) NULL,
  service_label varchar(255) NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  price_cents int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'booked',
  source varchar(10) NOT NULL DEFAULT 'online',
  comment text NULL,
  token_hash char(64) NULL,
  reminder_sent_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY span (start_utc,end_utc),
  KEY staff (staff_id,start_utc),
  KEY client (client_id),
  KEY token_hash (token_hash)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('consents')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  granted tinyint(1) NOT NULL,
  text_version varchar(20) NOT NULL,
  text_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY client (client_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('booking_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NULL,
  action varchar(30) NOT NULL,
  details text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking (booking_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('products')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  price_cents int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) ENGINE=InnoDB $c;";

		// Materjali ja toodete müügiread. kind: material (qty = grammid) või product (qty = tükid).
		$sql[] = "CREATE TABLE {$t('sales')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NULL,
  staff_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL,
  product_id bigint(20) unsigned NULL,
  label varchar(190) NOT NULL,
  qty decimal(10,2) NOT NULL DEFAULT 1,
  unit_cents int(11) NOT NULL DEFAULT 0,
  total_cents int(11) NOT NULL DEFAULT 0,
  sku varchar(100) NULL,
  woo_order_id bigint(20) unsigned NULL,
  payment_id bigint(20) unsigned NULL,
  sold_at datetime NOT NULL,
  created_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY booking (booking_id),
  KEY payment (payment_id),
  KEY staff_time (staff_id,sold_at)
) ENGINE=InnoDB $c;";

		// Kassa arved ja maksed. status: open (koostamisel) või paid. method: cash, card, transfer või mixed (jagatud makse; osad on veergudes *_cents).
		$sql[] = "CREATE TABLE {$t('payments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NULL,
  staff_id bigint(20) unsigned NOT NULL,
  client_name varchar(190) NOT NULL DEFAULT '',
  service_label varchar(190) NOT NULL DEFAULT '',
  service_cents int(11) NOT NULL DEFAULT 0,
  total_cents int(11) NOT NULL DEFAULT 0,
  method varchar(10) NULL,
  cash_cents int(11) NOT NULL DEFAULT 0,
  card_cents int(11) NOT NULL DEFAULT 0,
  transfer_cents int(11) NOT NULL DEFAULT 0,
  cash_given_cents int(11) NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  paid_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  paid_by bigint(20) unsigned NULL,
  email varchar(190) NULL,
  emailed_at datetime NULL,
  PRIMARY KEY  (id),
  KEY booking (booking_id),
  KEY status_paid (status,paid_at)
) ENGINE=InnoDB $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
	}

	/**
	 * Algandmed. Lisatakse ainult siis, kui tabelid on tühjad.
	 */
	public static function seed(): void {
		global $wpdb;
		if ( ! get_option( 'sb_settings' ) ) {
			update_option( 'sb_settings', Settings::defaults() );
		}
		if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'services' ) ) > 0 ) {
			return;
		}

		$catalog = array(
			'Lõikus'                     => array(
				array( 'Juukselõikus', 90, 2000, 0, '' ),
				array( 'Suure muutusega juukselõikus', 120, 2500, 0, '' ),
			),
			'Värvimine ja triibutamine'  => array(
				array( 'Värvimine, lühikesed juuksed', 180, 2500, 1, '' ),
				array( 'Värvimine, pikad juuksed', 210, 3500, 1, '' ),
				array( 'Triibutamine, osaline (kuni 25 fooliumit)', 210, 4500, 1, '' ),
				array( 'Triibutamine, terve pea', 300, 6000, 1, '' ),
			),
			'Kompleksteenused'           => array(
				array( 'Lõikus + värvimine, lühikesed juuksed', 240, 4000, 1, '' ),
				array( 'Lõikus + värvimine, pikad juuksed', 270, 5000, 1, '' ),
				array( 'Lõikus + keemiline lokk', 240, 5000, 0, 'Ainult kuni õlgadeni juuksed. Materjal on hinnas.' ),
			),
			'Soengud'                    => array(
				array( 'Soeng, kuni õlgadeni juuksed', 90, 2000, 0, '' ),
				array( 'Soeng, pikad juuksed', 120, 2500, 0, '' ),
				array( 'Ülespandud soeng', 120, 3000, 0, 'Juuksed peavad olema puhtad ja kuivad.' ),
			),
			'Juuksehooldus'              => array(
				array( 'Juuksehooldus, lühikesed juuksed', 75, 2000, 0, '' ),
				array( 'Juuksehooldus, pikad juuksed', 90, 2500, 0, '' ),
			),
		);

		$service_ids = array();
		$ci          = 0;
		foreach ( $catalog as $cat => $services ) {
			$wpdb->insert( self::table( 'categories' ), array( 'name' => $cat, 'sort_order' => $ci++ ) );
			$cat_id = (int) $wpdb->insert_id;
			$si     = 0;
			foreach ( $services as $s ) {
				$wpdb->insert(
					self::table( 'services' ),
					array(
						'category_id'    => $cat_id,
						'name'           => $s[0],
						'duration_min'   => $s[1],
						'price_cents'    => $s[2],
						'material_extra' => $s[3],
						'note'           => $s[4],
						'sort_order'     => $si++,
					)
				);
				$service_ids[] = (int) $wpdb->insert_id;
			}
		}

		// OLETUS: päris praktikantide nimesid ei tea. Loon kaks näidist, et broneerimine kohe töötaks.
		$hours = Settings::get()['opening_hours'];
		foreach ( array( 'Näidis: Praktikant 1', 'Näidis: Praktikant 2' ) as $i => $name ) {
			$wpdb->insert(
				self::table( 'staff' ),
				array(
					'name'       => $name,
					'active'     => 1,
					'sort_order' => $i,
					'ics_token'  => bin2hex( random_bytes( 16 ) ),
				)
			);
			$staff_id = (int) $wpdb->insert_id;
			foreach ( $service_ids as $sid ) {
				$wpdb->insert( self::table( 'staff_services' ), array( 'staff_id' => $staff_id, 'service_id' => $sid ) );
			}
			foreach ( $hours as $wd => $ranges ) {
				foreach ( $ranges as $r ) {
					$wpdb->insert(
						self::table( 'schedules' ),
						array(
							'staff_id'  => $staff_id,
							'weekday'   => (int) $wd,
							'start_min' => Settings::to_min( $r[0] ),
							'end_min'   => Settings::to_min( $r[1] ),
						)
					);
				}
			}
		}
	}
}
