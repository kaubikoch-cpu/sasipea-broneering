<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Värviladu: juuksevärvide (Fuente) kulu grammides kliendi kaupa ja salongi laoseis.
 *
 * - Värv (toon) on tabelis sb_colors: kood (nt 7.1), nimetus, tuubi suurus grammides ja soovi korral
 *   seos veebipoe tootega (tootekood, triipkood).
 * - Iga liikumine on rida tabelis sb_color_moves: lattu võtmine (+), kulu broneeringule (−) või
 *   inventuuri parandus. Laoseis on liikumiste summa, eraldi laoseisu veergu ei ole.
 * - Vesinik (oksüdant) on samuti värvilao kirje (is_ox). Igal värvil on vaikimisi segusuhe
 *   (1 osa värvi : ratio osa vesinikku); broneeringu juures arvutatakse vesiniku grammid ise ja neid saab muuta.
 * - Veebipood loeb ladu tervetes tükkides. Seepärast läheb poe laost maha terve tuub korraga
 *   (lattu võtmisel, 0 € sisekasutuse tellimusega) ja grammid arvestatakse siin.
 */
final class Colors {

	public const KINDS = array( 'in', 'use', 'adjust' );

	public static function init(): void {
		foreach ( array( 'save', 'add_shop', 'import', 'intake', 'adjust', 'undo', 'bill', 'line_ratio' ) as $a ) {
			add_action( 'admin_post_sb_color_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	private static function t( string $n ): string {
		return Install::table( $n );
	}

	/* ================= Puhtad abifunktsioonid (testitavad) ================= */

	/** "25,5" või "25.5 g" -> 25.5; vigane või lubamatu kogus -> null. */
	public static function parse_grams( string $s ): ?float {
		$s = str_replace( array( ' ', 'g', 'G' ), '', str_replace( ',', '.', trim( $s ) ) );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $s ) ) {
			return null;
		}
		$g = round( (float) $s, 2 );
		return $g > 0 && $g <= 5000 ? $g : null;
	}

	/** Grammid kirjapildis: 30 g, 25,5 g, −12 g. */
	public static function g( float $grams ): string {
		$n = rtrim( rtrim( number_format( abs( $grams ), 2, ',', ' ' ), '0' ), ',' );
		return ( $grams < 0 ? '−' : '' ) . $n . ' g';
	}

