<?php

/**
 * Unit tests for finding the citizen write action of a record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CitizenWriteActionFinder;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The first `update` action on the record's register and schema that carries a
 * citizen write config wins, together with what its collection declares.
 */
#[CoversClass(CitizenWriteActionFinder::class)]
class CitizenWriteActionFinderTest extends TestCase {

	/**
	 * The contribution registry.
	 *
	 * @var PortalContributionRegistry&MockObject
	 */
	private PortalContributionRegistry&MockObject $registry;

	/**
	 * The finder under test.
	 *
	 * @var CitizenWriteActionFinder
	 */
	private CitizenWriteActionFinder $finder;

	/**
	 * Build the finder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registry = $this->createMock(PortalContributionRegistry::class);
		$this->finder   = new CitizenWriteActionFinder(registry: $this->registry);
	}//end setUp()

	/**
	 * The matching action is returned with its collection's download, documents, fields and closed field.
	 *
	 * @return void
	 */
	public function testTheMatchingActionAndItsCollection(): void {
		$subject = ['audience' => 'citizen', 'subjectRef' => 'bsn:1'];
		$action  = ['type' => 'update', 'register' => 'zaken', 'schema' => 'case', 'citizenWrite' => ['fields' => ['phone']]];
		$this->registry->expects($this->once())->method('aggregateFor')->with($subject)->willReturn(
			[
				'contributions' => [
					[
						'app'         => 'other',
						'actions'     => [
							['type' => 'create', 'register' => 'zaken', 'schema' => 'case', 'citizenWrite' => []],
							['type' => 'update', 'register' => 'zaken', 'schema' => 'other', 'citizenWrite' => []],
							['type' => 'update', 'register' => 'zaken', 'schema' => 'case', 'citizenWrite' => 'yes'],
						],
					],
					[
						'app'         => 'zaakafhandeling',
						'actions'     => [$action],
						'collections' => [
							'not a collection',
							['register' => 'zaken', 'schema' => 'other', 'filesDownload' => true, 'documents' => ['label' => 'X'], 'fields' => ['x'], 'closedField' => 'x'],
							['register' => 'zaken', 'schema' => 'case', 'filesDownload' => 'yes', 'fields' => null, 'closedField' => 7],
							['register' => 'zaken', 'schema' => 'case', 'filesDownload' => true, 'documents' => ['label' => 'Stukken'], 'fields' => ['title', 'status'], 'closedField' => 'closed'],
						],
					],
				],
			]
		);

		$this->assertSame(
			[
				'action'        => $action,
				'app'           => 'zaakafhandeling',
				'filesDownload' => true,
				'documents'     => ['label' => 'Stukken', 'provider' => ''],
				'fields'        => ['title', 'status'],
				'closedField'   => 'closed',
			],
			$this->finder->forSubject(subject: $subject, register: 'zaken', schema: 'case')
		);
	}//end testTheMatchingActionAndItsCollection()

	/**
	 * Without a matching collection the extras are empty.
	 *
	 * @return void
	 */
	public function testWithoutACollectionTheExtrasAreEmpty(): void {
		$action = ['type' => 'update', 'register' => 'r', 'schema' => 's', 'citizenWrite' => []];
		$this->registry->method('aggregateFor')->willReturn(['contributions' => [['actions' => [$action]]]]);

		$this->assertSame(
			['action' => $action, 'app' => '', 'filesDownload' => false, 'documents' => null, 'fields' => null, 'closedField' => ''],
			$this->finder->forSubject(subject: [], register: 'r', schema: 's')
		);
	}//end testWithoutACollectionTheExtrasAreEmpty()

	/**
	 * No matching action, or no contributions at all, finds nothing.
	 *
	 * @return void
	 */
	public function testNothingMatches(): void {
		$this->registry->method('aggregateFor')->willReturnOnConsecutiveCalls(
			[],
			['contributions' => [['actions' => [['type' => 'update', 'register' => 'r', 'schema' => 's']]]]]
		);

		$this->assertNull($this->finder->forSubject(subject: [], register: 'r', schema: 's'));
		$this->assertNull($this->finder->forSubject(subject: [], register: 'r', schema: 's'));
	}//end testNothingMatches()
}//end class
