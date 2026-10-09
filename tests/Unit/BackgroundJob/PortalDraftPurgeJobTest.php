<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PortalDraftPurgeJob;
use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The hourly job that deletes expired form drafts.
 */
#[CoversClass(PortalDraftPurgeJob::class)]
class PortalDraftPurgeJobTest extends TestCase {
	/**
	 * Running the job purges the store and the interval is an hour.
	 *
	 * @return void
	 */
	public function testRunPurges(): void {
		$store = $this->createMock(PortalDraftStore::class);
		$store->expects($this->once())->method('purgeExpired')->willReturn(2);
		$job = new PortalDraftPurgeJob($this->createMock(ITimeFactory::class), $store);

		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->assertSame(3600, $job->getInterval());
	}//end testRunPurges()
}//end class
