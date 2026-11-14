<?php

/**
 * Unit tests for the PublicPublicationRead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Publication;

use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\Publication\PublicPublicationRead;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The anonymous read: found only for the object with that id, a 410 carries
 * the withdrawal and nothing else, everything else is not found.
 *
 * @covers \OCA\Portaliq\Service\Publication\PublicPublicationRead
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-withdrawn-link-says-it-was-withdrawn-and-why-req-per-004
 */
class PublicPublicationReadTest extends TestCase {
	/**
	 * The read over the fixture's loopback.
	 *
	 * @param PublicationReportFixture $fixture The recorded boundaries.
	 *
	 * @return PublicPublicationRead
	 */
	private function read(PublicationReportFixture $fixture): PublicPublicationRead {
		return new PublicPublicationRead(loopback: $fixture->loopback(), logger: new NullLogger());
	}//end read()

	/**
	 * A public publication is found with its body, read on opencatalogi's
	 * public by-id route.
	 *
	 * @return void
	 */
	public function testAPublicPublicationIsFound(): void {
		$fixture = new PublicationReportFixture(test: $this);
		$fixture->publications['pub-1'] = [200, ['id' => 'pub-1', 'title' => 'Besluit']];

		$this->assertSame(expected: ['status' => 200, 'body' => ['id' => 'pub-1', 'title' => 'Besluit']], actual: $this->read(fixture: $fixture)->read(id: 'pub-1'));
		$this->assertSame(expected: ['pub-1'], actual: $fixture->paths);
	}//end testAPublicPublicationIsFound()

	/**
	 * A 200 for another id (a route that fell through to the list) is not found.
	 *
	 * @return void
	 */
	public function testAnotherObjectIsNotFound(): void {
		$fixture = new PublicationReportFixture(test: $this);
		$fixture->publications['pub-1'] = [200, ['id' => 'pub-2']];

		$this->assertSame(expected: ['status' => 404, 'body' => null], actual: $this->read(fixture: $fixture)->read(id: 'pub-1'));
	}//end testAnotherObjectIsNotFound()

	/**
	 * A 410 carries the date, the reason and the title only, never the rest.
	 *
	 * @return void
	 */
	public function testAWithdrawalCarriesOnlyItsNotice(): void {
		$fixture = new PublicationReportFixture(test: $this);
		$fixture->publications['pub-1'] = [410, ['id' => 'pub-1', 'withdrawnAt' => '2026-10-01', 'publicReason' => 'Gepubliceerd in strijd met de AVG', 'summary' => 'geheim']];

		$this->assertSame(
			expected: ['status' => 410, 'body' => ['withdrawnAt' => '2026-10-01', 'publicReason' => 'Gepubliceerd in strijd met de AVG']],
			actual: $this->read(fixture: $fixture)->read(id: 'pub-1')
		);
	}//end testAWithdrawalCarriesOnlyItsNotice()

	/**
	 * A malformed id is not sent at all; a transport failure is not found.
	 *
	 * @return void
	 */
	public function testAMalformedIdOrAFailedReadIsNotFound(): void {
		$fixture = new PublicationReportFixture(test: $this);
		$this->assertSame(expected: ['status' => 404, 'body' => null], actual: $this->read(fixture: $fixture)->read(id: '../admin'));
		$this->assertSame(expected: [], actual: $fixture->paths);

		$loopback = $this->createMock(InstanceLoopback::class);
		$loopback->method('request')->willThrowException(new RuntimeException('down'));
		$read = new PublicPublicationRead(loopback: $loopback, logger: new NullLogger());
		$this->assertSame(expected: ['status' => 404, 'body' => null], actual: $read->read(id: 'pub-1'));
	}//end testAMalformedIdOrAFailedReadIsNotFound()
}//end class
