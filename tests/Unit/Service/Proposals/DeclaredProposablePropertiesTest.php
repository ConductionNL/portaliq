<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Proposals\DeclaredProposableProperties;
use PHPUnit\Framework\TestCase;

/**
 * change-proposals-reach-the-case-page REQ-CPC-003: the properties a record
 * takes a proposal on are the ones the contributions declare on a
 * propose-change for its register and schema, joined across audiences.
 *
 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md
 */
class DeclaredProposablePropertiesTest extends TestCase {

	/**
	 * A propose-change action.
	 *
	 * @param string       $schema     The schema it is on.
	 * @param array<mixed> $proposable Its allow-list.
	 *
	 * @return array<string, mixed>
	 */
	private static function propose(string $schema, array $proposable): array {
		return ['type' => 'propose-change', 'register' => 'dossiq', 'schema' => $schema, 'proposable' => $proposable];
	}//end propose()

	/**
	 * The service over a registry that answers one aggregate per audience.
	 *
	 * @param array<string, array<string, mixed>> $byAudience The aggregate per audience.
	 * @param array<int, array<string, mixed>>    $asked      The subjects the registry was asked for, filled in.
	 *
	 * @return DeclaredProposableProperties
	 */
	private function declared(array $byAudience, ?array &$asked = null): DeclaredProposableProperties {
		$asked    = [];
		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor', 'servedAudiences'])
			->getMock();
		$registry->method('servedAudiences')->willReturn(array_keys($byAudience));
		$registry->method('aggregateFor')->willReturnCallback(
			static function (array $subject) use ($byAudience, &$asked): array {
				$asked[] = $subject;
				return $byAudience[$subject['audience']];
			}
		);

		return new DeclaredProposableProperties(registry: $registry);
	}//end declared()

	public function testTheDeclarationsOfEveryAudienceAreJoinedEachOnce(): void {
		$declared = $this->declared([
			'employee' => ['contributions' => [['app' => 'dossiq', 'actions' => [self::propose('zaak', ['applicantPhone', 'applicantEmail'])]]]],
			'client' => ['contributions' => [
				['app' => 'dossiq', 'actions' => [self::propose('zaak', ['applicantEmail', 'applicantAddress'])]],
				['app' => 'other', 'actions' => [self::propose('task', ['title'])]],
			]],
		], $asked);

		$this->assertSame(['applicantPhone', 'applicantEmail', 'applicantAddress'], $declared->forRecord(register: 'dossiq', schema: 'zaak'));
		$this->assertSame([
			['audience' => 'employee', 'trust' => 'high', 'subjectRef' => '', 'organisation' => ''],
			['audience' => 'client', 'trust' => 'high', 'subjectRef' => '', 'organisation' => ''],
		], $asked);
	}//end testTheDeclarationsOfEveryAudienceAreJoinedEachOnce()

	public function testOtherActionsRegistersAndSchemasDeclareNothing(): void {
		$declared = $this->declared([
			'client' => ['contributions' => [
				['app' => 'dossiq', 'actions' => [
					['type' => 'update', 'register' => 'dossiq', 'schema' => 'zaak', 'proposable' => ['status']],
					['type' => 'propose-change', 'register' => 'elsewhere', 'schema' => 'zaak', 'proposable' => ['status']],
					self::propose('task', ['status']),
					self::propose('zaak', []),
					self::propose('zaak', [7, 'applicantPhone', null]),
					['type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'zaak', 'proposable' => 'applicantEmail'],
					'not an action',
				]],
				'not a contribution',
			]],
		]);

		$this->assertSame(['applicantPhone'], $declared->forRecord(register: 'dossiq', schema: 'zaak'));
		$this->assertSame([], $declared->forRecord(register: 'dossiq', schema: 'unknown'));
	}//end testOtherActionsRegistersAndSchemasDeclareNothing()

	public function testAnEmptyRegisterOrSchemaAsksNothing(): void {
		$declared = $this->declared(['client' => ['contributions' => [['app' => 'dossiq', 'actions' => [self::propose('zaak', ['x'])]]]]], $asked);

		$this->assertSame([], $declared->forRecord(register: '', schema: 'zaak'));
		$this->assertSame([], $declared->forRecord(register: 'dossiq', schema: ''));
		$this->assertSame([], $asked);
	}//end testAnEmptyRegisterOrSchemaAsksNothing()

	public function testMatchInKeepsEachActionWithItsAppAndItsStringEntries(): void {
		$first  = self::propose('zaak', ['applicantPhone']);
		$second = self::propose('zaak', [3]);
		$declared = $this->declared([]);

		$matches = $declared->matchIn(
			aggregate: ['contributions' => [['app' => 'dossiq', 'actions' => [$first]], ['actions' => [$second]]]],
			register: 'dossiq',
			schema: 'zaak'
		);

		$this->assertSame([
			['action' => $first, 'app' => 'dossiq', 'proposable' => ['applicantPhone']],
			['action' => $second, 'app' => '', 'proposable' => []],
		], $matches);
		$this->assertSame([], $declared->matchIn(aggregate: [], register: 'dossiq', schema: 'zaak'));
	}//end testMatchInKeepsEachActionWithItsAppAndItsStringEntries()
}//end class
