<?php

/**
 * Unit tests for PortalFormAvailability.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\PortalFormAvailability;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * Each situation of the FormulierNietBeschikbaar board, judged at a fixed
 * moment, and the limit counted over the destination's objects of the period.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalFormAvailability
 * @uses   \OCA\Portaliq\Service\Intake\DestinationMapping
 */
class PortalFormAvailabilityTest extends TestCase {

	/**
	 * The count calls the writer received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counted = [];

	/**
	 * The service over a writer that answers $count for every count.
	 *
	 * @param int $count The count to answer.
	 *
	 * @return PortalFormAvailability
	 */
	private function availability(int $count = 0): PortalFormAvailability {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('countObjects')->willReturnCallback(
			function (string $register, string $schema, array $filters = []) use ($count): int {
				$this->counted[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
				return $count;
			}
		);

		return new PortalFormAvailability(mapping: new DestinationMapping(), objects: $writer);
	}//end availability()

	/**
	 * A binding to the dossiq case schema with this availability.
	 *
	 * @param array<string, mixed> $availability The availability.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(array $availability): array {
		return ['route' => 'aanvragen/geveltuin', 'destination' => ['register' => 'dossiq', 'schema' => 'case'], 'availability' => $availability];
	}//end binding()

	/**
	 * A moment in Amsterdam.
	 *
	 * @param string $local The local time.
	 *
	 * @return DateTimeImmutable
	 */
	private function at(string $local): DateTimeImmutable {
		return new DateTimeImmutable($local, new \DateTimeZone('Europe/Amsterdam'));
	}//end at()

	/**
	 * A binding without availability, or with an empty one, is open and counts nothing.
	 *
	 * @return void
	 */
	public function testWithoutAvailabilityTheFormIsOpen(): void {
		$service = $this->availability(count: 999);

		$this->assertNull($service->unavailable(binding: ['route' => 'x'], now: $this->at('2026-10-09 12:00')));
		$this->assertNull($service->unavailable(binding: ['availability' => 'yes'], now: $this->at('2026-10-09 12:00')));
		$this->assertNull($service->unavailable(binding: $this->binding([]), now: $this->at('2026-10-09 12:00')));
		$this->assertNull($service->counter(binding: $this->binding([])));
		$this->assertSame([], $this->counted);
	}//end testWithoutAvailabilityTheFormIsOpen()

	/**
	 * Situation 3: the 150 places are taken over the total; the reopening date is carried.
	 *
	 * @return void
	 */
	public function testTheLimitReachedIsFull(): void {
		$binding = $this->binding(['limit' => ['count' => 150, 'per' => 'total'], 'reopensOn' => '2027-01-05']);

		$this->assertSame(
			['situation' => 'full', 'limit' => 150, 'per' => 'total', 'reopensOn' => '2027-01-05'],
			$this->availability(count: 150)->unavailable(binding: $binding, now: $this->at('2026-12-20 10:00'))
		);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case', 'filters' => []], $this->counted[0]);

		$this->assertNull($this->availability(count: 149)->unavailable(binding: $binding, now: $this->at('2026-12-20 10:00')));
	}//end testTheLimitReachedIsFull()

	/**
	 * A monthly limit counts from the first of the month, Amsterdam time,
	 * asked in UTC; a yearly one from 1 January. The counter feeds the admin panel.
	 *
	 * @return void
	 */
	public function testTheCounterCountsTheCurrentPeriod(): void {
		$monthly = $this->binding(['limit' => ['count' => 500, 'per' => 'month']]);

		$this->assertSame(
			['count' => 41, 'limit' => 500, 'per' => 'month'],
			$this->availability(count: 41)->counter(binding: $monthly, now: $this->at('2026-10-09 12:00'))
		);
		$this->assertSame(['@self' => ['created' => ['gte' => '2026-09-30 22:00:00']]], $this->counted[0]['filters']);

		$yearly = $this->binding(['limit' => ['count' => 10, 'per' => 'year']]);
		$this->availability(count: 0)->counter(binding: $yearly, now: $this->at('2026-10-09 12:00'));
		$this->assertSame(['@self' => ['created' => ['gte' => '2025-12-31 23:00:00']]], $this->counted[1]['filters']);
	}//end testTheCounterCountsTheCurrentPeriod()

	/**
	 * A limit without a positive count or with an unknown period is no limit.
	 *
	 * @return void
	 */
	public function testAnUnusableLimitIsNoLimit(): void {
		$service = $this->availability(count: 1000);

		$this->assertNull($service->counter(binding: $this->binding(['limit' => ['count' => 0, 'per' => 'month']])));
		$this->assertNull($service->counter(binding: $this->binding(['limit' => ['count' => 5, 'per' => 'week']])));
		$this->assertNull($service->counter(binding: $this->binding(['limit' => '5'])));
		$this->assertSame([], $this->counted);
	}//end testAnUnusableLimitIsNoLimit()

	/**
	 * Without a destination, or without the writer, the count is 0 and the form stays open.
	 *
	 * @return void
	 */
	public function testACountThatCannotBeMadeKeepsTheFormOpen(): void {
		$limit = ['limit' => ['count' => 1, 'per' => 'total']];

		$this->assertSame(
			['count' => 0, 'limit' => 1, 'per' => 'total'],
			$this->availability(count: 5)->counter(binding: ['availability' => $limit])
		);
		$this->assertSame([], $this->counted);

		$unwired = new PortalFormAvailability(mapping: new DestinationMapping());
		$this->assertNull($unwired->unavailable(binding: $this->binding($limit), now: $this->at('2026-10-09 12:00')));
	}//end testACountThatCannotBeMadeKeepsTheFormOpen()

	/**
	 * Situation 1: inside the maintenance window, its bounds and text; at its end the form is back.
	 *
	 * @return void
	 */
	public function testMaintenanceHoldsForItsWindow(): void {
		$service = $this->availability();
		$binding = $this->binding(['maintenance' => ['from' => '2026-10-09T18:00', 'until' => '2026-10-09T22:00', 'text' => 'Wij werken aan het formulier.']]);

		$this->assertSame(
			['situation' => 'maintenance', 'from' => '2026-10-09T18:00', 'until' => '2026-10-09T22:00', 'text' => 'Wij werken aan het formulier.'],
			$service->unavailable(binding: $binding, now: $this->at('2026-10-09 18:00'))
		);
		$this->assertSame('maintenance', $service->unavailable(binding: $binding, now: new DateTimeImmutable('2026-10-09T19:30:00Z'))['situation'] ?? null);
		$this->assertNull($service->unavailable(binding: $binding, now: $this->at('2026-10-09 17:59')));
		$this->assertNull($service->unavailable(binding: $binding, now: $this->at('2026-10-09 22:00')));
		$this->assertNull($service->unavailable(binding: $this->binding(['maintenance' => ['from' => '2026-10-09T18:00']]), now: $this->at('2026-10-09 19:00')));
		$this->assertNull($service->unavailable(binding: $this->binding(['maintenance' => ['from' => 'not a date', 'until' => '2026-10-09T22:00']]), now: $this->at('2026-10-09 19:00')));
	}//end testMaintenanceHoldsForItsWindow()

	/**
	 * Situation 2: a replaced form points to its successor, before anything else is judged.
	 *
	 * @return void
	 */
	public function testAReplacedFormPointsToItsSuccessor(): void {
		$binding = $this->binding([
			'replacedBy' => ' /aanvragen/woo-verzoek-2027 ',
			'activeFrom' => '2030-01-01',
			'limit' => ['count' => 1, 'per' => 'total'],
		]);

		$this->assertSame(
			['situation' => 'replaced', 'replacedBy' => '/aanvragen/woo-verzoek-2027'],
			$this->availability(count: 10)->unavailable(binding: $binding, now: $this->at('2026-10-09 12:00'))
		);
		$this->assertSame([], $this->counted);
	}//end testAReplacedFormPointsToItsSuccessor()

	/**
	 * The active period is inclusive on both calendar days, in Amsterdam.
	 *
	 * @return void
	 */
	public function testTheActivePeriodOpensAndCloses(): void {
		$service = $this->availability();
		$binding = $this->binding(['activeFrom' => '2026-01-01', 'activeUntil' => '2026-12-31']);

		$this->assertSame(
			['situation' => 'notYetOpen', 'opensOn' => '2026-01-01'],
			$service->unavailable(binding: $binding, now: $this->at('2025-12-31 23:59'))
		);
		$this->assertNull($service->unavailable(binding: $binding, now: $this->at('2026-01-01 00:00')));
		$this->assertNull($service->unavailable(binding: $binding, now: new DateTimeImmutable('2026-12-31T22:30:00Z')));
		$this->assertSame(
			['situation' => 'closed', 'closedOn' => '2026-12-31'],
			$service->unavailable(binding: $binding, now: $this->at('2027-01-01 00:00'))
		);
		$this->assertNull($service->unavailable(binding: $this->binding(['activeFrom' => '1 januari']), now: $this->at('2025-01-01 00:00')));
	}//end testTheActivePeriodOpensAndCloses()

	/**
	 * A malformed reopening date is left out of the full situation.
	 *
	 * @return void
	 */
	public function testAMalformedReopeningDateIsLeftOut(): void {
		$binding = $this->binding(['limit' => ['count' => 1, 'per' => 'total'], 'reopensOn' => '5-1-2027']);

		$this->assertNull($this->availability(count: 3)->unavailable(binding: $binding, now: $this->at('2026-10-09 12:00'))['reopensOn']);
	}//end testAMalformedReopeningDateIsLeftOut()
}//end class
