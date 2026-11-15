<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CitizenCaseProjection;
use OCA\Portaliq\Service\PortalFieldProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What of a case a resident sees: everything when no fields are declared,
 * else the declared fields plus what the case's actions and status need.
 */
#[CoversClass(CitizenCaseProjection::class)]
#[UsesClass(PortalFieldProjector::class)]
class CitizenCaseProjectionTest extends TestCase {

	private const CASE = [
		'id' => 'c-1',
		'uuid' => 'u-1',
		'title' => 'Permit',
		'status' => 'open',
		'phase' => 'review',
		'secret' => 'staff only',
		'note' => 'n',
		'answer' => 'a',
		'withdrawnAt' => '2026-11-01',
		'withdrawalReason' => 'moved',
		'@self' => ['id' => 'c-1', 'owner' => 'staff'],
	];

	private function projection(): CitizenCaseProjection {
		return new CitizenCaseProjection($this->createMock(LoggerInterface::class));
	}

	public function testWithoutDeclaredFieldsTheWholeCaseIsShown(): void {
		$this->assertSame(self::CASE, $this->projection()->visible([], self::CASE));
	}

	public function testDeclaredFieldsAreWidenedWithActionSetStatusAndWithdrawalFields(): void {
		$context = [
			'fields' => ['title'],
			'action' => ['fields' => ['note'], 'citizenWrite' => ['statusField' => 'phase']],
			'set' => ['fields' => ['answer' => ['type' => 'string']]],
		];

		$this->assertSame(
			[
				'title' => 'Permit',
				'note' => 'n',
				'answer' => 'a',
				'phase' => 'review',
				'withdrawnAt' => '2026-11-01',
				'withdrawalReason' => 'moved',
				'id' => 'c-1',
				'uuid' => 'u-1',
				'@self' => ['id' => 'c-1'],
			],
			$this->projection()->visible($context, self::CASE)
		);
	}

	public function testTheStatusFieldDefaultsToStatus(): void {
		$visible = $this->projection()->visible(['fields' => ['title']], self::CASE);

		$this->assertSame('open', $visible['status']);
		$this->assertArrayNotHasKey('secret', $visible);
		$this->assertArrayNotHasKey('phase', $visible);
	}

	public function testNonStringAndDuplicateFieldsAreDropped(): void {
		$visible = $this->projection()->visible(['fields' => ['title', 3, 'title', null]], self::CASE);

		$this->assertSame(['title', 'status', 'withdrawnAt', 'withdrawalReason', 'id', 'uuid', '@self'], array_keys($visible));
	}

	public function testANonArrayDeclarationProjectsToIdentifiersOnly(): void {
		$this->assertSame(
			['id' => 'c-1', 'uuid' => 'u-1', '@self' => ['id' => 'c-1']],
			$this->projection()->visible(['fields' => 'title'], self::CASE)
		);
	}
}
