<?php
use PHPUnit\Framework\TestCase;
use SB\Mailer;

final class MailerTest extends TestCase {

	public function test_kliendi_enda_tegevus_saadab_kirja_kliendile_ja_salongile(): void {
		foreach ( array( 'created', 'moved', 'cancelled' ) as $e ) {
			$this->assertSame( array( 'client' => true, 'admin' => true ), Mailer::plan( $e, true, true, true ) );
		}
	}

	public function test_salongi_tegevus_ei_saada_salongile_kirja(): void {
		foreach ( array( 'created', 'moved', 'cancelled' ) as $e ) {
			$this->assertFalse( Mailer::plan( $e, false, true, true )['admin'] );
		}
	}

	public function test_salongi_muudatus_teavitab_klienti_ainult_aja_muutumisel(): void {
		$this->assertTrue( Mailer::plan( 'moved', false, true, true, true )['client'] );
		$this->assertFalse( Mailer::plan( 'moved', false, true, true, false )['client'] );
	}

	public function test_linnukeseta_voi_moodunud_aja_kohta_kirja_ei_tule(): void {
		$this->assertFalse( Mailer::plan( 'created', false, false, true )['client'] );
		$this->assertFalse( Mailer::plan( 'cancelled', false, false, true )['client'] );
		$this->assertFalse( Mailer::plan( 'cancelled', false, true, false )['client'] );
		$this->assertFalse( Mailer::plan( 'moved', false, true, false, true )['client'] );
	}
}
