<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventDeadline;
use OCA\Portaliq\Service\EventSeatRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * RSVP deadline and seat rules for events.
 */
#[CoversClass(EventDeadline::class)]
#[CoversClass(EventSeatRules::class)]
class EventRulesTest extends TestCase {
	/**
	 * Deadline handling: empty, bad, date-only and datetime values.
	 *
	 * @return void
	 */
	public function testDeadline(): void {
		$d = new EventDeadline();

		$this->assertFalse($d->hasPassed(null));
		$this->assertFalse($d->hasPassed('  '));
		$this->assertTrue($d->hasPassed('garbage-not-a-date'));
		$this->assertFalse($d->hasPassed('2026-05-10', '2026-05-10 22:00:00'));
		$this->assertTrue($d->hasPassed('2026-05-10', '2026-05-11 00:00:01'));
		$this->assertTrue($d->hasPassed('2026-05-10 10:00:00', '2026-05-10 10:00:01'));
		$this->assertFalse($d->hasPassed('2026-05-10 10:00:00', '2026-05-10 09:59:00'));
		$this->assertTrue($d->hasPassed('2000-01-01'));
	}//end testDeadline()

	/**
	 * Seat counting follows the event's askSeats / maxSeatsPerAnswer.
	 *
	 * @return void
	 */
	public function testSeats(): void {
		$r = new EventSeatRules();

		$this->assertNull($r->seatsToRecord([], 'yes', 2));
		$this->assertNull($r->seatsToRecord(['askSeats' => 'true'], 'yes', 2));
		$this->assertSame(0, $r->seatsToRecord(['askSeats' => true], 'no', 3));
		$this->assertFalse($r->seatsToRecord(['askSeats' => true], 'yes', null));
		$this->assertFalse($r->seatsToRecord(['askSeats' => true], 'yes', 0));
		$this->assertFalse($r->seatsToRecord(['askSeats' => true], 'yes', 5));
		$this->assertSame(4, $r->seatsToRecord(['askSeats' => true], 'yes', 4));
		$this->assertSame(6, $r->seatsToRecord(['askSeats' => true, 'maxSeatsPerAnswer' => 6], 'yes', 6));
		$this->assertFalse($r->seatsToRecord(['askSeats' => true, 'maxSeatsPerAnswer' => 0], 'yes', 5));
		$this->assertSame(3, $r->seatsToRecord(['askSeats' => true, 'maxSeatsPerAnswer' => 0], 'yes', 3));
	}//end testSeats()
}//end class
