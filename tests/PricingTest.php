<?php
use PHPUnit\Framework\TestCase;
use SB\Pricing;

final class PricingTest extends TestCase {

	private function levels(): array {
		return array(
			array( 'id' => 'noorem', 'name' => 'Noorempraktikant', 'discount_pct' => 10, 'extra_min' => 30 ),
			array( 'id' => 'vanem', 'name' => 'Vanempraktikant', 'discount_pct' => 0, 'extra_min' => 0 ),
		);
	}

	public function test_tase_annab_soodustuse_ja_lisaaja(): void {
		$t = Pricing::terms( array( 'level' => 'noorem' ), $this->levels() );
		$this->assertSame( 'Noorempraktikant', $t['level_name'] );
		$this->assertSame( 10, $t['discount_pct'] );
		$this->assertSame( 30, $t['extra_min'] );
	}

	public function test_tasemeta_praktikant_on_taishinnaga(): void {
		$t = Pricing::terms( array( 'level' => '' ), $this->levels() );
		$this->assertSame( 0, $t['discount_pct'] );
		$this->assertSame( 0, $t['extra_min'] );
		$this->assertSame( '', $t['level_name'] );
	}

	public function test_kustutatud_tase_ei_anna_soodustust(): void {
		$t = Pricing::terms( array( 'level' => 'vana-tase' ), $this->levels() );
		$this->assertSame( 0, $t['discount_pct'] );
	}

	public function test_praktikandi_erisus_asendab_taseme(): void {
		$t = Pricing::terms( array( 'level' => 'noorem', 'discount_override' => 20, 'extra_override' => 15 ), $this->levels() );
		$this->assertSame( 20, $t['discount_pct'] );
		$this->assertSame( 15, $t['extra_min'] );
		// Erisus 0 on samuti erisus: tase annaks 10%, aga see praktikant soodustust ei anna.
		$t = Pricing::terms( array( 'level' => 'noorem', 'discount_override' => 0 ), $this->levels() );
		$this->assertSame( 0, $t['discount_pct'] );
		$this->assertSame( 30, $t['extra_min'] );
	}

	public function test_hind_parast_soodustust(): void {
		$this->assertSame( 1800, Pricing::price( 2000, 10 ) );
		$this->assertSame( 2250, Pricing::price( 2500, 10 ) );
		$this->assertSame( 2000, Pricing::price( 2000, 0 ) );
		$this->assertSame( 0, Pricing::price( 2000, 100 ) );
		$this->assertSame( 1659, Pricing::price( 1999, 17 ) ); // 1999 * 0,83 = 1659,17
	}

	public function test_kestus_lisaajaga(): void {
		$this->assertSame( 120, Pricing::duration( 90, 30 ) );
		$this->assertSame( 90, Pricing::duration( 90, 0 ) );
		$this->assertSame( 90, Pricing::duration( 90, -15 ) );
	}
}
