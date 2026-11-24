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
}
