<?php

/**
 * Unit tests for CosignDeadlineJob.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Portaliq\BackgroundJob\CosignDeadlineJob;
use OCA\Portaliq\Service\Intake\PortalCosign;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The hourly job that lapses co-signatures past their deadline (REQ-RIF-003).
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
#[CoversClass(CosignDeadlineJob::class)]
class CosignDeadlineJobTest extends TestCase {

	/**
	 * Running the job lapses at the clock's moment, once an hour.
	 *
	 * @return void
	 */
	public function testRunLapsesAtTheClocksMoment(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1792656000);
		$cosign = $this->createMock(PortalCosign::class);
		$cosign->expects($this->once())->method('expire')->with(
			$this->callback(static fn (DateTimeImmutable $now): bool => $now->getTimestamp() === 1792656000)
		)->willReturn(['AANVRAAG-1']);
		$job = new CosignDeadlineJob($time, $cosign);

		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->assertSame(3600, $job->getInterval());

	}//end testRunLapsesAtTheClocksMoment()
}//end class
