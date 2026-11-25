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
			$this->assertSame( false, Mailer::plan( $e, false, true, true )['admin'] );
		}
	}

	public function test_salongi_muudatus_teavitab_klienti_ainult_aja_muutumisel(): void {
		$this->assertSame( true, Mailer::plan( 'moved', false, true, true, true )['client'] );
		$this->assertSame( false, Mailer::plan( 'moved', false, true, true, false )['client'] );
	}

	public function test_linnukeseta_voi_moodunud_aja_kohta_kirja_ei_tule(): void {
		$this->assertSame( false, Mailer::plan( 'created', false, false, true )['client'] );
		$this->assertSame( false, Mailer::plan( 'cancelled', false, false, true )['client'] );
		$this->assertSame( false, Mailer::plan( 'cancelled', false, true, false )['client'] );
		$this->assertSame( false, Mailer::plan( 'moved', false, true, false, true )['client'] );
	}

	public function test_parandus_10_minuti_sees_jatab_alles_esimese_algse_aja(): void {
		$p = Mailer::queue( array(), 7, '2026-12-01 10:00:00', 1000 );
		$p = Mailer::queue( $p, 7, '2026-12-01 10:15:00', 1200 );
		$this->assertSame( '2026-12-01 10:00:00', $p[7]['from'] );
		$this->assertSame( 1200 + Mailer::DELAY, $p[7]['due'] );
	}

	public function test_algsele_ajale_tagasi_liigutatud_broneering_kirja_ei_saada(): void {
		$this->assertSame( false, Mailer::move_still_matters( '2026-12-01 10:00:00', '2026-12-01 10:00:00', true, true ) );
		$this->assertSame( true, Mailer::move_still_matters( '2026-12-01 10:00:00', '2026-12-01 10:30:00', true, true ) );
		$this->assertSame( false, Mailer::move_still_matters( '2026-12-01 10:00:00', '2026-12-01 10:30:00', false, true ) );
	}
}
