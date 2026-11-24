<?php
use PHPUnit\Framework\TestCase;
use SB\Integrations\Modellid;

final class ModellidTest extends TestCase {

	private const TERMS = array( 1 => 'Lõikus', 2 => 'Värvimine', 3 => 'Hallikatvus', 4 => 'Triibutamine', 5 => 'Heledaks / blondeerimine', 6 => 'Habe', 7 => 'Soeng', 8 => 'Hooldus' );

	public function test_teenus_sobitub_modelli_kategooriaga(): void {
		$this->assertSame( array( 1 ), Modellid::match_terms( self::TERMS, 'Juukselõikus Lõikus' ) );
		$this->assertSame( array( 2, 4 ), Modellid::match_terms( self::TERMS, 'Värvimine, pikad juuksed Värvimine ja triibutamine' ) );
		$this->assertSame( array( 8 ), Modellid::match_terms( self::TERMS, 'Juuksehooldus, lühikesed juuksed' ) );
		$this->assertSame( array( 7 ), Modellid::match_terms( self::TERMS, 'Soeng, kuni õlgadeni juuksed' ) );
		$this->assertSame( array( 5 ), Modellid::match_terms( self::TERMS, 'Blondeerimine' ) );
		$this->assertSame( array(), Modellid::match_terms( self::TERMS, 'Konsultatsioon' ) );
	}

	public function test_telefoni_vordlus_ei_soltu_kirjapildist(): void {
		$this->assertSame( '51234567', Modellid::phone_key( '+37251234567' ) );
		$this->assertSame( '51234567', Modellid::phone_key( '5123 4567' ) );
		$this->assertSame( '51234567', Modellid::phone_key( '+372 5123 4567' ) );
		$this->assertSame( '51234567', Modellid::phone_key( '00372 51234567' ) );
		$this->assertSame( '', Modellid::phone_key( '123' ) );
		$this->assertSame( '', Modellid::phone_key( '' ) );
	}
}
