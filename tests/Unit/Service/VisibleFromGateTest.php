<?php

/**
 * VisibleFromGate tests: a row waits for its moment on the server clock; an
 * empty or unreadable moment does not hold a row back; a collection without
 * the field is untouched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\VisibleFromGate;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
 */
class VisibleFromGateTest extends TestCase {

	public function testARowWaitsUntilItsMomentHasPassed(): void {
		$now  = (int)strtotime('2026-10-05T09:00:00+02:00');
		$rows = [
			['id' => 'before', 'visibleFrom' => '2026-10-05T08:59:00+02:00'],
			['id' => 'exact', 'visibleFrom' => '2026-10-05T09:00:00+02:00'],
			['id' => 'after', 'visibleFrom' => '2026-10-05T09:01:00+02:00'],
			['id' => 'tomorrow', 'visibleFrom' => '2026-10-06'],
			['id' => 'empty', 'visibleFrom' => ''],
			['id' => 'garbage', 'visibleFrom' => 'binnenkort'],
			['id' => 'absent'],
		];

		$shown = array_column((new VisibleFromGate())->rows($rows, ['visibleFromField' => 'visibleFrom'], $now), 'id');

		$this->assertSame(['before', 'exact', 'empty', 'garbage', 'absent'], $shown);
	}//end testARowWaitsUntilItsMomentHasPassed()

	public function testACollectionWithoutTheFieldIsUntouched(): void {
		$rows = [['id' => 'a', 'visibleFrom' => '2999-01-01']];

		$this->assertSame($rows, (new VisibleFromGate())->rows($rows, [], 0));
		$this->assertSame($rows, (new VisibleFromGate())->rows($rows, ['visibleFromField' => ''], 0));
	}//end testACollectionWithoutTheFieldIsUntouched()

	public function testTheNormaliserProjectsTheFieldSoTheGateCanReadIt(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'cijfers', 'schema' => 'grade-notice', 'fields' => ['subject'], 'visibleFromField' => 'visibleFrom'],
					['id' => 'fout', 'schema' => 'grade-notice', 'fields' => ['subject'], 'visibleFromField' => 'visible from; drop'],
				],
				'actions' => [],
				'pages' => [],
			]
		);
		$byId = array_column($out['collections'], null, 'id');

		$this->assertSame('visibleFrom', $byId['cijfers']['visibleFromField']);
		$this->assertContains('visibleFrom', $byId['cijfers']['fields'], 'a field the projection would drop is added, so no row shows early');
		$this->assertArrayNotHasKey('visibleFromField', $byId['fout']);
	}//end testTheNormaliserProjectsTheFieldSoTheGateCanReadIt()
}//end class
