<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeColumns\CasesCollections;
use PHPUnit\Framework\TestCase;

/**
 * The cases collections are gathered from every served audience's
 * aggregate, asked as a high-trust visitor with no identity of its own.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeColumns\CasesCollections
 */
class CasesCollectionsTest extends TestCase {

	public function testEveryAudienceIsAskedAndOnlyCasesCollectionsAreKept(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('servedAudiences')->willReturn(['resident', 'business']);
		$asked = [];
		$registry->method('aggregateFor')->willReturnCallback(
			static function (array $subject) use (&$asked): array {
				$asked[] = $subject;
				if ($subject['audience'] === 'resident') {
					return [
						'contributions' => [
							['collections' => [['kind' => 'cases', 'id' => 'zaken'], ['kind' => 'documents', 'id' => 'docs'], 'junk', ['id' => 'no-kind']]],
							'not a contribution',
							['collections' => 'not a list'],
							[],
						],
					];
				}

				return ['contributions' => [['collections' => [['kind' => 'cases', 'id' => 'bedrijfszaken']]]]];
			}
		);

		$this->assertSame(
			[['kind' => 'cases', 'id' => 'zaken'], ['kind' => 'cases', 'id' => 'bedrijfszaken']],
			(new CasesCollections($registry))->all()
		);
		$this->assertSame(
			[
				['audience' => 'resident', 'trust' => 'high', 'subjectRef' => '', 'organisation' => ''],
				['audience' => 'business', 'trust' => 'high', 'subjectRef' => '', 'organisation' => ''],
			],
			$asked
		);
	}

	public function testNoAudienceOrNoContributionsGivesNoCollections(): void {
		$none = $this->createMock(PortalContributionRegistry::class);
		$none->method('servedAudiences')->willReturn([]);
		$none->expects($this->never())->method('aggregateFor');
		$this->assertSame([], (new CasesCollections($none))->all());

		$empty = $this->createMock(PortalContributionRegistry::class);
		$empty->method('servedAudiences')->willReturn(['resident']);
		$empty->method('aggregateFor')->willReturn([]);
		$this->assertSame([], (new CasesCollections($empty))->all());
	}
}
