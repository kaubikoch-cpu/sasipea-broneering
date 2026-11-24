<?php
use PHPUnit\Framework\TestCase;
use SB\Vat;

final class VatTest extends TestCase {

	private function history(): array {
		return array(
			array( 'from' => '2027-07-01', 'rate' => 24 ),
			array( 'from' => '2027-03-01', 'rate' => 22 ),
		);
	}

	public function test_enne_kohustuse_algust_on_maar_null(): void {
		$this->assertSame( 0.0, Vat::rate_on( $this->history(), '2027-02-28' ) );
		$this->assertSame( 0.0, Vat::rate_on( array(), '2027-02-28' ) );
	}

	public function test_maar_kehtib_alates_kuupaevast(): void {
		$this->assertSame( 22.0, Vat::rate_on( $this->history(), '2027-03-01' ) );
		$this->assertSame( 22.0, Vat::rate_on( $this->history(), '2027-06-30' ) );
		$this->assertSame( 24.0, Vat::rate_on( $this->history(), '2027-07-01' ) );
	}

	public function test_summa_jagamine(): void {
		$this->assertSame( array( 'gross' => 12400, 'net' => 10000, 'vat' => 2400 ), Vat::split( 12400, 24 ) );
		$this->assertSame( array( 'gross' => 2000, 'net' => 1613, 'vat' => 387 ), Vat::split( 2000, 24 ) );
		$this->assertSame( array( 'gross' => 2000, 'net' => 2000, 'vat' => 0 ), Vat::split( 2000, 0 ) );
	}

	public function test_kuu_aruanne_liigi_kaupa(): void {
		$r = Vat::report(
			array( array( 'from' => '2027-01-01', 'rate' => 24 ) ),
			array(
				array( 'kind' => 'product', 'date' => '2027-01-05', 'cents' => 1800 ),
				array( 'kind' => 'service', 'date' => '2027-01-05', 'cents' => 2000 ),
				array( 'kind' => 'service', 'date' => '2027-01-06', 'cents' => 4500 ),
				array( 'kind' => 'material', 'date' => '2027-01-06', 'cents' => 800 ),
			)
		);
		$this->assertCount( 3, $r['lines'] );
		$this->assertSame( 'service', $r['lines'][0]['kind'] );
		$this->assertSame( 2, $r['lines'][0]['count'] );
		$this->assertSame( 6500, $r['lines'][0]['gross'] );
		$this->assertSame( 1258, $r['lines'][0]['vat'] );
		$this->assertSame( 5242, $r['lines'][0]['net'] );
		$this->assertSame( 9100, $r['total']['gross'] );
		$this->assertSame( $r['total']['gross'], $r['total']['net'] + $r['total']['vat'] );
	}

	public function test_maara_muutus_kuu_sees_annab_eraldi_read(): void {
		$r = Vat::report(
			$this->history(),
			array(
				array( 'kind' => 'service', 'date' => '2027-06-30', 'cents' => 12200 ),
				array( 'kind' => 'service', 'date' => '2027-07-01', 'cents' => 12400 ),
			)
		);
		$this->assertCount( 2, $r['lines'] );
		$this->assertSame( 2200, $r['lines'][0]['vat'] );
		$this->assertSame( 2400, $r['lines'][1]['vat'] );
	}
}
