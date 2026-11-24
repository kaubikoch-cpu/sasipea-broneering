<?php
namespace SB;

defined( 'ABSPATH' ) || exit;

/**
 * Kassa: broneering (või müük ilma broneeringuta) viiakse arvele, lisatakse materjal ja tooted
 * ning makse lõpetatakse valitud makseviisiga. Arve = rida tabelis sb_payments (status open|paid).
 */
final class Kassa {

	public const METHODS = array(
		'cash'     => 'Sularaha',
		'card'     => 'Kaart',
		'transfer' => 'Ülekanne',
	);

	public static function init(): void {
		foreach ( array( 'new', 'add', 'remove', 'pay', 'reopen', 'discard', 'mail' ) as $a ) {
			add_action( 'admin_post_sb_kassa_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	private static function t( string $n ): string {
		return Install::table( $n );
	}

	/* ================= Puhas arvutus (testitav) ================= */

	/** Arve summa: teenuse hind + müügiread. */
	public static function total( int $service_cents, array $sales ): int {
		return max( 0, $service_cents ) + array_sum( array_map( static fn( $s ) => (int) $s['total_cents'], $sales ) );
	}

	/**
	 * Päeva kokkuvõte makseviiside kaupa.
	 *
	 * @param list<array{method:string,total_cents:int|string}> $payments
	 * @return array{methods: array<string,int>, count:int, total:int}
	 */
	public static function summarize( array $payments ): array {
		$out = array( 'methods' => array_fill_keys( array_keys( self::METHODS ), 0 ), 'count' => 0, 'total' => 0 );
		foreach ( $payments as $p ) {
			$m = isset( self::METHODS[ $p['method'] ] ) ? $p['method'] : 'cash';
			$out['methods'][ $m ] += (int) $p['total_cents'];
			$out['total']         += (int) $p['total_cents'];
			++$out['count'];
		}
		return $out;
	}

	/** "12,5" või "12.50" eurodes -> sendid; vigane sisend annab null. */
	public static function parse_money( string $s ): ?int {
		$s = str_replace( array( ' ', '€', ',' ), array( '', '', '.' ), trim( $s ) );
		if ( '' === $s || ! is_numeric( $s ) || (float) $s < 0 || (float) $s > 100000 ) {
			return null;
		}
		return (int) round( (float) $s * 100 );
	}

	/* ================= Andmed ================= */

	public static function ticket( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/** Broneeringu arve; kui seda pole, luuakse avatud arve. */
	public static function ticket_for_booking( int $booking_id ): ?array {
		global $wpdb;
		$b = Repo::booking( $booking_id );
		if ( ! $b || ! in_array( $b['status'], Repo::ACTIVE, true ) ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'payments' ) . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1', $booking_id ) );
		if ( ! $id ) {
			$wpdb->insert(
				self::t( 'payments' ),
				array(
					'booking_id'    => $booking_id,
					'staff_id'      => (int) $b['staff_id'],
					'client_name'   => trim( $b['first_name'] . ' ' . $b['last_name'] ),
					'service_label' => (string) $b['service_name'],
					'service_cents' => (int) $b['price_cents'],
					'status'        => 'open',
					'created_at'    => Repo::utc( time() ),
					'created_by'    => get_current_user_id() ?: null,
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		return self::ticket( $id );
	}

	/** Arve müügiread. Broneeringu juurde päevaplaanis lisatud read võetakse arvele kaasa. */
	public static function sales( array $ticket ): array {
		global $wpdb;
		if ( $ticket['booking_id'] && 'open' === $ticket['status'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'sales' ) . ' SET payment_id = %d WHERE booking_id = %d AND payment_id IS NULL', $ticket['id'], $ticket['booking_id'] ) );
		}
		return array_map( array( Repo::class, 'sale_out' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'sales' ) . ' WHERE payment_id = %d ORDER BY id', $ticket['id'] ), ARRAY_A ) );
	}

	private static function day_param(): string {
		$d = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : ( new \DateTimeImmutable( 'now', Settings::tz() ) )->format( 'Y-m-d' );
	}

	/* ================= Leht ================= */

	public static function page(): void {
		if ( ! current_user_can( 'sb_manage_bookings' ) ) {
			wp_die( esc_html__( 'Sul pole selleks õigust.', 'sasipea-broneering' ), 403 );
		}
		$ticket = null;
		if ( isset( $_GET['booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ticket = self::ticket_for_booking( (int) $_GET['booking'] ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_GET['arve'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ticket = self::ticket( (int) $_GET['arve'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<div class="wrap sb-kassa">';
		self::styles();
		if ( $ticket ) {
			self::page_ticket( $ticket );
		} else {
			self::page_day();
		}
		echo '</div>';
	}

	private static function styles(): void {
		echo '<style>
			.sb-kassa .sb-k-nav{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}
			.sb-kassa td.num,.sb-kassa th.num{text-align:right;white-space:nowrap}
			.sb-kassa .sb-k-sum{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0 20px}
			.sb-kassa .sb-k-box{flex:1 1 150px;max-width:240px;padding:12px 14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
			.sb-kassa .sb-k-box span{display:block;font-size:13px;color:#50575e}
			.sb-kassa .sb-k-box strong{font-size:22px}
			.sb-kassa .sb-k-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:24px;align-items:start;max-width:1200px}
			.sb-kassa .sb-k-card{padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
			.sb-kassa .sb-k-card h2{margin-top:0}
			.sb-kassa .sb-k-total{display:flex;justify-content:space-between;align-items:baseline;margin:14px 0;padding-top:12px;border-top:2px solid #1d2327;font-size:16px}
			.sb-kassa .sb-k-total strong{font-size:30px}
			.sb-kassa .sb-k-methods{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 14px}
			.sb-kassa .sb-k-methods label{flex:1 1 90px;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;font-weight:600;cursor:pointer}
			.sb-kassa .sb-k-methods label:has(input:checked){border-color:#8a2b52;background:#f6e9ef}
			.sb-kassa .sb-k-add{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:10px 0}
			.sb-kassa .sb-k-add label{display:flex;flex-direction:column;gap:3px;font-size:13px}
			.sb-kassa .sb-k-pay{width:100%;min-height:48px;font-size:16px;justify-content:center}
			.sb-kassa .sb-k-meta{color:#50575e;margin:0 0 12px}
			.sb-kassa .sb-k-paid{display:inline-block;padding:2px 10px;border-radius:999px;background:#e3f4ea;color:#0f5c30;font-weight:600;font-size:13px}
			.sb-kassa form.sb-inline{display:inline}
			@media (max-width:900px){.sb-kassa .sb-k-grid{grid-template-columns:1fr}}
			@media print{.sb-kassa .sb-noprint{display:none!important}.sb-kassa .sb-k-grid{display:block}.sb-kassa .sb-k-card{border:0;padding:0}}
		</style>';
	}

	private static function form_open( string $action, string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		wp_nonce_field( 'sb_kassa_' . $action );
		echo '<input type="hidden" name="action" value="sb_kassa_' . esc_attr( $action ) . '">';
	}

	/** Päeva vaade: maksmata broneeringud, avatud arved, makstud arved ja kokkuvõte. */
	private static function page_day(): void {
		global $wpdb;
		$day   = self::day_param();
		$d     = new \DateTimeImmutable( $day, Settings::tz() );
		[ $from, $to ] = Repo::day_bounds( $day );
		$staff = array_column( Repo::staff_list( false ), 'name', 'id' );
		$url   = static fn( string $extra ) => esc_url( Admin::url( 'sb-kassa&' . $extra ) );

		echo '<h1>' . esc_html__( 'Kassa', 'sasipea-broneering' ) . '</h1>';
		Admin::notice();
		echo '<form method="get" class="sb-k-nav sb-noprint"><input type="hidden" name="' . ( Admin::$front ? 'vaade' : 'page' ) . '" value="' . ( Admin::$front ? 'kassa' : 'sb-kassa' ) . '">';
		echo '<a class="button" href="' . $url( 'day=' . $d->modify( '-1 day' )->format( 'Y-m-d' ) ) . '">‹</a> <label class="screen-reader-text" for="sb-k-day">' . esc_html__( 'Kuupäev', 'sasipea-broneering' ) . '</label><input id="sb-k-day" type="date" name="day" value="' . esc_attr( $day ) . '" onchange="this.form.submit()"> <a class="button" href="' . $url( 'day=' . $d->modify( '+1 day' )->format( 'Y-m-d' ) ) . '">›</a> <strong>' . esc_html( Format::date_long( $d ) ) . '</strong></form>';

		$paid = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . " WHERE status = 'paid' AND paid_at >= %s AND paid_at < %s ORDER BY paid_at", Repo::utc( $from ), Repo::utc( $to ) ), ARRAY_A );
		$sum  = self::summarize( $paid );
		echo '<div class="sb-k-sum"><div class="sb-k-box"><span>' . esc_html__( 'Päeva käive', 'sasipea-broneering' ) . '</span><strong>' . esc_html( Settings::money( $sum['total'] ) ) . '</strong><span>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d makse', '%d makset', $sum['count'], 'sasipea-broneering' ), $sum['count'] ) ) . '</span></div>';
		foreach ( self::METHODS as $k => $label ) {
			echo '<div class="sb-k-box"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( Settings::money( $sum['methods'][ $k ] ) ) . '</strong></div>';
		}
		echo '</div>';

		// Maksmata: päeva aktiivsed broneeringud, millel pole makstud arvet.
		$paid_bookings = array_map( 'intval', $wpdb->get_col( 'SELECT booking_id FROM ' . self::t( 'payments' ) . " WHERE status = 'paid' AND booking_id IS NOT NULL" ) );
		$waiting       = array_filter( Repo::bookings_between( $from, $to ), static fn( $b ) => 'no_show' !== $b['status'] && ! in_array( (int) $b['id'], $paid_bookings, true ) );
		echo '<h2>' . esc_html__( 'Ootab maksmist', 'sasipea-broneering' ) . '</h2>';
		if ( ! $waiting ) {
			echo '<p>' . esc_html__( 'Sellel päeval maksmata broneeringuid ei ole.', 'sasipea-broneering' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Aeg', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Klient', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Teenus', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Praktikant', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Teenuse hind', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $waiting as $b ) {
				$s = Format::local( $b['start_utc'] );
				$e = Format::local( $b['end_utc'] );
				printf(
					'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td class="num">%s</td><td class="num"><a class="button button-primary" href="%s">%s</a></td></tr>',
					esc_html( $s->format( 'H:i' ) . '–' . $e->format( 'H:i' ) ),
					esc_html( trim( $b['first_name'] . ' ' . $b['last_name'] ) ),
					esc_html( (string) $b['service_name'] ),
					esc_html( (string) $b['staff_name'] ),
					esc_html( Settings::money( (int) $b['price_cents'] ) ),
					$url( 'booking=' . (int) $b['id'] ), // phpcs:ignore WordPress.Security.EscapeOutput -- $url() teeb esc_url.
					esc_html__( 'Vii kassasse', 'sasipea-broneering' )
				);
			}
			echo '</tbody></table>';
		}

		$open = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'payments' ) . " WHERE status = 'open' AND booking_id IS NULL ORDER BY id", ARRAY_A );
		if ( $open ) {
			echo '<h2>' . esc_html__( 'Pooleli müügid ilma broneeringuta', 'sasipea-broneering' ) . '</h2><table class="widefat striped"><tbody>';
			foreach ( $open as $p ) {
				printf( '<tr><td>%s</td><td>%s</td><td class="num"><a class="button" href="%s">%s</a></td></tr>', esc_html( Format::local( $p['created_at'] )->format( 'd.m H:i' ) ), esc_html( $staff[ (int) $p['staff_id'] ] ?? '' ), $url( 'arve=' . (int) $p['id'] ), esc_html__( 'Jätka', 'sasipea-broneering' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}

		echo '<h2 class="sb-noprint">' . esc_html__( 'Müük ilma broneeringuta', 'sasipea-broneering' ) . '</h2>';
		self::form_open( 'new', 'sb-k-add sb-noprint' );
		echo '<label>' . esc_html__( 'Müüja', 'sasipea-broneering' ) . '<select name="staff_id" required>';
		foreach ( Repo::staff_list( true ) as $s ) {
			printf( '<option value="%d">%s</option>', (int) $s['id'], esc_html( $s['name'] ) );
		}
		echo '</select></label><button class="button" type="submit">' . esc_html__( 'Alusta müüki', 'sasipea-broneering' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Makstud', 'sasipea-broneering' ) . '</h2>';
		if ( ! $paid ) {
			echo '<p>' . esc_html__( 'Sellel päeval makseid veel ei ole.', 'sasipea-broneering' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kell', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Arve', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Klient', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Praktikant', 'sasipea-broneering' ) . '</th><th>' . esc_html__( 'Makseviis', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Summa', 'sasipea-broneering' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $paid as $p ) {
				printf(
					'<tr><td>%s</td><td>#%d</td><td>%s</td><td>%s</td><td>%s</td><td class="num">%s</td><td class="num"><a href="%s">%s</a></td></tr>',
					esc_html( Format::local( $p['paid_at'] )->format( 'H:i' ) ),
					(int) $p['id'],
					esc_html( $p['client_name'] ?: __( 'müük ilma broneeringuta', 'sasipea-broneering' ) ),
					esc_html( $staff[ (int) $p['staff_id'] ] ?? '' ),
					esc_html( self::METHODS[ $p['method'] ] ?? '' ),
					esc_html( Settings::money( (int) $p['total_cents'] ) ),
					$url( 'arve=' . (int) $p['id'] ), // phpcs:ignore WordPress.Security.EscapeOutput
					esc_html__( 'Kviitung', 'sasipea-broneering' )
				);
			}
			echo '</tbody></table>';
		}
	}

	/** Ühe arve vaade: avatud arve koostamine või makstud arve kviitung. */
	private static function page_ticket( array $t ): void {
		$set   = Settings::get();
		$open  = 'open' === $t['status'];
		$sales = self::sales( $t );
		$b     = $t['booking_id'] ? Repo::booking( (int) $t['booking_id'] ) : null;
		$staff = Repo::staff( (int) $t['staff_id'] );
		$total = $open ? self::total( (int) $t['service_cents'], $sales ) : (int) $t['total_cents'];
		$when  = $b ? Format::local( $b['start_utc'] ) : null;
		$back  = Admin::url( 'sb-kassa' . ( $when ? '&day=' . $when->format( 'Y-m-d' ) : '' ) );

		echo '<p class="sb-noprint"><a href="' . esc_url( $back ) . '">‹ ' . esc_html__( 'Tagasi kassasse', 'sasipea-broneering' ) . '</a></p>';
		/* translators: %d: receipt number */
		echo '<h1>' . esc_html( $open ? __( 'Arve', 'sasipea-broneering' ) : sprintf( __( 'Kviitung nr %d', 'sasipea-broneering' ), (int) $t['id'] ) ) . ( $open ? '' : ' <span class="sb-k-paid">' . esc_html__( 'Makstud', 'sasipea-broneering' ) . '</span>' ) . '</h1>';
		Admin::notice();
		if ( ! $open ) {
			echo '<p class="sb-k-meta">' . esc_html( $set['from_name'] . ' · ' . $set['salon_address'] . ' · ' . $set['salon_phone'] ) . '</p>';
		}
		$meta = array();
		if ( '' !== (string) $t['client_name'] ) {
			$meta[] = __( 'Klient', 'sasipea-broneering' ) . ': ' . $t['client_name'];
		}
		$meta[] = ( $b ? __( 'Praktikant', 'sasipea-broneering' ) : __( 'Müüja', 'sasipea-broneering' ) ) . ': ' . ( $staff['name'] ?? '' );
		if ( $when ) {
			$meta[] = __( 'Aeg', 'sasipea-broneering' ) . ': ' . $when->format( 'd.m.Y H:i' );
		}
		if ( ! $open ) {
			$meta[] = __( 'Makstud', 'sasipea-broneering' ) . ': ' . Format::local( $t['paid_at'] )->format( 'd.m.Y H:i' ) . ', ' . mb_strtolower( self::METHODS[ $t['method'] ] ?? '' );
		}
		echo '<p class="sb-k-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';

		echo '<div class="sb-k-grid"><div class="sb-k-card"><h2>' . esc_html__( 'Read', 'sasipea-broneering' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Nimetus', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Kogus', 'sasipea-broneering' ) . '</th><th class="num">' . esc_html__( 'Summa', 'sasipea-broneering' ) . '</th>' . ( $open ? '<th></th>' : '' ) . '</tr></thead><tbody>';
		if ( $t['booking_id'] ) {
			echo '<tr><td><strong>' . esc_html( (string) $t['service_label'] ) . '</strong><br><span class="description">' . esc_html__( 'teenus', 'sasipea-broneering' ) . '</span></td><td class="num">1</td><td class="num">' . esc_html( Settings::money( (int) $t['service_cents'] ) ) . '</td>' . ( $open ? '<td></td>' : '' ) . '</tr>';
		}
		foreach ( $sales as $s ) {
			echo '<tr><td>' . esc_html( $s['label'] ) . '<br><span class="description">' . esc_html( 'material' === $s['kind'] ? __( 'materjal', 'sasipea-broneering' ) : ( $s['woo_order_id'] ? __( 'toode poe laost', 'sasipea-broneering' ) : __( 'toode', 'sasipea-broneering' ) ) ) . '</span></td><td class="num">' . esc_html( $s['qty_label'] ) . '</td><td class="num">' . esc_html( $s['total_label'] ) . '</td>';
			if ( $open ) {
				echo '<td class="num">';
				self::form_open( 'remove', 'sb-inline' );
				echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '"><input type="hidden" name="id" value="' . (int) $s['id'] . '"><button class="button button-small button-link-delete" type="submit" aria-label="' . esc_attr( sprintf( /* translators: %s: line name */ __( 'Eemalda rida %s', 'sasipea-broneering' ), $s['label'] ) ) . '">' . esc_html__( 'Eemalda', 'sasipea-broneering' ) . '</button></form></td>';
			}
			echo '</tr>';
		}
		if ( ! $t['booking_id'] && ! $sales ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Arvel pole veel ühtegi rida. Lisa allpool toode või materjal.', 'sasipea-broneering' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $open ) {
			echo '<h3>' . esc_html__( 'Lisa materjal või toode', 'sasipea-broneering' ) . '</h3>';
			self::form_open( 'add', 'sb-k-add' );
			echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '">';
			echo '<label>' . esc_html__( 'Mida', 'sasipea-broneering' ) . '<select name="item"><option value="">' . esc_html__( '— vali —', 'sasipea-broneering' ) . '</option><optgroup label="' . esc_attr__( 'Materjal (grammides)', 'sasipea-broneering' ) . '">';
			foreach ( (array) $set['materials'] as $mat ) {
				printf( '<option value="material:%s">%s (%s/g)</option>', esc_attr( $mat['id'] ), esc_html( $mat['name'] ), esc_html( Settings::money( (int) $mat['cents_per_g'] ) ) );
			}
			echo '</optgroup><optgroup label="' . esc_attr__( 'Tooted (tükkides)', 'sasipea-broneering' ) . '">';
			foreach ( Repo::products() as $p ) {
				printf( '<option value="product:%d">%s (%s)</option>', (int) $p['id'], esc_html( $p['name'] ), esc_html( $p['price_label'] ) );
			}
			echo '</optgroup></select></label>';
			if ( Integrations\Woo::enabled() ) {
				echo '<label>' . esc_html__( 'või poe triipkood / tootekood', 'sasipea-broneering' ) . '<input name="shop_code" autocomplete="off" style="width:170px"></label>';
			}
			echo '<label>' . esc_html__( 'Kogus (g või tk)', 'sasipea-broneering' ) . '<input name="qty" inputmode="decimal" value="1" style="width:90px" required></label><button class="button" type="submit">' . esc_html__( 'Lisa arvele', 'sasipea-broneering' ) . '</button></form>';
			if ( Integrations\Woo::enabled() ) {
				echo '<p class="description">' . esc_html__( 'Poe laost müües tehakse veebipoodi kohe tellimus ja ladu väheneb. Rea eemaldamine tühistab tellimuse.', 'sasipea-broneering' ) . '</p>';
			}
		}
		echo '</div>';

		echo '<div class="sb-k-card">';
		if ( $open ) {
			echo '<h2>' . esc_html__( 'Makse', 'sasipea-broneering' ) . '</h2>';
			self::form_open( 'pay' );
			echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '">';
			if ( $t['booking_id'] ) {
				echo '<p><label for="sb-k-price"><strong>' . esc_html__( 'Teenuse hind (€)', 'sasipea-broneering' ) . '</strong></label><br><input id="sb-k-price" name="service_price" inputmode="decimal" value="' . esc_attr( number_format( (int) $t['service_cents'] / 100, 2, ',', '' ) ) . '" style="width:120px" data-cents="' . (int) ( $total - (int) $t['service_cents'] ) . '"><span class="description"> ' . esc_html__( 'Muuda, kui teed soodustust või hind erines.', 'sasipea-broneering' ) . '</span></p>';
			}
			echo '<div class="sb-k-total"><span>' . esc_html__( 'Kokku maksta', 'sasipea-broneering' ) . '</span><strong id="sb-k-total">' . esc_html( Settings::money( $total ) ) . '</strong></div>';
			echo '<p><label for="sb-k-email"><strong>' . esc_html__( 'Kviitung e-postile', 'sasipea-broneering' ) . '</strong></label><br><input id="sb-k-email" type="email" name="email" value="' . esc_attr( (string) ( $b['email'] ?? '' ) ) . '" autocomplete="off" style="width:100%"><span class="description">' . esc_html__( 'Kviitung saadetakse sellele aadressile kohe pärast makset. Jäta tühjaks, kui klient kviitungit ei soovi.', 'sasipea-broneering' ) . '</span></p>';
			echo '<fieldset style="border:0;padding:0;margin:0"><legend><strong>' . esc_html__( 'Makseviis', 'sasipea-broneering' ) . '</strong></legend><div class="sb-k-methods">';
			foreach ( self::METHODS as $k => $label ) {
				echo '<label><input type="radio" name="method" value="' . esc_attr( $k ) . '" required' . ( 'card' === $k ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
			}
			echo '</div></fieldset><button class="button button-primary sb-k-pay" type="submit">' . esc_html__( 'Lõpeta makse', 'sasipea-broneering' ) . '</button></form>';
			if ( $t['booking_id'] ) {
				echo '<p class="description">' . esc_html__( 'Makse lõpetamisel märgitakse broneering staatusesse "Kohal" ja teenus läheb tasude arvestusse.', 'sasipea-broneering' ) . '</p>';
			}
			echo "<script>(function(){var p=document.getElementById('sb-k-price'),t=document.getElementById('sb-k-total');if(!p)return;p.addEventListener('input',function(){var v=parseFloat(p.value.replace(',','.'));if(isNaN(v)||v<0)return;var c=Math.round(v*100)+parseInt(p.getAttribute('data-cents'),10);t.textContent=(c%100?(c/100).toFixed(2).replace('.',','):String(c/100))+' €';});})();</script>";
			if ( ! $t['booking_id'] && ! $sales ) {
				self::form_open( 'discard' );
				echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '"><p style="margin-top:14px"><button class="button button-link-delete" type="submit">' . esc_html__( 'Katkesta müük', 'sasipea-broneering' ) . '</button></p></form>';
			}
		} else {
			echo '<div class="sb-k-total" style="border-top:0;padding-top:0;margin-top:0"><span>' . esc_html__( 'Kokku', 'sasipea-broneering' ) . '</span><strong>' . esc_html( Settings::money( $total ) ) . '</strong></div>';
			echo '<p>' . esc_html__( 'Makseviis', 'sasipea-broneering' ) . ': <strong>' . esc_html( self::METHODS[ $t['method'] ] ?? '' ) . '</strong></p>';
			if ( ! empty( $t['emailed_at'] ) ) {
				/* translators: 1: e-mail address, 2: time */
				echo '<p class="sb-noprint">' . esc_html( sprintf( __( 'Kviitung saadetud aadressile %1$s (%2$s).', 'sasipea-broneering' ), (string) $t['email'], Format::local( $t['emailed_at'] )->format( 'd.m.Y H:i' ) ) ) . '</p>';
			} else {
				echo '<p class="sb-noprint">' . esc_html__( 'Kviitungit ei ole e-postiga saadetud.', 'sasipea-broneering' ) . '</p>';
			}
			self::form_open( 'mail', 'sb-noprint' );
			echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '"><p><label for="sb-k-email2"><strong>' . esc_html( ! empty( $t['emailed_at'] ) ? __( 'Saada uuesti', 'sasipea-broneering' ) : __( 'Saada kviitung e-postiga', 'sasipea-broneering' ) ) . '</strong></label><br><input id="sb-k-email2" type="email" name="email" value="' . esc_attr( (string) ( $t['email'] ?: ( $b['email'] ?? '' ) ) ) . '" required style="width:100%"></p><p><button class="button button-primary" type="submit">' . esc_html__( 'Saada kviitung', 'sasipea-broneering' ) . '</button> <button class="button" type="button" onclick="window.print()">' . esc_html__( 'Prindi', 'sasipea-broneering' ) . '</button></p></form>';
			if ( current_user_can( 'sb_manage_settings' ) ) {
				self::form_open( 'reopen', 'sb-noprint' );
				echo '<input type="hidden" name="arve" value="' . (int) $t['id'] . '"><button class="button button-link-delete" type="submit">' . esc_html__( 'Tühista makse ja ava arve uuesti', 'sasipea-broneering' ) . '</button></form>';
			}
		}
		echo '</div></div>';
	}

	/* ================= Salvestamine ================= */

	private static function open_ticket_from_post(): array {
		$t = self::ticket( (int) Admin::post( 'arve' ) );
		if ( ! $t || 'open' !== $t['status'] ) {
			Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'sb_error' => __( 'Arvet ei leitud või see on juba makstud.', 'sasipea-broneering' ) ) );
		}
		return $t;
	}

	public static function handle_new(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_new' );
		$staff = Repo::staff( (int) Admin::post( 'staff_id' ) );
		if ( ! $staff ) {
			Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'sb_error' => __( 'Vali müüja.', 'sasipea-broneering' ) ) );
		}
		$wpdb->insert(
			self::t( 'payments' ),
			array(
				'staff_id'      => (int) $staff['id'],
				'client_name'   => '',
				'service_label' => '',
				'service_cents' => 0,
				'status'        => 'open',
				'created_at'    => Repo::utc( time() ),
				'created_by'    => get_current_user_id() ?: null,
			)
		);
		Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'arve' => (int) $wpdb->insert_id ) );
	}

	public static function handle_add(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_add' );
		$t    = self::open_ticket_from_post();
		$item = Admin::post( 'item' );
		$code = Admin::post( 'shop_code' );
		$res  = null;
		if ( '' !== $code ) {
			// Veebipoe toode triipkoodi või tootekoodi järgi: peab leiduma täpselt üks vaste.
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
		$res = $res ?? Repo::add_sale( (int) $t['staff_id'], $t['booking_id'] ? (int) $t['booking_id'] : null, $item, (float) str_replace( ',', '.', Admin::post( 'qty' ) ), time() );
		if ( is_wp_error( $res ) ) {
			Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'arve' => (int) $t['id'], 'sb_error' => $res->get_error_message() ) );
		}
		$wpdb->update( self::t( 'sales' ), array( 'payment_id' => (int) $t['id'] ), array( 'id' => (int) $res ) );
		Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'arve' => (int) $t['id'] ) );
	}

