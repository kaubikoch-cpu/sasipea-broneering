<?php
use PHPUnit\Framework\TestCase;
use SB\Settings;

final class RangesTest extends TestCase {

	public function test_tavaline_kuju(): void {
		$this->assertSame( array( array( 720, 1140 ) ), Settings::parse_ranges( '12:00-19:00' ) );
		$this->assertSame( array( array( 600, 780 ), array( 810, 960 ) ), Settings::parse_ranges( '10:00-13:00, 13:30-16:00' ) );
	}

	/** Viga 0.14.3-s: "12.00 - 19.00" (punkt ja tühikud) salvestus tühjana. */
	public function test_punkt_ja_tuhikud_kriipsu_umber(): void {
		$this->assertSame( array( array( 720, 1140 ) ), Settings::parse_ranges( '12.00 - 19.00' ) );
		$this->assertSame( array( array( 720, 1140 ) ), Settings::parse_ranges( '12:00 – 19:00' ) );
		$this->assertSame( array( array( 600, 780 ), array( 810, 960 ) ), Settings::parse_ranges( '10.00 - 13.00, 13.30 - 16.00' ) );
	}

	public function test_taistunnid(): void {
		$this->assertSame( array( array( 720, 1140 ) ), Settings::parse_ranges( '12-19' ) );
		$this->assertSame( array( array( 540, 1020 ) ), Settings::parse_ranges( '9 kuni 17' ) );
	}

	public function test_vigane_tekst_ei_anna_midagi(): void {
		$this->assertSame( array(), Settings::parse_ranges( '' ) );
		$this->assertSame( array(), Settings::parse_ranges( 'suletud' ) );
		$this->assertSame( array(), Settings::parse_ranges( '19:00-12:00' ) );
		$this->assertSame( array(), Settings::parse_ranges( '12:00-25:00' ) );
	}
}
