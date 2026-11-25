<?php
use PHPUnit\Framework\TestCase;
use SB\Kassa;

final class KassaTest extends TestCase {

	public function test_arve_summa_on_teenus_pluss_read(): void {
		$this->assertSame( 2000 + 900 + 1800, Kassa::total( 2000, array( array( 'total_cents' => 900 ), array( 'total_cents' => '1800' ) ) ) );
		$this->assertSame( 1800, Kassa::total( 0, array( array( 'total_cents' => 1800 ) ) ) );
		$this->assertSame( 0, Kassa::total( -5, array() ) );
	}

	public function test_paeva_kokkuvote_makseviiside_kaupa(): void {
		$s = Kassa::summarize(
			array(
				array( 'method' => 'cash', 'total_cents' => 2000 ),
				array( 'method' => 'card', 'total_cents' => '4750' ),
				array( 'method' => 'card', 'total_cents' => 1250 ),
				array( 'method' => 'transfer', 'total_cents' => 3000 ),
			)
		);
		$this->assertSame( array( 'cash' => 2000, 'card' => 6000, 'transfer' => 3000 ), $s['methods'] );
		$this->assertSame( 4, $s['count'] );
		$this->assertSame( 11000, $s['total'] );
	}

	public function test_kviitungi_kiri_sisaldab_ridu_ja_kogusummat(): void {
		$html = Kassa::receipt_html( array( 'Klient: Mari <Tamm>' ), array( array( 'label' => 'Juukselõikus', 'qty' => '1', 'total' => '20 €' ), array( 'label' => 'Värv', 'qty' => '45 g', 'total' => '13,50 €' ) ), '33,50 €' );
		$this->assertSame( true, str_contains( $html, 'Juukselõikus' ) );
		$this->assertSame( true, str_contains( $html, '45 g' ) );
		$this->assertSame( true, str_contains( $html, '33,50 €' ) );
		$this->assertSame( true, str_contains( $html, 'Mari &lt;Tamm&gt;' ) );
		$this->assertSame( false, str_contains( $html, '<Tamm>' ) );
	}

	public function test_summa_sisestus_eurodes(): void {
		$this->assertSame( 1250, Kassa::parse_money( '12,5' ) );
		$this->assertSame( 1250, Kassa::parse_money( '12.50 €' ) );
		$this->assertSame( 0, Kassa::parse_money( '0' ) );
		$this->assertSame( 1999, Kassa::parse_money( '19,99' ) );
		$this->assertSame( null, Kassa::parse_money( '' ) );
		$this->assertSame( null, Kassa::parse_money( 'abc' ) );
		$this->assertSame( null, Kassa::parse_money( '-3' ) );
	}

	public function test_poe_otsingust_valitakse_ainult_uhene_toode(): void {
		$a = array( 'key' => 'woo:1:0', 'exact' => true );
		$b = array( 'key' => 'woo:2:0', 'exact' => false );
		$c = array( 'key' => 'woo:3:0', 'exact' => false );
		$this->assertSame( 'woo:1:0', Kassa::pick_hit( array( $b, $a, $c ) ) );
		$this->assertSame( 'woo:2:0', Kassa::pick_hit( array( $b ) ) );
		$this->assertSame( null, Kassa::pick_hit( array( $b, $c ) ) );
		$this->assertSame( null, Kassa::pick_hit( array() ) );
		$this->assertSame( null, Kassa::pick_hit( array( $a, array( 'key' => 'woo:9:0', 'exact' => true ) ) ) );
	}

	public function test_jagatud_makse_osad_peavad_andma_kogusumma(): void {
		$r = Kassa::settle( 5000, array( 'cash' => '10', 'card' => '10', 'transfer' => '30' ) );
		$this->assertSame( array( 'cash' => 1000, 'card' => 1000, 'transfer' => 3000 ), $r['parts'] );
		$this->assertSame( 'mixed', $r['method'] );
		$this->assertSame( true, is_string( Kassa::settle( 5000, array( 'cash' => '10', 'card' => '10' ) ) ) );
		$this->assertSame( true, is_string( Kassa::settle( 5000, array( 'card' => '60' ) ) ) );
		$this->assertSame( true, is_string( Kassa::settle( 5000, array( 'card' => 'abc' ) ) ) );
		$one = Kassa::settle( 5000, array( 'card' => '50,00' ) );
		$this->assertSame( 'card', $one['method'] );
		$this->assertSame( 'cash', Kassa::settle( 0, array() )['method'] );
	}

