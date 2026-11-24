<?php
use PHPUnit\Framework\TestCase;
use SB\Integrations\Woo;

final class ShopSearchTest extends TestCase {

	private function items(): array {
		return array(
			array( 'p' => 101, 'v' => 0, 'n' => 'Y.S. Park karbonkamm 112 must', 's' => '12600331', 'g' => '4981104350122' ),
			array( 'p' => 82589, 'v' => 0, 'n' => 'Olivia Garden Fingerbrush roosa', 's' => '5414343008534', 'g' => '' ),
			array( 'p' => 103, 'v' => 1031, 'n' => 'Ayala šampoon – 250 ml', 's' => 'AY-CUS-001', 'g' => '4740000000024' ),
			array( 'p' => 103, 'v' => 1032, 'n' => 'Ayala šampoon – 1000 ml', 's' => 'AY-CUS-002', 'g' => '4740000000031' ),
			array( 'p' => 105, 'v' => 0, 'n' => 'Habemenuga', 's' => 'LS-RAZOR', 'g' => '' ),
		);
	}

	private function keys( string $q ): array {
		return array_map( static fn( $h ) => $h['p'] . ':' . $h['v'], Woo::match( $this->items(), $q ) );
	}

	public function test_triipkood_gtin_valjalt(): void {
		$hits = Woo::match( $this->items(), '4740000000031' );
		$this->assertCount( 1, $hits );
		$this->assertSame( 1032, $hits[0]['v'] );
		$this->assertSame( true, $hits[0]['exact'] );
	}

	public function test_triipkood_mis_on_sku_valjal(): void {
		$hits = Woo::match( $this->items(), '5414343008534' );
		$this->assertCount( 1, $hits );
		$this->assertSame( 82589, $hits[0]['p'] );
		$this->assertSame( true, $hits[0]['exact'] );
	}

	public function test_tootekood_tapselt_ja_tostutundetult(): void {
		$this->assertSame( array( '105:0' ), $this->keys( 'ls-razor' ) );
		$this->assertSame( array( '101:0' ), $this->keys( '12600331' ) );
	}

	public function test_skanner_lisab_tyhikuid_voi_kriipse(): void {
		$this->assertSame( array( '103:1031' ), $this->keys( ' 4740-0000 00024 ' ) );
	}

	public function test_nimeotsing_mitme_sonaga(): void {
		$this->assertSame( array( '103:1031', '103:1032' ), $this->keys( 'ayala šampoon' ) );
		$this->assertSame( array( '103:1032' ), $this->keys( 'šampoon 1000' ) );
		$hits = Woo::match( $this->items(), 'kamm' );
		$this->assertSame( false, $hits[0]['exact'] );
	}

	public function test_koodi_algus_ja_tundmatu(): void {
		$this->assertSame( array( '103:1031', '103:1032' ), $this->keys( 'AY-CUS' ) );
		$this->assertSame( array(), $this->keys( '9999999999999' ) );
	}
}
