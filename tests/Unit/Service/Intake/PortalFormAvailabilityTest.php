<?php

/**
 * Tests for PortalFormAvailability.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Intake\PortalFormAvailability;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Each FormulierNietBeschikbaar situation, the open form, and the count the
 * limit asks for.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalFormAvailability
 */
class PortalFormAvailabilityTest extends TestCase {

	/**
	 * The filters the count was asked with, or null when it was not asked.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $counted = null;

	/**
	 * The service at a fixed moment, with a fixed count.
	 *
	 * @param string $now   Now, as a local Amsterdam time.
	 * @param int    $count What the count answers.
	 *
	 * @return PortalFormAvailability
	 */
	private function service(string $now = '2026-10-09T12:00', int $count = 0): PortalFormAvailability {
		$objects = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['countObjects'])
			->getMock();
		$objects->method('countObjects')->willReturnCallback(
			function (string $register, string $schema, array $filters = []) use ($count): int {
				$this->counted = $filters;
				return $count;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable($now, new DateTimeZone('Europe/Amsterdam')));

		return new PortalFormAvailability($objects, $clock);
	}//end service()

	/**
	 * A binding with the given availability.
	 *
	 * @param array<string, mixed> $availability The availability.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(array $availability): array {
		return ['portal' => 'zuiddrecht', 'route' => 'aanvragen/geveltuin', 'availability' => $availability];
	}//end binding()

	/**
	 * A binding without availability is open and costs no count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testABindingWithoutAvailabilityIsOpen(): void {
		$this->assertNull($this->service()->situation(['portal' => 'zuiddrecht', 'route' => 'x']));
		$this->assertNull($this->counted);
	}//end testABindingWithoutAvailabilityIsOpen()

	/**
	 * The 150 places are taken: the limit over the whole life of the form,
	 * with the reopening day the binding names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testTheLimitIsReached(): void {
		$situation = $this->service(count: 150)->situation($this->binding(['limit' => ['count' => 150, 'per' => 'total'], 'reopensOn' => '2027-01-05']));

		$this->assertSame(['situation' => 'limitReached', 'count' => 150, 'per' => 'total', 'reopensOn' => '2027-01-05'], $situation);
		$this->assertSame(['portal' => 'zuiddrecht', 'route' => 'aanvragen/geveltuin'], $this->counted);
	}//end testTheLimitIsReached()

	/**
	 * Below the limit the form is open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testBelowTheLimitTheFormIsOpen(): void {
		$this->assertNull($this->service(count: 149)->situation($this->binding(['limit' => ['count' => 150, 'per' => 'total']])));
	}//end testBelowTheLimitTheFormIsOpen()

	/**
	 * A monthly limit counts from the first of the month and reopens on the
	 * first of the next one when the binding names no day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testAMonthlyLimitCountsThisMonth(): void {
		$situation = $this->service(count: 500)->situation($this->binding(['limit' => ['count' => 500, 'per' => 'month']]));

		$this->assertSame('2026-11-01', $situation['reopensOn']);
		$this->assertSame('2026-10-01T00:00:00+02:00', $this->counted['submittedAt']['gte']);
	}//end testAMonthlyLimitCountsThisMonth()

	/**
	 * During maintenance the form says so, with the window and the admin's words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testMaintenanceShowsTheWindow(): void {
		$binding = $this->binding(['maintenance' => ['from' => '2026-10-09T18:00', 'until' => '2026-10-09T22:00', 'text' => '']]);

		$this->assertNull($this->service(now: '2026-10-09T17:59')->situation($binding));
		$this->assertSame(
			['situation' => 'maintenance', 'from' => '2026-10-09T18:00', 'until' => '2026-10-09T22:00', 'text' => ''],
			$this->service(now: '2026-10-09T18:00')->situation($binding)
		);
		$this->assertNull($this->service(now: '2026-10-09T22:00')->situation($binding));
	}//end testMaintenanceShowsTheWindow()

	/**
	 * A replaced form points to its successor; a link off the portal is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testAReplacedFormPointsToItsSuccessor(): void {
		$this->assertSame(
			['situation' => 'replaced', 'replacedBy' => '/aanvragen/woo-verzoek-2027'],
			$this->service()->situation($this->binding(['replacedBy' => '/aanvragen/woo-verzoek-2027']))
		);
		foreach (['//elders.example', 'https://elders.example', 'javascript:alert(1)'] as $elsewhere) {
			$this->assertNull($this->service()->situation($this->binding(['replacedBy' => $elsewhere])), $elsewhere);
		}
	}//end testAReplacedFormPointsToItsSuccessor()

	/**
	 * Before its first day the form says when it opens; after its last day it is closed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function testTheActivePeriodOpensAndCloses(): void {
		$binding = $this->binding(['activeFrom' => '2026-11-01', 'activeUntil' => '2026-12-31']);

		$this->assertSame(['situation' => 'notYetOpen', 'opensOn' => '2026-11-01'], $this->service(now: '2026-10-31T23:59')->situation($binding));
		$this->assertNull($this->service(now: '2026-12-31T23:59')->situation($binding));
		$this->assertSame(['situation' => 'closed'], $this->service(now: '2027-01-01T00:00')->situation($binding));
	}//end testTheActivePeriodOpensAndCloses()
}//end class
