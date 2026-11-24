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
		$r = Kassa::settle( 5600, array( 'cash' => '56' ), '100' );
		$this->assertSame( 'cash', $r['method'] );
		$this->assertSame( 10000, $r['given'] );
		$this->assertSame( 4400, $r['change'] );
		$this->assertSame( 0, Kassa::settle( 5600, array( 'cash' => '56' ) )['change'] );
		$this->assertSame( true, is_string( Kassa::settle( 5600, array( 'cash' => '56' ), '50' ) ) );
		// Jagatud makse: tagasi antakse ainult sularaha osast.
		$m = Kassa::settle( 5000, array( 'cash' => '10', 'card' => '40' ), '20' );
		$this->assertSame( 1000, $m['change'] );
		// Sularaha ei ole: antud summa ei loe.
		$this->assertSame( null, Kassa::settle( 5000, array( 'card' => '50' ), '100' )['given'] );
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
}
