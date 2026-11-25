<?php
use PHPUnit\Framework\TestCase;
use SB\Colors;

final class ColorsTest extends TestCase {

	public function test_grammide_sisestus(): void {
		$this->assertSame( 30.0, Colors::parse_grams( '30' ) );
		$this->assertSame( 25.5, Colors::parse_grams( '25,5' ) );
		$this->assertSame( 12.25, Colors::parse_grams( ' 12.25 g' ) );
		$this->assertSame( null, Colors::parse_grams( '0' ) );
		$this->assertSame( null, Colors::parse_grams( '-5' ) );
		$this->assertSame( null, Colors::parse_grams( 'palju' ) );
		$this->assertSame( null, Colors::parse_grams( '' ) );
		$this->assertSame( null, Colors::parse_grams( '99999' ) );
	}

	public function test_grammide_kirjapilt(): void {
		$this->assertSame( '30 g', Colors::g( 30.0 ) );
		$this->assertSame( '25,5 g', Colors::g( 25.5 ) );
		$this->assertSame( '−12 g', Colors::g( -12.0 ) );
		$this->assertSame( '0 g', Colors::g( 0.0 ) );
	}

	public function test_tuubi_suurus_toote_nimest(): void {
		$this->assertSame( 100.0, Colors::pack_from_name( 'Fuente Colour 7.1 tuhkblond 100 ml' ) );
		$this->assertSame( 60.0, Colors::pack_from_name( 'Fuente 6.0 60ml' ) );
		$this->assertSame( 1000.0, Colors::pack_from_name( 'Fuente Oxidant 6% 1000 ml' ) );
		$this->assertSame( 1000.0, Colors::pack_from_name( 'Fuente Oxidant 3% 1 L' ) );
		$this->assertSame( 100.0, Colors::pack_from_name( 'Fuente 8.3' ) );
	}

	public function test_varvikood_toote_nimest(): void {
		$this->assertSame( '7.1', Colors::code_from_name( 'Fuente Colour 7.1 tuhkblond 100 ml' ) );
		$this->assertSame( '10.32', Colors::code_from_name( 'Fuente juuksevärv 10.32 100ml' ) );
		$this->assertSame( '6.0', Colors::code_from_name( 'Fuente 6,0 60 ml' ) );
		$this->assertSame( '5', Colors::code_from_name( 'Fuente 5 helepruun 100 ml' ) );
		$this->assertSame( '', Colors::code_from_name( 'Fuente Oxidant 6% 1000 ml' ) );
		$this->assertSame( '', Colors::code_from_name( 'Fuente blondeerimispulber' ) );
	}

	public function test_laoseis_tuubides_ja_tase(): void {
		$this->assertSame( '≈ 2,3 tuubi', Colors::packs_label( 230.0, 100.0 ) );
		$this->assertSame( '≈ 0 tuubi', Colors::packs_label( -20.0, 100.0 ) );
		$this->assertSame( 'out', Colors::level( 0.0, 100.0 ) );
		$this->assertSame( 'out', Colors::level( -15.0, 100.0 ) );
		$this->assertSame( 'low', Colors::level( 49.0, 100.0 ) );
		$this->assertSame( 'ok', Colors::level( 50.0, 100.0 ) );
	}

	public function test_retsept_uhe_reana(): void {
		$this->assertSame(
			'7.1 30 g + 8.3 20,5 g',
			Colors::formula_text( array( array( 'code' => '7.1', 'name' => 'x', 'grams' => 30.0 ), array( 'code' => '8.3', 'name' => 'y', 'grams' => 20.5 ) ) )
		);
		$this->assertSame( 'Oxidant 6% 60 g', Colors::formula_text( array( array( 'code' => '', 'name' => 'Oxidant 6%', 'grams' => 60.0 ) ) ) );
	}

	public function test_arve_vaikevalik_nime_jargi(): void {
		$mats = array( array( 'id' => 'pulber', 'name' => 'Blondeerimispulber' ), array( 'id' => 'varv', 'name' => 'Värv' ) );
		$this->assertSame( '-', Colors::bill_from_name( 'Fuente Oxidant 6% 1000 ml', $mats ) );
		$this->assertSame( 'pulber', Colors::bill_from_name( 'Fuente blondeerimispulber 500 g', $mats ) );
		$this->assertSame( 'pulber', Colors::bill_from_name( 'Fuente Powder Shine Clear 500 g', $mats ) );
		$this->assertSame( null, Colors::bill_from_name( 'Fuente Cream 7.1 100 ml', $mats ) );
		$this->assertSame( null, Colors::bill_from_name( 'Fuente blondeerimispulber 500 g', array( array( 'id' => 'varv', 'name' => 'Värv' ) ) ) );
	}

	public function test_vesiniku_grammid_segusuhte_jargi(): void {
		$this->assertSame( 80.0, Colors::ox_grams( 80.0, 1.0 ) );
		$this->assertSame( 60.0, Colors::ox_grams( 40.0, 1.5 ) );
		$this->assertSame( 68.0, Colors::ox_grams( 45.5, 1.5 ) );
		$this->assertSame( 60.0, Colors::ox_grams( 30.0, 2.0 ) );
		$this->assertSame( 0.0, Colors::ox_grams( 30.0, 0.0 ) );
		$this->assertSame( '1:1,5', Colors::ratio_label( 1.5 ) );
		$this->assertSame( '1:2', Colors::ratio_label( 2.0 ) );
		$this->assertSame( 1.5, Colors::parse_ratio( '1,5' ) );
		$this->assertSame( null, Colors::parse_ratio( '7' ) );
	}

	public function test_vesinik_ja_vaikesuhe_nime_jargi(): void {
		$this->assertSame( true, Colors::is_ox_name( 'Fuente Oxidant 6% 1000 ml' ) );
		$this->assertSame( false, Colors::is_ox_name( 'Fuente Cream 7.1 100 ml' ) );
		$this->assertSame( 0.0, Colors::ratio_from_name( 'Fuente Oxidant 3% 1000 ml' ) );
		$this->assertSame( '6%', Colors::ox_code( 'Fuente Oxidant 6% 1000 ml' ) );
		$this->assertSame( '1,9%', Colors::ox_code( 'Fuente Activator 1.9% 1000 ml' ) );
		$this->assertSame( 2.0, Colors::ratio_from_name( 'Fuente Powder Shine Clear 500 g' ) );
		$this->assertSame( 1.0, Colors::ratio_from_name( 'Fuente Shine 7.1 60 ml' ) );
	}

	public function test_poe_nimekirjast_ainult_fuente(): void {
		$items = array(
			array( 'p' => 1, 'v' => 0, 'n' => 'Fuente Colour 7.1 100 ml', 's' => 'F71', 'g' => '' ),
			array( 'p' => 2, 'v' => 0, 'n' => 'Šampoon 250 ml', 's' => 'S1', 'g' => '' ),
			array( 'p' => 3, 'v' => 0, 'n' => 'FUENTE Oxidant 6% 1000 ml', 's' => 'FO6', 'g' => '' ),
		);
		$this->assertSame( array( 1, 3 ), array_column( Colors::filter_index( $items, 'fuente' ), 'p' ) );
		$this->assertSame( array( 1 ), array_column( Colors::filter_index( $items, 'Fuente colour' ), 'p' ) );
		$this->assertSame( array(), Colors::filter_index( $items, '  ' ) );
	}
}
