<?php
use PHPUnit\Framework\TestCase;
use SB\Users;
use SB\Dashboard;

final class UsersTest extends TestCase {

	public function test_hallata_saab_ainult_oma_rollidega_kontosid(): void {
		$this->assertSame( 'sb_praktikant', Users::managed_role( array( 'sb_praktikant' ) ) );
		$this->assertSame( 'sb_omanik', Users::managed_role( array( 'sb_praktikant', 'sb_omanik' ) ) );
		$this->assertSame( 'sb_juhendaja', Users::managed_role( array( 'subscriber', 'sb_juhendaja' ) ) );
		// Administraatorit ei saa töölaualt muuta ka siis, kui tal on lisaks salongi roll.
		$this->assertSame( null, Users::managed_role( array( 'administrator', 'sb_omanik' ) ) );
		$this->assertSame( null, Users::managed_role( array( 'editor' ) ) );
		$this->assertSame( null, Users::managed_role( array() ) );
	}

	public function test_kasutajanimi_e_posti_algusest(): void {
		$this->assertSame( 'mari.tamm', Users::login_base( 'Mari.Tamm@example.test' ) );
		$this->assertSame( 'marisalong', Users::login_base( 'mari+salong@example.test' ) );
		$this->assertSame( 'kasutaja', Users::login_base( '@example.test' ) );
		$this->assertSame( 'kasutaja', Users::login_base( '...@example.test' ) );
	}

	public function test_google_sisselogimine_ainult_lubatud_kontodele(): void {
		$this->assertSame( true, Dashboard::google_allowed( array( 'sb_praktikant' ), true, false, false ) );
		$this->assertSame( false, Dashboard::google_allowed( array( 'sb_praktikant' ), true, true, false ) );
		$this->assertSame( false, Dashboard::google_allowed( array( 'subscriber' ), false, false, true ) );
		$this->assertSame( false, Dashboard::google_allowed( array( 'administrator' ), true, false, false ) );
		$this->assertSame( true, Dashboard::google_allowed( array( 'administrator' ), true, false, true ) );
		$this->assertSame( false, Dashboard::google_allowed( array( 'administrator' ), true, true, true ) );
	}
}
