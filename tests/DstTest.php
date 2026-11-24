<?php
use PHPUnit\Framework\TestCase;
use SB\Availability;

/** Suveaja vahetus: kellaajad peavad jääma kohaliku kella järgi õigeks. */
final class DstTest extends TestCase {

	private function p( string $date, string $now ): array {
		$tz = new DateTimeZone( 'Europe/Tallinn' );
		return array(
			'date'           => $date,
			'tz'             => $tz,
			'duration'       => 60,
			'step'           => 60,
			'buffer'         => 0,
			'now'            => ( new DateTimeImmutable( $now, $tz ) )->getTimestamp(),
			'min_notice'     => 0,
			'max_days'       => 60,
			'opening'        => array( array( 600, 960 ) ),
			'staff'          => array( 1 => array( 'work' => array( array( 600, 960 ) ), 'bookings' => array(), 'blocks' => array() ) ),
			'salon_blocks'   => array(),
			'salon_bookings' => array(),
			'capacity'       => 3,
		);
	}

	public function test_day_before_and_after_spring_forward(): void {
		// 2027-03-28 kell 03:00 -> 04:00 (UTC+2 -> UTC+3).
		$before = Availability::slots( $this->p( '2027-03-26', '2027-03-20 09:00' ) );
		$after  = Availability::slots( $this->p( '2027-03-31', '2027-03-20 09:00' ) );
		$this->assertSame( '10:00', $before[0]['time'] );
		$this->assertSame( '10:00', $after[0]['time'] );
		$this->assertSame( '08:00', gmdate( 'H:i', $before[0]['start'] ) ); // UTC+2
		$this->assertSame( '07:00', gmdate( 'H:i', $after[0]['start'] ) );  // UTC+3
		$this->assertCount( 6, $before );
		$this->assertCount( 6, $after );
	}

	public function test_autumn_fall_back(): void {
		// 2026-10-25 kell 04:00 -> 03:00 (UTC+3 -> UTC+2).
		$before = Availability::slots( $this->p( '2026-10-23', '2026-10-01 09:00' ) );
		$after  = Availability::slots( $this->p( '2026-10-28', '2026-10-01 09:00' ) );
		$this->assertSame( '07:00', gmdate( 'H:i', $before[0]['start'] ) );
		$this->assertSame( '08:00', gmdate( 'H:i', $after[0]['start'] ) );
	}

	public function test_booking_across_dst_blocks_correct_local_time(): void {
		$tz = new DateTimeZone( 'Europe/Tallinn' );
		$p  = $this->p( '2027-03-31', '2027-03-20 09:00' );
		$b  = array( ( new DateTimeImmutable( '2027-03-31 12:00', $tz ) )->getTimestamp(), ( new DateTimeImmutable( '2027-03-31 13:00', $tz ) )->getTimestamp() );
		$p['staff'][1]['bookings'] = array( $b );
		$t                         = array_column( Availability::slots( $p ), 'time' );
		$this->assertNotContains( '12:00', $t );
		$this->assertContains( '11:00', $t );
		$this->assertContains( '13:00', $t );
	}
}