	public function test_sularaha_tagasiandmine(): void {
		// Arve 56 €, klient annab 100 €: tagasi 44 €, sularahana läheb kirja 56 €.
		$r = Kassa::settle( 5600, array( 'cash' => '100' ) );
		$this->assertSame( 'cash', $r['method'] );
		$this->assertSame( 5600, $r['parts']['cash'] );
		$this->assertSame( 10000, $r['given'] );
		$this->assertSame( 4400, $r['change'] );
		$exact = Kassa::settle( 5600, array( 'cash' => '56' ) );
		$this->assertSame( 0, $exact['change'] );
		$this->assertSame( null, $exact['given'] );
		// Jagatud makse: kaart 40, sularaha 20 antud, arve 50: tagasi 10, sularaha kirjas 10.
		$m = Kassa::settle( 5000, array( 'cash' => '20', 'card' => '40' ) );
		$this->assertSame( 1000, $m['change'] );
		$this->assertSame( array( 'cash' => 1000, 'card' => 4000, 'transfer' => 0 ), $m['parts'] );
		// Kaardiga üle makstud summat ei saa sularahas tagasi anda.
		$this->assertSame( true, is_string( Kassa::settle( 5000, array( 'card' => '60', 'cash' => '5' ) ) ) );
		// Kogu arve kaeti kaardiga, sularaha anti asjata: kõik sularaha tagasi, kirja läheb ainult kaart.
		$c = Kassa::settle( 5000, array( 'card' => '50', 'cash' => '5' ) );
		$this->assertSame( 'card', $c['method'] );
		$this->assertSame( 500, $c['change'] );
	}

	public function test_paeva_kokkuvote_jagab_jagatud_makse_makseviisidesse(): void {
		$s = Kassa::summarize(
			array(
				array( 'method' => 'mixed', 'total_cents' => 5000, 'cash_cents' => 1000, 'card_cents' => 1000, 'transfer_cents' => 3000 ),
				array( 'method' => 'card', 'total_cents' => 2000, 'cash_cents' => 0, 'card_cents' => 0, 'transfer_cents' => 0 ),
			)
		);
		$this->assertSame( array( 'cash' => 1000, 'card' => 3000, 'transfer' => 3000 ), $s['methods'] );
		$this->assertSame( 7000, $s['total'] );
		$this->assertSame( 2, $s['count'] );
		$this->assertSame( 'kaart', Kassa::method_label( array( 'method' => 'card', 'total_cents' => 2000 ) ) );
		$this->assertSame( 'sularaha 10 €, ülekanne 30 €', Kassa::method_label( array( 'method' => 'mixed', 'total_cents' => 4000, 'cash_cents' => 1000, 'transfer_cents' => 3000 ) ) );
	}

	public function test_arve_varem_luhendab_broneeringut(): void {
		$start = strtotime( '2026-11-25 12:00:00 UTC' );
		$end   = strtotime( '2026-11-25 14:00:00 UTC' );
		$this->assertSame( strtotime( '2026-11-25 13:15:00 UTC' ), Kassa::end_after_payment( $start, $end, strtotime( '2026-11-25 13:12:40 UTC' ) ) );
	}

	public function test_arve_hiljem_pikendab_broneeringut(): void {
		$start = strtotime( '2026-11-25 12:00:00 UTC' );
		$end   = strtotime( '2026-11-25 14:00:00 UTC' );
		$this->assertSame( strtotime( '2026-11-25 14:40:00 UTC' ), Kassa::end_after_payment( $start, $end, strtotime( '2026-11-25 14:40:00 UTC' ) ) );
	}

	public function test_arve_enne_algust_voi_palju_hiljem_ei_muuda_aega(): void {
		$start = strtotime( '2026-11-25 12:00:00 UTC' );
		$end   = strtotime( '2026-11-25 14:00:00 UTC' );
		$this->assertSame( $end, Kassa::end_after_payment( $start, $end, strtotime( '2026-11-25 11:30:00 UTC' ) ) );
		$this->assertSame( $end, Kassa::end_after_payment( $start, $end, strtotime( '2026-11-26 10:00:00 UTC' ) ) );
		// Rohkem kui 1 tund pärast lõppu: arvet ei jõutud varem teha, aeg jääb samaks. Täpselt 1 tund veel pikendab.
		$this->assertSame( $end, Kassa::end_after_payment( $start, $end, strtotime( '2026-11-25 15:05:00 UTC' ) ) );
		$this->assertSame( strtotime( '2026-11-25 15:00:00 UTC' ), Kassa::end_after_payment( $start, $end, strtotime( '2026-11-25 15:00:00 UTC' ) ) );
		// Kohe alguses tehtud arve jätab vähemalt 15 minutit.
		$this->assertSame( $start + 900, Kassa::end_after_payment( $start, $end, $start + 60 ) );
	}
}