	public static function handle_remove(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_remove' );
		$t  = self::open_ticket_from_post();
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'sales' ) . ' WHERE id = %d AND payment_id = %d', (int) Admin::post( 'id' ), (int) $t['id'] ) );
		$res = $id ? Repo::delete_sale( $id ) : true;
		$args = array( 'sb_saved' => null, 'arve' => (int) $t['id'] );
		if ( is_wp_error( $res ) ) {
			$args['sb_error'] = $res->get_error_message();
		}
		Admin::back( 'sb-kassa', $args );
	}

	public static function handle_pay(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_pay' );
		$t      = self::open_ticket_from_post();
		$method = Admin::post( 'method' );
		$err    = null;
		if ( ! isset( self::METHODS[ $method ] ) ) {
			$err = __( 'Vali makseviis.', 'sasipea-broneering' );
		}
		$service = (int) $t['service_cents'];
		if ( $t['booking_id'] ) {
			$parsed = self::parse_money( Admin::post( 'service_price' ) );
			if ( null === $parsed ) {
				$err = __( 'Teenuse hind ei ole korrektne summa.', 'sasipea-broneering' );
			} else {
				$service = $parsed;
			}
		}
		$sales = self::sales( $t );
		if ( ! $t['booking_id'] && ! $sales ) {
			$err = __( 'Arvel pole ühtegi rida.', 'sasipea-broneering' );
		}
		if ( $err ) {
			Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'arve' => (int) $t['id'], 'sb_error' => $err ) );
		}
		$now = Repo::utc( time() );
		$wpdb->update(
			self::t( 'payments' ),
			array(
				'service_cents' => $service,
				'total_cents'   => self::total( $service, $sales ),
				'method'        => $method,
				'status'        => 'paid',
				'paid_at'       => $now,
				'paid_by'       => get_current_user_id() ?: null,
			),
			array( 'id' => (int) $t['id'] )
		);
		if ( $t['booking_id'] ) {
			$b = Repo::booking( (int) $t['booking_id'] );
			// Tasu arvestus loeb teenust broneeringu hinna ja staatuse "Kohal" järgi.
			$wpdb->update( self::t( 'bookings' ), array( 'price_cents' => $service, 'updated_at' => $now ), array( 'id' => (int) $t['booking_id'] ) );
			if ( $b && 'arrived' !== $b['status'] ) {
				BookingService::update( (int) $t['booking_id'], array( 'status' => 'arrived' ) );
			}
			Repo::log( (int) $t['booking_id'], 'paid', array( 'payment' => (int) $t['id'], 'method' => $method, 'total' => self::total( $service, $sales ) ) );
		}
		$args  = array( 'arve' => (int) $t['id'] );
		$email = sanitize_email( Admin::post( 'email' ) );
		if ( '' !== $email && ! self::send_receipt( (int) $t['id'], $email ) ) {
			$args['sb_error'] = __( 'Makse on salvestatud, aga kviitungi saatmine e-postiga ebaõnnestus. Proovi kviitungi lehelt uuesti.', 'sasipea-broneering' );
		}
		Admin::back( 'sb-kassa', $args );
	}

	public static function handle_mail(): void {
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_mail' );
		$id    = (int) Admin::post( 'arve' );
		$t     = self::ticket( $id );
		$email = sanitize_email( Admin::post( 'email' ) );
		$args  = array( 'arve' => $id, 'sb_saved' => null );
		if ( ! $t || 'paid' !== $t['status'] ) {
			$args['sb_error'] = __( 'Kviitungit saab saata ainult makstud arve kohta.', 'sasipea-broneering' );
		} elseif ( ! is_email( $email ) ) {
			$args['sb_error'] = __( 'Sisesta korrektne e-posti aadress.', 'sasipea-broneering' );
		} elseif ( ! self::send_receipt( $id, $email ) ) {
			$args['sb_error'] = __( 'Kviitungi saatmine ebaõnnestus. Proovi uuesti.', 'sasipea-broneering' );
		} else {
			$args['sb_sent'] = 1;
		}
		Admin::back( 'sb-kassa', $args );
	}

	/**
	 * Kviitungi read e-kirja jaoks. Puhas funktsioon (testitav).
	 *
	 * @param list<array{label:string,qty:string,total:string}> $lines
	 */
	public static function receipt_html( array $head, array $lines, string $total ): string {
		$e    = static fn( string $v ): string => htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		$td   = 'padding:8px 10px;border-bottom:1px solid #eee;';
		$html = '<p style="margin:0 0 12px">' . implode( '<br>', array_map( $e, $head ) ) . '</p>';
		$html .= '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;max-width:520px">';
		foreach ( $lines as $l ) {
			$html .= '<tr><td style="' . $td . '">' . $e( $l['label'] ) . '</td><td style="' . $td . 'text-align:right;white-space:nowrap">' . $e( $l['qty'] ) . '</td><td style="' . $td . 'text-align:right;white-space:nowrap">' . $e( $l['total'] ) . '</td></tr>';
		}
		$html .= '<tr><td colspan="2" style="padding:10px;font-weight:bold">Kokku</td><td style="padding:10px;text-align:right;font-weight:bold;white-space:nowrap">' . $e( $total ) . '</td></tr></table>';
		return $html;
	}

	/** Saadab makstud arve kviitungi e-postiga ja jätab meelde, kuhu ja millal. */
	public static function send_receipt( int $id, string $to ): bool {
		global $wpdb;
		$t = self::ticket( $id );
		if ( ! $t || 'paid' !== $t['status'] || ! is_email( $to ) ) {
			return false;
		}
		$set   = Settings::get();
		$staff = Repo::staff( (int) $t['staff_id'] );
		$lines = array();
		if ( $t['booking_id'] ) {
			$lines[] = array( 'label' => (string) $t['service_label'], 'qty' => '1', 'total' => Settings::money( (int) $t['service_cents'] ) );
		}
		foreach ( self::sales( $t ) as $s ) {
			$lines[] = array( 'label' => $s['label'], 'qty' => $s['qty_label'], 'total' => $s['total_label'] );
		}
		/* translators: %d: receipt number */
		$title = sprintf( __( 'Kviitung nr %d', 'sasipea-broneering' ), $id );
		$head  = array_filter(
			array(
				'' !== (string) $t['client_name'] ? __( 'Klient', 'sasipea-broneering' ) . ': ' . $t['client_name'] : '',
				( $t['booking_id'] ? __( 'Teenindas', 'sasipea-broneering' ) : __( 'Müüja', 'sasipea-broneering' ) ) . ': ' . ( $staff['name'] ?? '' ),
				__( 'Makstud', 'sasipea-broneering' ) . ': ' . Format::local( $t['paid_at'] )->format( 'd.m.Y H:i' ) . ', ' . mb_strtolower( self::METHODS[ $t['method'] ] ?? '' ),
			)
		);
		$web  = esc_html( $set['salon_web'] );
		$body = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#222"><h2 style="margin:0 0 4px;font-size:20px">' . esc_html( $title ) . '</h2>'
			. '<p style="margin:0 0 16px;color:#555">' . esc_html( $set['from_name'] ) . '</p>'
			. self::receipt_html( array_values( $head ), $lines, Settings::money( (int) $t['total_cents'] ) )
			. '<p style="margin:18px 0 0">' . esc_html__( 'Aitäh, et käisid! Ootame sind tagasi.', 'sasipea-broneering' ) . '</p>'
			. '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px"><p style="color:#555;font-size:13px;margin:0">' . esc_html( $set['from_name'] . ' · ' . $set['salon_address'] . ' · tel ' . $set['salon_phone'] ) . ' · <a href="https://' . $web . '">' . $web . '</a></p></div>';
		$ok = wp_mail(
			$to,
			$title . ' – ' . $set['from_name'],
			$body,
			array(
				'Content-Type: text/html; charset=UTF-8',
				sprintf( 'From: %s <%s>', $set['from_name'], $set['from_email'] ),
			)
		);
		if ( $ok ) {
			$wpdb->update( self::t( 'payments' ), array( 'email' => $to, 'emailed_at' => Repo::utc( time() ) ), array( 'id' => $id ) );
		}
		return (bool) $ok;
	}

	/** Omanik saab makse tühistada (nt vale makseviis); arve avaneb uuesti muutmiseks. */
	public static function handle_reopen(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_settings', 'sb_kassa_reopen' );
		$id = (int) Admin::post( 'arve' );
		$wpdb->update( self::t( 'payments' ), array( 'status' => 'open', 'method' => null, 'paid_at' => null, 'paid_by' => null, 'total_cents' => 0, 'emailed_at' => null ), array( 'id' => $id, 'status' => 'paid' ) );
		Admin::back( 'sb-kassa', array( 'sb_saved' => null, 'arve' => $id ) );
	}

	public static function handle_discard(): void {
		global $wpdb;
		Admin::guard( 'sb_manage_bookings', 'sb_kassa_discard' );
		$t = self::open_ticket_from_post();
		if ( ! $t['booking_id'] && ! self::sales( $t ) ) {
			$wpdb->delete( self::t( 'payments' ), array( 'id' => (int) $t['id'] ) );
		}
		Admin::back( 'sb-kassa', array( 'sb_saved' => null ) );
	}
}
