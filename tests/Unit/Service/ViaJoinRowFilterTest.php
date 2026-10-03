<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Portaliq\Service\ViaJoinRowFilter;
use PHPUnit\Framework\TestCase;

/**
 * The live-row rule of a `via` join on its own: the edges the reader tests
 * do not reach (site-mijn-omgeving-components REQ-SMO-023).
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
 */
class ViaJoinRowFilterTest extends TestCase {

	private const NOW = '2026-10-02T12:00:00+00:00';

	/**
	 * Whether a row with this end date grants on 2 October 2026 at noon.
	 *
	 * @param mixed $expiresAt The stored end date.
	 *
	 * @return bool
	 */
	private function grantsUntil(mixed $expiresAt): bool {
		return (new ViaJoinRowFilter())->grants(
			row: ['share' => ['expiresAt' => $expiresAt]],
			via: ['validUntilField' => 'share.expiresAt'],
			now: new DateTimeImmutable(self::NOW)
		);
	}

	public function testADateWithoutATimeIsValidThroughThatDay(): void {
		$this->assertTrue($this->grantsUntil('2026-10-02'));
		$this->assertFalse($this->grantsUntil('2026-10-01'));
	}

	public function testATimeIsComparedToTheMoment(): void {
		$this->assertTrue($this->grantsUntil('2026-10-02T12:00:01+00:00'));
		$this->assertFalse($this->grantsUntil('2026-10-02T11:59:59+00:00'));
	}

	public function testAnEmptyOrMissingEndDateGrants(): void {
		$this->assertTrue($this->grantsUntil(null));
		$this->assertTrue($this->grantsUntil(''));
		$this->assertTrue(
			(new ViaJoinRowFilter())->grants(row: [], via: ['validUntilField' => 'share.expiresAt'], now: new DateTimeImmutable(self::NOW))
		);
	}

	public function testAnUnreadableEndDateGrantsNothing(): void {
		$this->assertFalse($this->grantsUntil('not a date'));
		$this->assertFalse($this->grantsUntil(20261231));
		$this->assertFalse($this->grantsUntil(['2026-12-31']));
	}

	public function testWhenComparesStrictly(): void {
		$filter = new ViaJoinRowFilter();
		$via = ['when' => ['field' => 'status', 'in' => ['active']]];

		$this->assertTrue($filter->grants(row: ['status' => 'active'], via: $via));
		$this->assertFalse($filter->grants(row: ['status' => 'Active'], via: $via));
		$this->assertFalse($filter->grants(row: [], via: $via));
	}

	public function testAViaWithoutLiveRowMembersIsValidAndGrants(): void {
		$filter = new ViaJoinRowFilter();

		$this->assertTrue($filter->isValid(via: ['schema' => 'enrolment']));
		$this->assertTrue($filter->grants(row: ['status' => 'withdrawn'], via: ['schema' => 'enrolment']));
	}

	public function testAWhenThatIsNotAnObjectOrHasAMapAsInIsInvalid(): void {
		$filter = new ViaJoinRowFilter();

		$this->assertFalse($filter->isValid(via: ['when' => 'status=active']));
		$this->assertFalse($filter->isValid(via: ['when' => ['field' => 'status', 'in' => ['a' => 'active']]]));
		$this->assertFalse($filter->isValid(via: ['when' => ['field' => 7, 'in' => ['active']]]));
		$this->assertTrue($filter->isValid(via: ['when' => ['in' => ['active', 1, true], 'field' => 'status'], 'validUntilField' => 'expiresAt']));
	}
}