	/** Pakendi suurus toote nimest ("... 100 ml", "1000ml", "60 g"); kui nimes pole, siis 100. */
	public static function pack_from_name( string $name ): float {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*(ml|g|gr|l)\b/iu', $name, $m ) ) {
			$n = (float) str_replace( ',', '.', $m[1] );
			if ( 'l' === strtolower( $m[2] ) ) {
				$n *= 1000;
			}
			if ( $n >= 5 && $n <= 5000 ) {
				return $n;
			}
		}
		return 100.0;
	}

	/** Värvikood toote nimest: "Fuente Colour 7.1 tuhkblond 100 ml" -> "7.1". Kui ei leia, tühi. */
	public static function code_from_name( string $name ): string {
		// Pakendi suurus ja protsendid ei ole värvikood.
		$clean = (string) preg_replace( '/\d+(?:[.,]\d+)?\s*(ml|g|gr|l|%|vol)\b/iu', ' ', $name . ' ' );
		$clean = (string) preg_replace( '/\d+(?:[.,]\d+)?\s*%/u', ' ', $clean );
		if ( preg_match( '/(?<![\w.,\/])(\d{1,3}(?:[.,\/-]\d{1,3}){0,2}[A-Za-z]{0,2})(?![\w])/u', $clean, $m ) ) {
			return str_replace( ',', '.', $m[1] );
		}
		return '';
	}

	public const RATIOS = array( '0' => 0.0, '1' => 1.0, '1.5' => 1.5, '2' => 2.0, '3' => 3.0 );

	/** Segusuhe kirjapildis: 1.5 -> "1:1,5"; 0 -> "ilma vesinikuta". */
	public static function ratio_label( float $ratio ): string {
		if ( $ratio <= 0 ) {
			return __( 'ilma vesinikuta', 'sasipea-broneering' );
		}
		return '1:' . rtrim( rtrim( number_format( $ratio, 2, ',', '' ), '0' ), ',' );
	}

	/** Lubatud segusuhe vormist ("1.5", "1,5") või null. */
	public static function parse_ratio( string $s ): ?float {
		$s = str_replace( ',', '.', trim( $s ) );
		return isset( self::RATIOS[ $s ] ) ? self::RATIOS[ $s ] : null;
	}

	/** Vesiniku grammid värvi grammide ja segusuhte järgi (täisgrammideks ümardatud). */
	public static function ox_grams( float $color_g, float $ratio ): float {
		return $ratio > 0 && $color_g > 0 ? (float) round( $color_g * $ratio ) : 0.0;
	}

	/** Kas toode on nime järgi vesinik (oksüdant). */
	public static function is_ox_name( string $name ): bool {
		return (bool) preg_match( '/oxid|oksüd|developer|ilmuti|vesinik|activator|aktivaator/u', mb_strtolower( $name ) );
	}

	/** Vesiniku lühitähis nimest: "Fuente Oxidant 6% 1000 ml" -> "6%" (10 vol -> "10 vol"). */
	public static function ox_code( string $name ): string {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*%/u', $name, $m ) ) {
			return str_replace( '.', ',', $m[1] ) . '%';
		}
		return preg_match( '/(\d+)\s*vol/iu', $name, $m ) ? $m[1] . ' vol' : '';
	}

	/** Vaikimisi segusuhe toote nime järgi: vesinikul 0, pulbril 1:2, muul 1:1. Muudetav värvi või liini kaupa. */
	public static function ratio_from_name( string $name ): float {
		if ( self::is_ox_name( $name ) ) {
			return 0.0;
		}
		return preg_match( '/pulber|powder/u', mb_strtolower( $name ) ) ? 2.0 : 1.0;
	}

	/** Mitu tuubi laoseis teeb: "≈ 2,3 tuubi". */
	public static function packs_label( float $stock_g, float $pack_g ): string {
		if ( $pack_g <= 0 ) {
			return '';
		}
		return '≈ ' . rtrim( rtrim( number_format( max( 0, $stock_g ) / $pack_g, 1, ',', '' ), '0' ), ',' ) . ' ' . __( 'tuubi', 'sasipea-broneering' );
	}

	/** Laoseisu tase: out (otsas), low (alla poole tuubi) või ok. */
	public static function level( float $stock_g, float $pack_g ): string {
		if ( $stock_g <= 0 ) {
			return 'out';
		}
		return $stock_g < $pack_g / 2 ? 'low' : 'ok';
	}

	/**
	 * Värviretsept ühe reana: "7.1 30 g + 8.3 20 g".
	 *
	 * @param list<array{code:string,name?:string,grams:float}> $items
	 */
	public static function formula_text( array $items ): string {
		return implode(
			' + ',
			array_map(
				static fn( $i ) => trim( ( '' !== (string) $i['code'] ? $i['code'] : (string) ( $i['name'] ?? '' ) ) . ' ' . self::g( (float) $i['grams'] ) ),
				$items
			)
		);
	}

	/**
	 * Poe tootenimekirja koopiast need tooted, mille nimes on kõik otsisõnad (nt "fuente").
	 *
	 * @param list<array{p:int,v:int,n:string,s:string,g:string}> $items
	 * @return list<array{p:int,v:int,n:string,s:string,g:string}>
	 */
	public static function filter_index( array $items, string $q ): array {
		$words = array_values( array_filter( preg_split( '/\s+/', mb_strtolower( trim( $q ) ) ) ) );
		if ( ! $words ) {
			return array();
		}
		return array_values(
			array_filter(
				$items,
				static function ( $it ) use ( $words ) {
					$name = mb_strtolower( (string) $it['n'] );
					foreach ( $words as $w ) {
						if ( false === mb_strpos( $name, $w ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/* ================= Andmed ================= */

	private static function out( array $r ): array {
		$stock = round( (float) ( $r['stock_g'] ?? 0 ), 2 );
		$pack  = (float) $r['pack_g'];
		return array(
			'id'          => (int) $r['id'],
			'code'        => (string) $r['code'],
			'name'        => (string) $r['name'],
			'sku'         => (string) $r['sku'],
			'gtin'        => (string) $r['gtin'],
			'woo_pid'     => (int) $r['woo_product_id'],
			'woo_vid'     => (int) $r['woo_variation_id'],
			'pack_g'      => $pack,
			'active'      => (bool) $r['active'],
			'bill'        => isset( $r['bill'] ) ? (string) $r['bill'] : null,
			'is_ox'       => ! empty( $r['is_ox'] ),
			'ratio'       => (float) ( $r['ratio'] ?? 1 ),
			'stock_g'     => $stock,
			'stock_label' => self::g( $stock ),
			'packs_label' => self::packs_label( $stock, $pack ),
			'level'       => self::level( $stock, $pack ),
			'title'       => trim( $r['code'] . ' – ' . $r['name'], ' –' ),
		);
	}

	/** Kõik värvid koos laoseisuga, koodi järgi loomulikus järjekorras (5.0, 6.1, 10.32). */
	public static function all( bool $active_only = true ): array {
		global $wpdb;
		$where = $active_only ? 'WHERE c.active = 1' : '';
		$rows  = $wpdb->get_results( 'SELECT c.*, COALESCE(SUM(m.grams), 0) AS stock_g FROM ' . self::t( 'colors' ) . ' c LEFT JOIN ' . self::t( 'color_moves' ) . " m ON m.color_id = c.id $where GROUP BY c.id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array_map( array( self::class, 'out' ), $rows ?: array() );
		usort( $out, static fn( $a, $b ) => strnatcasecmp( $a['code'], $b['code'] ) ?: strnatcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT c.*, (SELECT COALESCE(SUM(m.grams), 0) FROM ' . self::t( 'color_moves' ) . ' m WHERE m.color_id = c.id) AS stock_g FROM ' . self::t( 'colors' ) . ' c WHERE c.id = %d', $id ), ARRAY_A );
		return $r ? self::out( $r ) : null;
	}

	private static function move( array $row ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'color_moves' ),
			$row + array(
				'created_at' => Repo::utc( time() ),
				'created_by' => get_current_user_id() ?: null,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public const NO_BILL = '-';

	/**
	 * Materjal, mille grammihinnaga värv kliendi arvele läheb; null = arvele ei lisata.
	 * Värvil võib olla oma valik (nt blondeerimispulber teise hinnaga, oksüdant arvele ei lähe);
	 * muidu kehtib värvilao üldine valik.
	 *
	 * @param string|null $own värvi enda valik: null = üldine, '-' = ei lisa, muidu materjali id
	 */
	public static function bill_material( ?string $own = null ): ?array {
		$s  = Settings::get();
		$id = null !== $own && '' !== $own ? $own : (string) ( $s['color_material'] ?? '' );
		if ( self::NO_BILL === $id ) {
			return null;
		}
		foreach ( (array) $s['materials'] as $m ) {
			if ( '' !== $id && (string) $m['id'] === $id ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Märgib broneeringule kulunud värvi: laoseis väheneb ja (kui seadistatud) lisandub materjali rida kliendi arvele.
	 * Laoseis võib minna miinusesse: töö ei tohi jääda seisma, kui tuub on lattu võtmata.
	 *
	 * @param array $booking Repo::booking() rida
	 * @return int|\WP_Error liikumise id
	 */
	public static function add_use( array $booking, int $color_id, float $grams ) {
		global $wpdb;
		$c = self::get( $color_id );
		if ( ! $c || $grams <= 0 ) {
			return new \WP_Error( 'sb_invalid', __( 'Vali värv ja sisesta grammid.', 'sasipea-broneering' ), array( 'status' => 400 ) );
		}
		$sale_id = null;
		$mat     = self::bill_material( $c['bill'] );
		if ( $mat && ! Kassa::paid_booking_ids( array( (int) $booking['id'] ) ) ) {
			$sale = Repo::add_sale( (int) $booking['staff_id'], (int) $booking['id'], 'material:' . $mat['id'], $grams, Repo::ts( $booking['start_utc'] ) );
			if ( ! is_wp_error( $sale ) ) {
				$sale_id = (int) $sale;
				$wpdb->update( self::t( 'sales' ), array( 'label' => mb_substr( trim( $mat['name'] . ' ' . ( '' !== $c['code'] ? $c['code'] : $c['name'] ) ), 0, 190 ) ), array( 'id' => $sale_id ) );
			}
		}
		return self::move(
			array(
				'color_id'   => $color_id,
				'kind'       => 'use',
				'grams'      => -$grams,
				'booking_id' => (int) $booking['id'],
				'client_id'  => (int) $booking['client_id'],
				'staff_id'   => (int) $booking['staff_id'],
				'sale_id'    => $sale_id,
			)
		);
	}

	/**
	 * Eemaldab kulurea: grammid tulevad lattu tagasi. Kliendi arve rida eemaldatakse ainult siis, kui arve pole makstud.
	 *
	 * @return int|null broneeringu id
	 */
	public static function delete_use( int $move_id ): ?int {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'color_moves' ) . " WHERE id = %d AND kind = 'use'", $move_id ), ARRAY_A );
		if ( ! $m ) {
			return null;
		}
		if ( $m['sale_id'] ) {
			$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'sales' ) . ' s JOIN ' . self::t( 'payments' ) . " p ON p.id = s.payment_id WHERE s.id = %d AND p.status = 'paid'", (int) $m['sale_id'] ) );
			if ( ! $paid ) {
				$wpdb->delete( self::t( 'sales' ), array( 'id' => (int) $m['sale_id'] ) );
			}
		}
		$wpdb->delete( self::t( 'color_moves' ), array( 'id' => $move_id ) );
		return (int) $m['booking_id'];
	}

	private static function use_out( array $r ): array {
		$g = abs( (float) $r['grams'] );
		return array(
			'id'    => (int) $r['id'],
			'code'  => (string) $r['code'],
			'name'  => (string) $r['name'],
			'grams' => $g,
			'label' => trim( $r['code'] . ' ' . $r['name'] ) . ' · ' . self::g( $g ),
		);
	}

	public static function uses_for_booking( int $booking_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.*, c.code, c.name FROM ' . self::t( 'color_moves' ) . ' m JOIN ' . self::t( 'colors' ) . " c ON c.id = m.color_id WHERE m.booking_id = %d AND m.kind = 'use' ORDER BY m.id", $booking_id ), ARRAY_A );
		return array_map( array( self::class, 'use_out' ), $rows ?: array() );
	}

	/**
	 * Kliendi värviretseptid külastuste kaupa, uuemad ees.
	 *
	 * @return list<array{booking_id:int,date:string,service:string,staff:string,items:list<array>,text:string}>
	 */
	public static function client_formulas( int $client_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.id, m.grams, m.booking_id, c.code, c.name, b.start_utc, COALESCE(b.service_label, s.name) AS service_name, st.name AS staff_name
				 FROM ' . self::t( 'color_moves' ) . ' m
				 JOIN ' . self::t( 'colors' ) . ' c ON c.id = m.color_id
				 JOIN ' . self::t( 'bookings' ) . ' b ON b.id = m.booking_id
				 LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id
				 LEFT JOIN ' . self::t( 'staff' ) . " st ON st.id = b.staff_id
				 WHERE m.client_id = %d AND m.kind = 'use' ORDER BY b.start_utc DESC, m.id",
				$client_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$bid = (int) $r['booking_id'];
			if ( ! isset( $out[ $bid ] ) ) {
				$out[ $bid ] = array(
					'booking_id' => $bid,
					'date'       => Format::local( $r['start_utc'] )->format( 'd.m.Y' ),
					'service'    => (string) $r['service_name'],
					'staff'      => (string) $r['staff_name'],
					'items'      => array(),
				);
			}
			$out[ $bid ]['items'][] = self::use_out( $r );
		}
		foreach ( $out as $bid => $v ) {
			$out[ $bid ]['text'] = self::formula_text( $v['items'] );
		}
		return array_values( $out );
	}

	/** Värvide loend päevaplaani valikusse. */
	public static function picker(): array {
		return array_map(
			static fn( $c ) => array(
				'id'    => $c['id'],
				'code'  => $c['code'],
				'name'  => $c['name'],
				'sku'   => $c['sku'],
				'pack'  => self::g( $c['pack_g'] ),
				'is_ox' => $c['is_ox'],
				'ratio' => $c['is_ox'] ? 0 : $c['ratio'],
				'title' => $c['title'],
				'stock' => $c['stock_label'],
				'level' => $c['level'],
			),
			self::all( true )
		);
	}

	/* ================= Leht ================= */

	private static function form_open( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-c-form">';
		wp_nonce_field( 'sb_color_' . $action );
		echo '<input type="hidden" name="action" value="sb_color_' . esc_attr( $action ) . '">';
	}

	private static function styles(): void {
		echo '<style>
			.sb-colors .sb-c-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:12px 0}
			.sb-colors .sb-c-top input[type=search]{min-width:240px}
			.sb-colors .sb-c-code{font-weight:700;white-space:nowrap}
			.sb-colors .sb-c-num{text-align:right;white-space:nowrap}
			.sb-colors .sb-c-tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600}
			.sb-colors .sb-c-out{background:#fde2e1;color:#8a1f1b}
			.sb-colors .sb-c-low{background:#fdf0cc;color:#6b4a00}
			.sb-colors .sb-c-off{color:#777}
			.sb-colors .sb-c-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;max-width:980px;margin:16px 0}
			.sb-colors .sb-c-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}
			.sb-colors .sb-c-card h3{margin:0 0 8px;font-size:15px}
			.sb-colors .sb-c-card label{display:block;margin:8px 0 2px;font-weight:600}
			.sb-colors .sb-c-card input[type=text],.sb-colors .sb-c-card input[type=number],.sb-colors .sb-c-card input[type=search],.sb-colors .sb-c-card select{width:100%;max-width:100%;box-sizing:border-box}
			.sb-colors .sb-c-card .button{margin-top:10px}
			.sb-colors .sb-c-check{font-weight:400!important}
			.sb-colors .sb-c-big{font-size:26px;font-weight:700;margin:4px 0}
			.sb-colors .sb-c-hits button{display:flex;justify-content:space-between;gap:10px;width:100%;margin-top:4px;padding:8px 10px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;text-align:left;font:inherit;cursor:pointer}
			.sb-colors .sb-c-hits button:hover,.sb-colors .sb-c-hits button:focus{border-color:#8a2b52;background:#f6e9ef}
			.sb-colors .sb-c-hits small{color:#50575e;white-space:nowrap}
			.sb-colors .sb-c-plus{color:#17663a}.sb-colors .sb-c-minus{color:#8a1f1b}
			.sb-colors .sb-c-inline{display:inline}
			.sb-colors .sb-c-linkbtn{background:none;border:0;padding:0;color:#b32d2e;text-decoration:underline;cursor:pointer;font:inherit}
		</style>';
	}

	public static function page(): void {
		$id = isset( $_GET['varv'] ) ? (int) $_GET['varv'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap sb-colors">';
		self::styles();
		$c = $id ? self::get( $id ) : null;
		if ( $c ) {
			self::page_color( $c );
		} else {
			self::page_list();
		}
		echo '</div>';
	}

	private static function page_list(): void {
		$colors = self::all( false );
		$shop   = Integrations\Woo::enabled();
		echo '<h1>' . esc_html__( 'Värviladu', 'sasipea-broneering' ) . '</h1>';
		Admin::notice();
		if ( isset( $_GET['sb_added'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %d: number of colours */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Lisatud %d värvi.', 'sasipea-broneering' ), (int) $_GET['sb_added'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['sb_ratio'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %d: number of colours */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Segusuhe muudetud %d värvil.', 'sasipea-broneering' ), (int) $_GET['sb_ratio'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p class="description" style="max-width:900px">' . esc_html__( 'Siin on salongi värvid grammides. Tuub võetakse lattu tervena, kulu märgitakse päevaplaanis broneeringu juurde värvikoodi ja grammidega. Kulu läheb kliendikaardile ja laoseis väheneb.', 'sasipea-broneering' ) . '</p>';

		$low = count( array_filter( $colors, static fn( $x ) => $x['active'] && 'ok' !== $x['level'] ) );
		echo '<div class="sb-c-top"><input type="search" id="sb-c-filter" placeholder="' . esc_attr__( 'Otsi koodi või nime järgi', 'sasipea-broneering' ) . '" aria-label="' . esc_attr__( 'Otsi värvi', 'sasipea-broneering' ) . '">';
		echo '<label><input type="checkbox" id="sb-c-onlylow"> ' . esc_html( sprintf( /* translators: %d: count */ __( 'Ainult otsas või vähe (%d)', 'sasipea-broneering' ), $low ) ) . '</label></div>';
		echo '<table class="widefat striped sb-c-list"><thead><tr><th>' . esc_html__( 'Kood', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Nimetus', 'sasipea-broneering' ) . '</th><th class="sb-c-num">' . esc_html__( 'Tuub', 'sasipea-broneering' ) . '</th><th class="sb-c-num">' . esc_html__( 'Laos', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Tuubides', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Segusuhe', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody id="sb-c-rows">';
		if ( ! $colors ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Värve pole veel lisatud. Lisa need allpool poest või käsitsi.', 'sasipea-broneering' ) . '</td></tr>';
		}
		foreach ( $colors as $c ) {
			$tag = '';
			if ( ! $c['active'] ) {
				$tag = ' <span class="sb-c-off">(' . esc_html__( 'peidetud', 'sasipea-broneering' ) . ')</span>';
			} elseif ( 'out' === $c['level'] ) {
				$tag = ' <span class="sb-c-tag sb-c-out">' . esc_html__( 'otsas', 'sasipea-broneering' ) . '</span>';
			} elseif ( 'low' === $c['level'] ) {
				$tag = ' <span class="sb-c-tag sb-c-low">' . esc_html__( 'vähe', 'sasipea-broneering' ) . '</span>';
			}
			printf(
				'<tr data-q="%s" data-low="%d"><td class="sb-c-code">%s</td><td>%s%s</td><td class="sb-c-num">%s</td><td class="sb-c-num"><strong>%s</strong></td><td>%s</td><td>%s</td><td><a class="button" href="%s">%s</a></td></tr>',
				esc_attr( mb_strtolower( $c['code'] . ' ' . $c['name'] . ' ' . $c['sku'] . ' ' . $c['gtin'] ) ),
				$c['active'] && 'ok' !== $c['level'] ? 1 : 0,
				esc_html( $c['code'] ),
				esc_html( $c['name'] ),
				$tag, // phpcs:ignore WordPress.Security.EscapeOutput -- koostatud ülal esc_html-iga.
				esc_html( self::g( $c['pack_g'] ) ),
				esc_html( $c['stock_label'] ),
				esc_html( $c['packs_label'] ),
				esc_html( $c['is_ox'] ? __( 'vesinik', 'sasipea-broneering' ) : self::ratio_label( $c['ratio'] ) ),
				esc_url( Admin::url( 'sb-colors&varv=' . $c['id'] ) ),
				esc_html__( 'Lattu / muuda', 'sasipea-broneering' )
			);
		}
		echo '</tbody></table>';
		echo "<script>(function(){var f=document.getElementById('sb-c-filter'),l=document.getElementById('sb-c-onlylow'),rows=[].slice.call(document.querySelectorAll('#sb-c-rows tr[data-q]'));function run(){var q=f.value.trim().toLowerCase();rows.forEach(function(r){var ok=(!q||r.getAttribute('data-q').indexOf(q)>-1)&&(!l.checked||r.getAttribute('data-low')==='1');r.style.display=ok?'':'none';});}f.addEventListener('input',run);l.addEventListener('change',run);})();</script>";

		echo '<h2>' . esc_html__( 'Lisa värve', 'sasipea-broneering' ) . '</h2><div class="sb-c-cards">';
		if ( $shop ) {
			echo '<div class="sb-c-card"><h3>' . esc_html__( 'Üks värv poest', 'sasipea-broneering' ) . '</h3>';
			self::form_open( 'add_shop' );
			echo '<label for="sb-c-shop">' . esc_html__( 'Nimi, triipkood või tootekood', 'sasipea-broneering' ) . '</label><input type="search" id="sb-c-shop" autocomplete="off" placeholder="' . esc_attr__( 'nt fuente 7.1', 'sasipea-broneering' ) . '" data-rest="' . esc_url( rest_url( 'sasipea/v1/admin/shop-search' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"><input type="hidden" name="shop_key" id="sb-c-shop-key"><div id="sb-c-shop-list" class="sb-c-hits" role="listbox" aria-live="polite"></div></form>';
			echo "<script>(function(){var q=document.getElementById('sb-c-shop'),key=document.getElementById('sb-c-shop-key'),list=document.getElementById('sb-c-shop-list'),seq=0,timer;function msg(t){list.textContent='';var e=document.createElement('em');e.textContent=t;list.appendChild(e);}function run(){var v=q.value.trim();if(v.length<2){list.textContent='';return;}var my=++seq;msg(" . wp_json_encode( __( 'Otsin…', 'sasipea-broneering' ) ) . ");var u=q.getAttribute('data-rest');fetch(u+(u.indexOf('?')<0?'?':'&')+'q='+encodeURIComponent(v),{credentials:'same-origin',headers:{'X-WP-Nonce':q.getAttribute('data-nonce')}}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(j){if(my!==seq)return;list.textContent='';if(!j.length){msg(" . wp_json_encode( __( 'Poest ei leitud midagi.', 'sasipea-broneering' ) ) . ");return;}j.forEach(function(it){var b=document.createElement('button');b.type='button';var a=document.createElement('span');a.textContent=it.name;var m=document.createElement('small');m.textContent=[it.gtin||it.sku,it.stock_label].filter(Boolean).join(' · ');b.appendChild(a);b.appendChild(m);b.addEventListener('click',function(){key.value=it.key;q.form.submit();});list.appendChild(b);});}).catch(function(){if(my===seq)msg(" . wp_json_encode( __( 'Otsing ebaõnnestus. Proovi uuesti.', 'sasipea-broneering' ) ) . ");});}q.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(run,300);});q.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();clearTimeout(timer);run();}});})();</script>";
			echo '</div>';

			$idx = Integrations\Woo::index();
			echo '<div class="sb-c-card"><h3>' . esc_html__( 'Kõik korraga poe tootenimekirjast', 'sasipea-broneering' ) . '</h3>';
			self::form_open( 'import' );
			echo '<label for="sb-c-imp">' . esc_html__( 'Toote nimes peab olema', 'sasipea-broneering' ) . '</label><input type="text" id="sb-c-imp" name="q" value="Fuente" required>';
			echo '<p class="description">' . esc_html( sprintf( /* translators: %d: products in the local copy */ __( 'Lisab kõik poe tooted, mille nimes on need sõnad (koopias on %d toodet). Olemasolevaid ei dubleerita, laoseisu ei muudeta. Kood ja tuubi suurus võetakse nimest ja neid saab hiljem parandada.', 'sasipea-broneering' ), count( $idx['items'] ) ) ) . '</p>';
			echo '<button class="button" type="submit">' . esc_html__( 'Lisa leitud värvid', 'sasipea-broneering' ) . '</button></form></div>';
		}
		echo '<div class="sb-c-card"><h3>' . esc_html__( 'Käsitsi', 'sasipea-broneering' ) . '</h3>';
		self::edit_form( array( 'id' => 0, 'code' => '', 'name' => 'Fuente', 'pack_g' => 100.0, 'sku' => '', 'gtin' => '', 'active' => true, 'bill' => null, 'is_ox' => false, 'ratio' => 1.0 ) );
		echo '</div></div>';

		echo '<h2>' . esc_html__( 'Vesinik ja segusuhe', 'sasipea-broneering' ) . '</h2><div class="sb-c-cards"><div class="sb-c-card"><h3>' . esc_html__( 'Segusuhe terve liini kaupa', 'sasipea-broneering' ) . '</h3>';
		self::form_open( 'line_ratio' );
		echo '<label for="sb-c-lq">' . esc_html__( 'Värvi nimes on', 'sasipea-broneering' ) . '</label><input type="text" id="sb-c-lq" name="q" required placeholder="' . esc_attr__( 'nt Shine', 'sasipea-broneering' ) . '">';
		echo '<label for="sb-c-lr">' . esc_html__( 'Segusuhe (värv : vesinik)', 'sasipea-broneering' ) . '</label><select id="sb-c-lr" name="ratio">';
		foreach ( self::RATIOS as $k => $r ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( '1.5' === (string) $k, true, false ), esc_html( self::ratio_label( $r ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'See on vaikimisi suhe. Broneeringu juures arvutatakse vesiniku grammid selle järgi ise, aga iga kliendi puhul saab suhet või gramme muuta.', 'sasipea-broneering' ) . '</p><button class="button" type="submit">' . esc_html__( 'Määra kõigile', 'sasipea-broneering' ) . '</button></form></div></div>';

		if ( ! current_user_can( 'sb_manage_settings' ) ) {
			return;
		}
		$mat = self::bill_material();
		echo '<h2>' . esc_html__( 'Kliendi arve', 'sasipea-broneering' ) . '</h2><div class="sb-c-cards"><div class="sb-c-card">';
		self::form_open( 'bill' );
		echo '<label for="sb-c-bill">' . esc_html__( 'Kulunud värv läheb kliendi arvele', 'sasipea-broneering' ) . '</label><select id="sb-c-bill" name="material"><option value="">' . esc_html__( 'ei lisa arvele (ainult ladu ja kliendikaart)', 'sasipea-broneering' ) . '</option>';
		foreach ( (array) Settings::get()['materials'] as $m ) {
			printf( '<option value="%s"%s>%s (%s/g)</option>', esc_attr( $m['id'] ), selected( $mat && $mat['id'] === $m['id'], true, false ), esc_html( $m['name'] ), esc_html( Settings::money( (int) $m['cents_per_g'] ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Grammihinda muudetakse seadetes materjalide all. Makstud arvele ridu juurde ei lisata.', 'sasipea-broneering' ) . '</p><button class="button" type="submit">' . esc_html__( 'Salvesta', 'sasipea-broneering' ) . '</button></form></div></div>';
	}

	private static function edit_form( array $c ): void {
		self::form_open( 'save' );
		echo '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
		$n = (int) $c['id'];
		printf( '<label for="sb-c-code%d">%s</label><input type="text" id="sb-c-code%d" name="code" value="%s" required placeholder="7.1">', $n, esc_html__( 'Värvikood', 'sasipea-broneering' ), $n, esc_attr( $c['code'] ) );
		printf( '<label for="sb-c-name%d">%s</label><input type="text" id="sb-c-name%d" name="name" value="%s" required>', $n, esc_html__( 'Nimetus', 'sasipea-broneering' ), $n, esc_attr( $c['name'] ) );
		printf( '<label for="sb-c-pack%d">%s</label><input type="text" id="sb-c-pack%d" name="pack_g" inputmode="decimal" value="%s" required>', $n, esc_html__( 'Tuubi või pudeli suurus (g)', 'sasipea-broneering' ), $n, esc_attr( rtrim( rtrim( number_format( (float) $c['pack_g'], 2, ',', '' ), '0' ), ',' ) ) );
		printf( '<label for="sb-c-sku%d">%s</label><input type="text" id="sb-c-sku%d" name="sku" value="%s">', $n, esc_html__( 'Tootekood (pole kohustuslik)', 'sasipea-broneering' ), $n, esc_attr( $c['sku'] ) );
		printf( '<label for="sb-c-ratio%d">%s</label><select id="sb-c-ratio%d" name="ratio">', $n, esc_html__( 'Segusuhe (värv : vesinik)', 'sasipea-broneering' ), $n );
		foreach ( self::RATIOS as $k => $r ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( abs( (float) $c['ratio'] - $r ) < 0.001, true, false ), esc_html( self::ratio_label( $r ) ) );
		}
		echo '</select>';
		echo '<label class="sb-c-check"><input type="checkbox" name="is_ox" value="1"' . checked( ! empty( $c['is_ox'] ), true, false ) . '> ' . esc_html__( 'See on vesinik (oksüdant)', 'sasipea-broneering' ) . '</label>';
		$def = self::bill_material();
		printf( '<label for="sb-c-bill%d">%s</label><select id="sb-c-bill%d" name="bill">', $n, esc_html__( 'Kliendi arvele', 'sasipea-broneering' ), $n );
		/* translators: %s: default material name or "ei lisa" */
		echo '<option value="">' . esc_html( sprintf( __( 'nagu värviladu üldiselt (%s)', 'sasipea-broneering' ), $def ? $def['name'] : __( 'ei lisa', 'sasipea-broneering' ) ) ) . '</option>';
		echo '<option value="' . esc_attr( self::NO_BILL ) . '"' . selected( $c['bill'] ?? '', self::NO_BILL, false ) . '>' . esc_html__( 'ei lisa arvele', 'sasipea-broneering' ) . '</option>';
		foreach ( (array) Settings::get()['materials'] as $m ) {
			printf( '<option value="%s"%s>%s (%s/g)</option>', esc_attr( $m['id'] ), selected( $c['bill'] ?? '', (string) $m['id'], false ), esc_html( $m['name'] ), esc_html( Settings::money( (int) $m['cents_per_g'] ) ) );
		}
		echo '</select>';
		if ( $c['id'] ) {
			echo '<label class="sb-c-check"><input type="checkbox" name="active" value="1"' . checked( $c['active'], true, false ) . '> ' . esc_html__( 'Kasutusel (näha päevaplaani valikus)', 'sasipea-broneering' ) . '</label>';
		} else {
			echo '<input type="hidden" name="active" value="1">';
		}
		echo '<button class="button' . ( $c['id'] ? '' : ' button-primary' ) . '" type="submit">' . ( $c['id'] ? esc_html__( 'Salvesta', 'sasipea-broneering' ) : esc_html__( 'Lisa värv', 'sasipea-broneering' ) ) . '</button></form>';
	}

	private static function page_color( array $c ): void {
		global $wpdb;
		echo '<p><a href="' . esc_url( Admin::url( 'sb-colors' ) ) . '">‹ ' . esc_html__( 'Tagasi värvilattu', 'sasipea-broneering' ) . '</a></p>';
		echo '<h1>' . esc_html( $c['title'] ) . '</h1>';
		Admin::notice();
		$linked = $c['woo_pid'] && Integrations\Woo::enabled();
		echo '<div class="sb-c-cards">';

		echo '<div class="sb-c-card"><h3>' . esc_html__( 'Laos', 'sasipea-broneering' ) . '</h3><div class="sb-c-big">' . esc_html( $c['stock_label'] ) . '</div><div>' . esc_html( $c['packs_label'] ) . ' · ' . esc_html( sprintf( /* translators: %s: grams */ __( 'tuub %s', 'sasipea-broneering' ), self::g( $c['pack_g'] ) ) ) . '</div>';
		if ( '' !== $c['sku'] || '' !== $c['gtin'] ) {
			echo '<p class="description">' . esc_html( implode( ' · ', array_filter( array( $c['sku'] ? __( 'Tootekood', 'sasipea-broneering' ) . ' ' . $c['sku'] : '', $c['gtin'] ? __( 'Triipkood', 'sasipea-broneering' ) . ' ' . $c['gtin'] : '' ) ) ) ) . '</p>';
		}
		echo '</div>';

		echo '<div class="sb-c-card"><h3>' . esc_html__( 'Võta lattu', 'sasipea-broneering' ) . '</h3>';
		self::form_open( 'intake' );
		echo '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
		echo '<label for="sb-c-n">' . esc_html__( 'Mitu tuubi', 'sasipea-broneering' ) . '</label><input type="number" id="sb-c-n" name="packs" min="1" max="200" value="1" required>';
		if ( $linked ) {
			echo '<label class="sb-c-check"><input type="checkbox" name="from_shop" value="1"' . checked( ! empty( Settings::get()['color_from_shop'] ), true, false ) . '> ' . esc_html__( 'Kanna veebipoe laost maha', 'sasipea-broneering' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Linnukesega tehakse veebipoodi 0 € tellimus märkega "Akadeemia sisekasutus" ja poe laoseis väheneb. Ilma linnukeseta poodi ei puudutata (nt kui tuub osteti mujalt).', 'sasipea-broneering' ) . '</p>';
		} elseif ( Integrations\Woo::enabled() ) {
			echo '<p class="description">' . esc_html__( 'See värv pole veebipoe tootega seotud, seega poe laoseisu ei muudeta.', 'sasipea-broneering' ) . '</p>';
		}
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Võta lattu', 'sasipea-broneering' ) . '</button></form></div>';

		echo '<div class="sb-c-card"><h3>' . esc_html__( 'Inventuur', 'sasipea-broneering' ) . '</h3>';
		self::form_open( 'adjust' );
		echo '<input type="hidden" name="id" value="' . (int) $c['id'] . '">';
		echo '<label for="sb-c-real">' . esc_html__( 'Tegelikult on alles (g)', 'sasipea-broneering' ) . '</label><input type="text" id="sb-c-real" name="actual" inputmode="decimal" required placeholder="' . esc_attr( rtrim( rtrim( number_format( max( 0, $c['stock_g'] ), 2, ',', '' ), '0' ), ',' ) ) . '">';
		echo '<label for="sb-c-note">' . esc_html__( 'Märkus', 'sasipea-broneering' ) . '</label><input type="text" id="sb-c-note" name="note" maxlength="150" placeholder="' . esc_attr__( 'nt kaalutud, tuub läks kõvaks', 'sasipea-broneering' ) . '">';
		echo '<button class="button" type="submit">' . esc_html__( 'Paranda laoseis', 'sasipea-broneering' ) . '</button></form></div>';

		echo '<div class="sb-c-card"><h3>' . esc_html__( 'Andmed', 'sasipea-broneering' ) . '</h3>';
		self::edit_form( $c );
		echo '</div></div>';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.*, cl.first_name, cl.last_name, st.name AS staff_name, u.display_name
				 FROM ' . self::t( 'color_moves' ) . ' m
				 LEFT JOIN ' . self::t( 'clients' ) . ' cl ON cl.id = m.client_id
				 LEFT JOIN ' . self::t( 'staff' ) . " st ON st.id = m.staff_id
				 LEFT JOIN {$wpdb->users} u ON u.ID = m.created_by
				 WHERE m.color_id = %d ORDER BY m.id DESC LIMIT 60",
				$c['id']
			),
			ARRAY_A
		);
		echo '<h2>' . esc_html__( 'Liikumised', 'sasipea-broneering' ) . '</h2><table class="widefat striped sb-c-list"><thead><tr><th>' . esc_html__( 'Aeg', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Mis', 'sasipea-broneering' ) . '</th><th class="sb-c-num">' . esc_html__( 'Kogus', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Selgitus', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Liikumisi veel pole.', 'sasipea-broneering' ) . '</td></tr>';
		}
		$labels = array(
			'in'     => __( 'Lattu', 'sasipea-broneering' ),
			'use'    => __( 'Kulu', 'sasipea-broneering' ),
			'adjust' => __( 'Inventuur', 'sasipea-broneering' ),
		);
		foreach ( $rows ?: array() as $r ) {
			$g = (float) $r['grams'];
			if ( 'use' === $r['kind'] ) {
				$why = trim( $r['first_name'] . ' ' . $r['last_name'] ) . ( $r['staff_name'] ? ' · ' . $r['staff_name'] : '' );
			} else {
				$why = trim( (string) $r['note'] . ( $r['woo_order_id'] ? ' · ' . sprintf( /* translators: %d: shop order number */ __( 'poe tellimus nr %d', 'sasipea-broneering' ), (int) $r['woo_order_id'] ) : '' ), ' ·' );
				$why = trim( $why . ( $r['display_name'] ? ' · ' . $r['display_name'] : '' ), ' ·' );
			}
			echo '<tr><td>' . esc_html( Format::local( $r['created_at'] )->format( 'd.m.Y H:i' ) ) . '</td><td>' . esc_html( $labels[ $r['kind'] ] ?? $r['kind'] ) . '</td><td class="sb-c-num ' . ( $g < 0 ? 'sb-c-minus' : 'sb-c-plus' ) . '">' . esc_html( ( $g > 0 ? '+' : '' ) . self::g( $g ) ) . '</td><td>' . esc_html( $why ) . '</td><td>';
			if ( 'use' !== $r['kind'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-c-inline">';
				wp_nonce_field( 'sb_color_undo' );
				echo '<input type="hidden" name="action" value="sb_color_undo"><input type="hidden" name="move" value="' . (int) $r['id'] . '"><button type="submit" class="sb-c-linkbtn">' . esc_html__( 'võta tagasi', 'sasipea-broneering' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Kulu eemaldatakse päevaplaanis broneeringu juurest. Poe laost võetud tuubi tagasivõtmine tühistab poe tellimuse ja tuub tuleb poe lattu tagasi.', 'sasipea-broneering' ) . '</p>';
	}

	/* ================= Vormide käsitlejad ================= */

	private static function fail( string $msg, array $args = array() ): void {
		Admin::back( 'sb-colors', $args + array( 'sb_saved' => null, 'sb_error' => $msg ) );
	}

	public static function handle_save(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_color_save' );
		$id   = (int) Admin::post( 'id' );
		$pack = self::parse_grams( Admin::post( 'pack_g' ) );
		$data = array(
			'code'   => mb_substr( Admin::post( 'code' ), 0, 40 ),
			'name'   => mb_substr( Admin::post( 'name' ), 0, 190 ),
			'sku'    => mb_substr( Admin::post( 'sku' ), 0, 100 ),
			'pack_g' => $pack ?? 100,
			'active' => Admin::post( 'active' ) ? 1 : 0,
			'bill'   => self::valid_bill( Admin::post( 'bill' ) ),
			'is_ox'  => Admin::post( 'is_ox' ) ? 1 : 0,
			'ratio'  => Admin::post( 'is_ox' ) ? 0 : ( self::parse_ratio( Admin::post( 'ratio' ) ) ?? 1.0 ),
		);
		if ( '' === $data['code'] || '' === $data['name'] || null === $pack ) {
			self::fail( __( 'Sisesta värvikood, nimetus ja tuubi suurus grammides.', 'sasipea-broneering' ), $id ? array( 'varv' => $id ) : array() );
		}
		if ( $id ) {
			$wpdb->update( self::t( 'colors' ), $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t( 'colors' ), $data );
			$id = (int) $wpdb->insert_id;
		}
		Admin::back( 'sb-colors', array( 'varv' => $id ) );
	}

	private static function valid_bill( string $v ): ?string {
		$ids = array_map( 'strval', array_column( (array) Settings::get()['materials'], 'id' ) );
		return self::NO_BILL === $v || in_array( $v, $ids, true ) ? $v : null;
	}

	/**
	 * Arve vaikevalik toote nime järgi: oksüdant arvele ei lähe, pulber läheb pulbri materjalina (kui selline on).
	 * Kõik muu järgib värvilao üldist valikut. Puhas funktsioon; valikut saab värvi juures muuta.
	 *
	 * @param list<array{id:string,name:string}> $materials
	 */
	public static function bill_from_name( string $name, array $materials ): ?string {
		$n = mb_strtolower( $name );
		if ( self::is_ox_name( $n ) ) {
			return self::NO_BILL;
		}
		if ( preg_match( '/pulber|powder/u', $n ) ) {
			foreach ( $materials as $m ) {
				if ( preg_match( '/pulber|powder/u', mb_strtolower( (string) $m['name'] ) ) ) {
					return (string) $m['id'];
				}
			}
		}
		return null;
	}

	/** Lisab värvi poe toote põhjal; kui see toode on juba värvilaos, tagastab olemasoleva. */
	private static function create_from_shop( int $pid, int $vid, string $name, string $sku, string $gtin ): array {
		global $wpdb;
		$old = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'colors' ) . ' WHERE woo_product_id = %d AND woo_variation_id = %d', $pid, $vid ) );
		if ( $old ) {
			return array( $old, false );
		}
		$wpdb->insert(
			self::t( 'colors' ),
			array(
				'code'             => mb_substr( self::is_ox_name( $name ) ? self::ox_code( $name ) : self::code_from_name( $name ), 0, 40 ),
				'name'             => mb_substr( $name, 0, 190 ),
				'sku'              => mb_substr( $sku, 0, 100 ),
				'gtin'             => mb_substr( $gtin, 0, 50 ),
				'woo_product_id'   => $pid,
				'woo_variation_id' => $vid,
				'pack_g'           => self::pack_from_name( $name ),
				'bill'             => self::bill_from_name( $name, (array) Settings::get()['materials'] ),
				'is_ox'            => self::is_ox_name( $name ) ? 1 : 0,
				'ratio'            => self::ratio_from_name( $name ),
				'active'           => 1,
			)
		);
		return array( (int) $wpdb->insert_id, true );
	}

	public static function handle_add_shop(): void {
		Admin::guard( 'sb_manage_bookings', 'sb_color_add_shop' );
		if ( ! preg_match( '/^woo:(\d+):(\d+)$/', Admin::post( 'shop_key' ), $m ) ) {
			self::fail( __( 'Vali toode otsingu tulemustest.', 'sasipea-broneering' ) );
		}
		$it = Integrations\Woo::get( (int) $m[1], (int) $m[2] );
		if ( is_wp_error( $it ) ) {
			self::fail( $it->get_error_message() );
		}
		[ $id ] = self::create_from_shop( (int) $m[1], (int) $m[2], $it['name'], $it['sku'], $it['gtin'] );
		Admin::back( 'sb-colors', array( 'varv' => $id ) );
	}

	public static function handle_import(): void {
		Admin::guard( 'sb_manage_bookings', 'sb_color_import' );
		$hits = self::filter_index( Integrations\Woo::index()['items'], Admin::post( 'q' ) );
		if ( ! $hits ) {
			self::fail( __( 'Poe tootenimekirjast ei leitud selle nimega tooteid. Kontrolli sõna või uuenda seadetes tootenimekirja.', 'sasipea-broneering' ) );
		}
		$n = 0;
		foreach ( array_slice( $hits, 0, 1000 ) as $h ) {
			[ , $new ] = self::create_from_shop( (int) $h['p'], (int) $h['v'], (string) $h['n'], (string) $h['s'], (string) $h['g'] );
			$n        += $new ? 1 : 0;
		}
		Admin::back( 'sb-colors', array( 'sb_saved' => null, 'sb_added' => $n ) );
	}

	public static function handle_intake(): void {
		Admin::guard( 'sb_manage_bookings', 'sb_color_intake' );
		$c     = self::get( (int) Admin::post( 'id' ) );
		$packs = (int) Admin::post( 'packs' );
		if ( ! $c || $packs < 1 || $packs > 200 ) {
			self::fail( __( 'Sisesta tuubide arv.', 'sasipea-broneering' ), $c ? array( 'varv' => $c['id'] ) : array() );
		}
		$order = null;
		$shop  = (bool) Admin::post( 'from_shop' );
		if ( $c['woo_pid'] && Integrations\Woo::enabled() ) {
			// Viimane valik jääb meelde, et linnukest ei peaks iga kord seadma.
			Settings::update( array( 'color_from_shop' => $shop ) );
		}
		if ( $shop && $c['woo_pid'] ) {
			$sold = Integrations\Woo::sell( $c['woo_pid'], $c['woo_vid'], $packs, __( 'Akadeemia sisekasutus (värviladu)', 'sasipea-broneering' ), true );
			if ( is_wp_error( $sold ) ) {
				self::fail( $sold->get_error_message(), array( 'varv' => $c['id'] ) );
			}
			$order = (int) $sold['order_id'];
		}
		self::move(
			array(
				'color_id'     => $c['id'],
				'kind'         => 'in',
				'grams'        => $packs * $c['pack_g'],
				'woo_order_id' => $order,
				/* translators: %d: number of tubes */
				'note'         => sprintf( _n( '%d tuub', '%d tuubi', $packs, 'sasipea-broneering' ), $packs ) . ( $order ? ' ' . __( 'veebipoe laost', 'sasipea-broneering' ) : '' ),
			)
		);
		Admin::back( 'sb-colors', array( 'varv' => $c['id'] ) );
	}

	public static function handle_adjust(): void {
		Admin::guard( 'sb_manage_bookings', 'sb_color_adjust' );
		$c   = self::get( (int) Admin::post( 'id' ) );
		$raw = str_replace( array( ' ', 'g' ), '', str_replace( ',', '.', Admin::post( 'actual' ) ) );
		if ( ! $c || ! preg_match( '/^\d+(\.\d+)?$/', $raw ) || (float) $raw > 100000 ) {
			self::fail( __( 'Sisesta tegelik kogus grammides.', 'sasipea-broneering' ), $c ? array( 'varv' => $c['id'] ) : array() );
		}
		$diff = round( (float) $raw - $c['stock_g'], 2 );
		if ( 0.0 !== $diff ) {
			self::move(
				array(
					'color_id' => $c['id'],
					'kind'     => 'adjust',
					'grams'    => $diff,
					'note'     => mb_substr( Admin::post( 'note' ), 0, 150 ),
				)
			);
		}
		Admin::back( 'sb-colors', array( 'varv' => $c['id'] ) );
	}

	public static function handle_undo(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_color_undo' );
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'color_moves' ) . " WHERE id = %d AND kind IN ('in','adjust')", (int) Admin::post( 'move' ) ), ARRAY_A );
		if ( ! $m ) {
			self::fail( __( 'Liikumist ei leitud.', 'sasipea-broneering' ) );
		}
		if ( $m['woo_order_id'] ) {
			$res = Integrations\Woo::cancel( (int) $m['woo_order_id'] );
			if ( is_wp_error( $res ) ) {
				self::fail( $res->get_error_message(), array( 'varv' => (int) $m['color_id'] ) );
			}
		}
		$wpdb->delete( self::t( 'color_moves' ), array( 'id' => (int) $m['id'] ) );
		Admin::back( 'sb-colors', array( 'varv' => (int) $m['color_id'] ) );
	}

	/** Määrab segusuhte korraga kõigile värvidele, mille nimes on antud sõnad (nt terve liin "Shine"). */
	public static function handle_line_ratio(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_color_line_ratio' );
		$ratio = self::parse_ratio( Admin::post( 'ratio' ) );
		$hits  = self::filter_index( array_map( static fn( $c ) => array( 'n' => $c['name'], 'id' => $c['id'], 'ox' => $c['is_ox'] ), self::all( false ) ), Admin::post( 'q' ) );
		$hits  = array_filter( $hits, static fn( $h ) => ! $h['ox'] );
		if ( null === $ratio || ! $hits ) {
			self::fail( __( 'Selle nimega värve ei leitud. Kirjuta liini nimi nii, nagu see toote nimes on (nt Shine).', 'sasipea-broneering' ) );
		}
		foreach ( $hits as $h ) {
			$wpdb->update( self::t( 'colors' ), array( 'ratio' => $ratio ), array( 'id' => (int) $h['id'] ) );
		}
		Admin::back( 'sb-colors', array( 'sb_saved' => null, 'sb_ratio' => count( $hits ) ) );
	}

	public static function handle_bill(): void {
		Admin::guard( 'sb_manage_settings', 'sb_color_bill' );
		$id = Admin::post( 'material' );
		$ok = '' === $id || in_array( $id, array_map( 'strval', array_column( (array) Settings::get()['materials'], 'id' ) ), true );
		if ( $ok ) {
			Settings::update( array( 'color_material' => $id ) );
		}
		Admin::back( 'sb-colors' );
	}
}
