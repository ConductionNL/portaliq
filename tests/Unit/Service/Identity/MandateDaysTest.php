<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\MandateDays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Date helpers behind the mandate (machtiging) lifecycle.
 */
#[CoversClass(MandateDays::class)]
class MandateDaysTest extends TestCase {
	/**
	 * An empty value never expires; a past or unparseable one does.
	 *
	 * @return void
	 */
	public function testExpired(): void {
		$days = new MandateDays();
		$now  = new DateTimeImmutable('2026-05-10 12:00:00');

		$this->assertFalse($days->expired('', $now));
		$this->assertTrue($days->expired('2026-05-09', $now));
		$this->assertTrue($days->expired('not a date', $now));
		$this->assertFalse($days->expired('2026-06-01', $now));
	}//end testExpired()

	/**
	 * A day becomes the last second of that day in Amsterdam time.
	 *
	 * @return void
	 */
	public function testEndOfDay(): void {
		$days = new MandateDays();

		$this->assertSame('', $days->endOfDay(''));
		$this->assertSame('', $days->endOfDay('garbage'));
		$this->assertSame('2026-01-15T23:59:59+01:00', $days->endOfDay('2026-01-15'));
		$this->assertSame('2026-07-15T23:59:59+02:00', $days->endOfDay('2026-07-15'));
	}//end testEndOfDay()

	/**
	 * Only a well-formed date strictly after today counts as future.
	 *
	 * @return void
	 */
	public function testFutureDay(): void {
		$days = new MandateDays();
		$now  = new DateTimeImmutable('2026-05-10 12:00:00');

		$this->assertTrue($days->futureDay('2026-05-11', $now));
		$this->assertFalse($days->futureDay('2026-05-10', $now));
		$this->assertFalse($days->futureDay('2026-05-09', $now));
		$this->assertFalse($days->futureDay('2026-02-31', $now));
		$this->assertFalse($days->futureDay('nope', $now));
	}//end testFutureDay()
}//end class
