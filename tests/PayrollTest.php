<?php
use PHPUnit\Framework\TestCase;
use SB\Payroll;

final class PayrollTest extends TestCase {

	public function test_maaramata_tasu_on_null(): void {
		$r = Payroll::normalize( null );
		$this->assertSame( 'pct', $r['service']['type'] );
		$s = Payroll::summarize( $r, array( 2000, 1800 ), array( array( 'kind' => 'product', 'total_cents' => 1500, 'qty' => 1 ) ) );
		$this->assertSame( 0, $s['total_pay'] );
		$this->assertSame( 5300, $s['total_turnover'] );
	}

	public function test_protsent_teenuselt(): void {
		$r = Payroll::normalize( array( 'service' => array( 'type' => 'pct', 'value' => 30 ) ) );
		$s = Payroll::summarize( $r, array( 2000, 1800 ), array() );
		$this->assertSame( 2, $s['service']['count'] );
		$this->assertSame( 3800, $s['service']['turnover'] );
		$this->assertSame( 1140, $s['service']['pay'] );
	}

	public function test_kindel_summa_teenuse_ja_toote_tyki_eest(): void {
		$r = Payroll::normalize( '{"service":{"type":"fixed","value":500},"product":{"type":"fixed","value":100}}' );
		$s = Payroll::summarize(
			$r,
			array( 2000, 6000 ),
			array( array( 'kind' => 'product', 'total_cents' => 4500, 'qty' => 3 ) )
		);
		$this->assertSame( 1000, $s['service']['pay'] );
		$this->assertSame( 300, $s['product']['pay'] );
		$this->assertSame( 1300, $s['total_pay'] );
	}

	public function test_materjal_ja_tooted_eraldi_maaraga(): void {
		$r = Payroll::normalize(
			array(
				'service'  => array( 'type' => 'pct', 'value' => 25 ),
				'material' => array( 'type' => 'pct', 'value' => 10 ),
				'product'  => array( 'type' => 'pct', 'value' => 15 ),
			)
		);
		$s = Payroll::summarize(
			$r,
			array( 4500 ),
			array(
				array( 'kind' => 'material', 'total_cents' => 800, 'qty' => 40 ),  // 40 g pulbrit
				array( 'kind' => 'material', 'total_cents' => 900, 'qty' => 30 ),  // 30 g värvi
				array( 'kind' => 'product', 'total_cents' => 2000, 'qty' => 1 ),
			)
		);
		$this->assertSame( 1125, $s['service']['pay'] );
		$this->assertSame( 1700, $s['material']['turnover'] );
		$this->assertSame( 170, $s['material']['pay'] );
		$this->assertSame( 300, $s['product']['pay'] );
		$this->assertSame( 1595, $s['total_pay'] );
		$this->assertSame( 8200, $s['total_turnover'] );
	}

	public function test_vigane_maar_piiratakse(): void {
		$r = Payroll::normalize( array( 'service' => array( 'type' => 'pct', 'value' => 250 ), 'product' => array( 'type' => 'midagi', 'value' => -5 ) ) );
		$this->assertSame( 100.0, $r['service']['value'] );
		$this->assertSame( 'pct', $r['product']['type'] );
		$this->assertSame( 0.0, $r['product']['value'] );
	}
}
