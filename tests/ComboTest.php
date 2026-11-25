<?php
use PHPUnit\Framework\TestCase;
use SB\Repo;

/** Mitme teenuse broneerimine korraga: kestused ja hinnad liidetakse. */
final class ComboTest extends TestCase {

	private function svc( int $id, string $name, int $min, int $cents, bool $material = false ): array {
		return array( 'id' => $id, 'name' => $name, 'duration' => $min, 'price_cents' => $cents, 'material_extra' => $material, 'hidden' => false, 'note' => 'x' );
	}

	public function test_id_de_lugemine(): void {
		$this->assertSame( array( 3, 7 ), Repo::parse_ids( '3,7' ) );
		$this->assertSame( array( 3 ), Repo::parse_ids( 3 ) );
		$this->assertSame( array( 3, 7 ), Repo::parse_ids( '3,7,3,0,abc' ) );
		$this->assertSame( array(), Repo::parse_ids( '' ) );
		$this->assertSame( Repo::MAX_COMBO, count( Repo::parse_ids( '1,2,3,4,5,6,7,8' ) ) );
	}

	public function test_uks_teenus_jaab_samaks(): void {
		$c = Repo::combine( array( $this->svc( 3, 'Juukselõikus', 60, 1500 ) ) );
		$this->assertSame( 'Juukselõikus', $c['name'] );
		$this->assertSame( 60, $c['duration'] );
		$this->assertSame( array( 3 ), $c['ids'] );
		$this->assertSame( array( 3 ), Repo::ids_of( $c ) );
	}

	public function test_kahe_teenuse_ajad_ja_hinnad_liidetakse(): void {
		$c = Repo::combine( array( $this->svc( 3, 'Juukselõikus', 60, 1500 ), $this->svc( 7, 'Triibutamine', 150, 4000, true ) ) );
		$this->assertSame( 'Juukselõikus + Triibutamine', $c['name'] );
		$this->assertSame( 210, $c['duration'] );
		$this->assertSame( 5500, $c['price_cents'] );
		$this->assertSame( 3, $c['id'] );
		$this->assertSame( array( 3, 7 ), $c['ids'] );
		$this->assertSame( true, $c['material_extra'] );
		$this->assertSame( '3 h 30 min', $c['duration_label'] );
	}

	public function test_praktikandi_lisaaeg_ja_soodustus_iga_teenuse_kohta(): void {
		$c = Repo::combine( array( $this->svc( 3, 'Juukselõikus', 60, 1500 ), $this->svc( 7, 'Triibutamine', 150, 4000 ) ) );
		$t = Repo::service_terms( $c, array( 'extra_min' => 30, 'discount_pct' => 10 ) );
		$this->assertSame( 270, $t['duration'] );
		$one = Repo::service_terms( $this->svc( 3, 'Juukselõikus', 60, 1500 ), array( 'extra_min' => 30, 'discount_pct' => 10 ) );
		$two = Repo::service_terms( $this->svc( 7, 'Triibutamine', 150, 4000 ), array( 'extra_min' => 30, 'discount_pct' => 10 ) );
		$this->assertSame( $one['price_cents'] + $two['price_cents'], $t['price_cents'] );
		$this->assertSame( 90, $one['duration'] );
	}

	public function test_tuhi_valik(): void {
		$this->assertSame( null, Repo::combine( array() ) );
	}
}
