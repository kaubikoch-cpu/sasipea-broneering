<?php
use PHPUnit\Framework\TestCase;
use SB\Availability;

final class AvailabilityTest extends TestCase {

	private function tz(): DateTimeZone {
		return new DateTimeZone( 'Europe/Tallinn' );
	}

	private function ts( string $local ): int {
		return ( new DateTimeImmutable( $local, $this->tz() ) )->getTimestamp();
	}

	/** Teisipäev 2026-12-01, avatud 12–19, üks praktikant 12–19. */
	private function base( array $over = array() ): array {
		return array_replace(
			array(
				'date'           => '2026-12-01',
				'tz'             => $this->tz(),
				'duration'       => 90,
				'step'           => 30,
				'buffer'         => 0,
				'now'            => $this->ts( '2026-11-20 09:00' ),
				'min_notice'     => 12 * 60,
				'max_days'       => 60,
				'opening'        => array( array( 720, 1140 ) ),
				'staff'          => array( 1 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array(), 'blocks' => array() ) ),
				'salon_blocks'   => array(),
				'salon_bookings' => array(),
				'capacity'       => 3,
			),
			$over
		);
	}

	private function times( array $p ): array {
		return array_column( Availability::slots( $p ), 'time' );
	}

	public function test_slots_fit_inside_working_hours(): void {
		$t = $this->times( $this->base() );
		$this->assertSame( '12:00', $t[0] );
		$this->assertSame( '17:30', end( $t ) ); // 17:30 + 1h30 = 19:00
		$this->assertCount( 12, $t );
	}

	public function test_step_15(): void {
		$t = $this->times( $this->base( array( 'step' => 15 ) ) );
		$this->assertContains( '12:15', $t );
		$this->assertSame( '17:30', end( $t ) );
	}

	public function test_closed_day_has_no_slots(): void {
		$this->assertSame( array(), Availability::slots( $this->base( array( 'opening' => array() ) ) ) );
	}

	public function test_staff_hours_narrower_than_opening(): void {
		$p = $this->base();
		$p['staff'][1]['work'] = array( array( 780, 960 ) ); // 13–16
		$this->assertSame( array( '13:00', '13:30', '14:00', '14:30' ), $this->times( $p ) );
	}

	public function test_opening_hours_limit_staff_hours(): void {
		$p = $this->base();
		$p['staff'][1]['work'] = array( array( 600, 1200 ) ); // 10–20, salong 12–19
		$t = $this->times( $p );
		$this->assertSame( '12:00', $t[0] );
		$this->assertSame( '17:30', end( $t ) );
	}

	public function test_break_splits_day(): void {
		$p = $this->base();
		$p['staff'][1]['work'] = array( array( 720, 900 ), array( 930, 1140 ) ); // paus 15:00–15:30
		$t = $this->times( $p );
		$this->assertContains( '13:30', $t );     // lõpeb 15:00
		$this->assertNotContains( '14:00', $t );  // ulatuks pausi
		$this->assertNotContains( '15:00', $t );
		$this->assertContains( '15:30', $t );
	}

	public function test_existing_booking_blocks_overlap(): void {
		$p = $this->base();
		$p['staff'][1]['bookings'] = array( array( $this->ts( '2026-12-01 14:00' ), $this->ts( '2026-12-01 15:30' ) ) );
		$p['salon_bookings']       = $p['staff'][1]['bookings'];
		$t                         = $this->times( $p );
		$this->assertContains( '12:30', $t );    // lõpeb täpselt 14:00
		$this->assertNotContains( '13:00', $t );
		$this->assertNotContains( '15:00', $t );
		$this->assertContains( '15:30', $t );    // algab täpselt lõpus
	}

	public function test_buffer_between_bookings(): void {
		$p = $this->base( array( 'buffer' => 15, 'step' => 15 ) );
		$p['staff'][1]['bookings'] = array( array( $this->ts( '2026-12-01 14:00' ), $this->ts( '2026-12-01 15:30' ) ) );
		$t                         = $this->times( $p );
		$this->assertNotContains( '12:30', $t ); // lõpeks 14:00, puhver puudu
		$this->assertContains( '12:15', $t );
		$this->assertNotContains( '15:30', $t );
		$this->assertContains( '15:45', $t );
	}

	public function test_staff_block_and_salon_block(): void {
		$p = $this->base();
		$p['staff'][1]['blocks'] = array( array( $this->ts( '2026-12-01 12:00' ), $this->ts( '2026-12-01 14:00' ) ) );
		$this->assertSame( '14:00', $this->times( $p )[0] );

		$p                 = $this->base();
		$p['salon_blocks'] = array( array( $this->ts( '2026-12-01 00:00' ), $this->ts( '2026-12-02 00:00' ) ) );
		$this->assertSame( array(), $this->times( $p ) );
	}

	public function test_any_staff_returns_union(): void {
		$p             = $this->base();
		$p['staff'][2] = array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array(), 'blocks' => array() );
		$busy          = array( $this->ts( '2026-12-01 12:00' ), $this->ts( '2026-12-01 13:30' ) );
		$p['staff'][1]['bookings'] = array( $busy );
		$p['salon_bookings']       = array( $busy );
		$slots                     = Availability::slots( $p );
		$this->assertSame( '12:00', $slots[0]['time'] );
		$this->assertSame( array( 2 ), $slots[0]['staff'] );
	}

	public function test_supervisor_capacity(): void {
		// 4 praktikanti, aga juhendaja jõuab korraga 3 kliendiga.
		$p = $this->base( array( 'capacity' => 3 ) );
		$b = array( $this->ts( '2026-12-01 12:00' ), $this->ts( '2026-12-01 14:00' ) );
		foreach ( array( 2, 3, 4 ) as $id ) {
			$p['staff'][ $id ] = array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array( $b ), 'blocks' => array() );
		}
		$p['salon_bookings'] = array( $b, $b, $b );
		$t                   = $this->times( $p );
		$this->assertNotContains( '12:00', $t ); // praktikant 1 on vaba, aga maht on täis
		$this->assertNotContains( '13:30', $t );
		$this->assertContains( '14:00', $t );

		$p['capacity'] = 4;
		$this->assertContains( '12:00', $this->times( $p ) );
	}

	public function test_capacity_counts_peak_not_total(): void {
		// Kolm järjestikust broneeringut ei ole kolm samaaegset.
		$r = array(
			array( 0, 100 ),
			array( 100, 200 ),
			array( 200, 300 ),
		);
		$this->assertSame( 1, Availability::max_overlap( 0, 300, $r ) );
		$this->assertSame( 2, Availability::max_overlap( 0, 300, array( array( 0, 150 ), array( 100, 200 ) ) ) );
	}

	public function test_min_notice(): void {
		// Kell on esmaspäev 23:00, etteteatamine 12 h => teisipäeval varaseim 11:00, st kõik alates 12:00.
		$p = $this->base( array( 'now' => $this->ts( '2026-11-30 23:00' ) ) );
		$this->assertSame( '12:00', $this->times( $p )[0] );
		// Kell on teisipäev 02:00 => varaseim 14:00.
		$p = $this->base( array( 'now' => $this->ts( '2026-12-01 02:00' ) ) );
		$this->assertSame( '14:00', $this->times( $p )[0] );
	}

	public function test_past_day_and_max_days_ahead(): void {
		$this->assertSame( array(), $this->times( $this->base( array( 'now' => $this->ts( '2026-12-02 09:00' ) ) ) ) );
		$this->assertSame( array(), $this->times( $this->base( array( 'max_days' => 5 ) ) ) );
		$this->assertNotEmpty( $this->times( $this->base( array( 'max_days' => 11 ) ) ) );
	}

	public function test_admin_ignores_notice_and_horizon(): void {
		$p = $this->base( array( 'now' => $this->ts( '2026-12-01 13:00' ), 'min_notice' => null, 'max_days' => null ) );
		$this->assertSame( array( 1 ), Availability::free_staff( $p, $this->ts( '2026-12-01 13:15' ) ) );
	}

	public function test_free_staff_rejects_outside_hours(): void {
		$p = $this->base();
		$this->assertSame( array(), Availability::free_staff( $p, $this->ts( '2026-12-01 18:00' ) ) ); // lõpeks 19:30
		$this->assertSame( array(), Availability::free_staff( $p, $this->ts( '2026-12-01 11:30' ) ) );
	}

	/* ---------- praktikandi enda kestus (teenus + lisaaeg) ---------- */

	public function test_praktikandi_lisaaeg_lyhendab_viimast_algusaega(): void {
		// 90 min teenus, praktikant 2 teeb seda 30 min kauem: tema viimane algus on 17:00, mitte 17:30.
		$p = $this->base(
			array(
				'staff' => array(
					1 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array(), 'blocks' => array() ),
					2 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array(), 'blocks' => array(), 'duration' => 120 ),
				),
			)
		);
		$this->assertSame( array( 1, 2 ), Availability::free_staff( $p, $this->ts( '2026-12-01 17:00' ) ) );
		$this->assertSame( array( 1 ), Availability::free_staff( $p, $this->ts( '2026-12-01 17:30' ) ) );
	}

	public function test_ainult_aeglasem_praktikant_maarab_viimase_aja(): void {
		$p = $this->base(
			array( 'staff' => array( 2 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => array(), 'blocks' => array(), 'duration' => 120 ) ) )
		);
		$times = $this->times( $p );
		$this->assertContains( '17:00', $times );
		$this->assertNotContains( '17:30', $times );
	}

	public function test_lisaaeg_kattub_jargmise_broneeringuga(): void {
		// Järgmine klient kell 14:00. Tavakestusega mahub 12:30 algus ära, lisaajaga mitte.
		$busy = array( array( $this->ts( '2026-12-01 14:00' ), $this->ts( '2026-12-01 15:30' ) ) );
		$p    = $this->base(
			array(
				'staff'          => array(
					1 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => $busy, 'blocks' => array() ),
					2 => array( 'work' => array( array( 720, 1140 ) ), 'bookings' => $busy, 'blocks' => array(), 'duration' => 120 ),
				),
				'salon_bookings' => array(),
			)
		);
		$this->assertSame( array( 1 ), Availability::free_staff( $p, $this->ts( '2026-12-01 12:30' ) ) );
		$this->assertSame( array( 1, 2 ), Availability::free_staff( $p, $this->ts( '2026-12-01 12:00' ) ) );
	}
}
