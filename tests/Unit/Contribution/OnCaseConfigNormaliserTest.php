<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The `onCase` placement: kept when it rides on a declared cross reference,
 * dropped otherwise, and never takes its action with it.
 *
 * Runs through the full manifest normaliser, so the order against the
 * cross-reference normaliser is the production order.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-a-create-action-may-declare-a-place-on-the-case-page-req-coc-001
 */
class OnCaseConfigNormaliserTest extends TestCase {
	/**
	 * A sound declaration survives with the same values.
	 *
	 * @return void
	 */
	public function testAWellFormedDeclarationIsKept(): void {
		$action = $this->normalised(
			[
				'crossRef' => 'againstCaseId',
				'order' => 10,
				'window' => ['from' => 'decisionSentAt', 'days' => 42],
				'closedText' => 'De termijn voor bezwaar is voorbij.',
			]
		);

		$this->assertNotNull($action);
		$this->assertSame(
			[
				'crossRef' => 'againstCaseId',
				'order' => 10,
				'window' => ['from' => 'decisionSentAt', 'days' => 42],
				'closedText' => 'De termijn voor bezwaar is voorbij.',
			],
			$action['onCase']
		);
	}//end testAWellFormedDeclarationIsKept()

	/**
	 * Without a window or order the placement is kept with order 0.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutAWindowIsKept(): void {
		$action = $this->normalised(['crossRef' => 'againstCaseId']);

		$this->assertSame(['crossRef' => 'againstCaseId', 'order' => 0], $action['onCase'] ?? null);
	}//end testAPlacementWithoutAWindowIsKept()

	/**
	 * A crossRef the action does not declare drops the key, keeps the action.
	 *
	 * @return void
	 */
	public function testAnUnknownCrossRefIsDroppedAndTheActionKept(): void {
		$action = $this->normalised(['crossRef' => 'caseId']);

		$this->assertNotNull($action);
		$this->assertArrayNotHasKey('onCase', $action);
		$this->assertArrayHasKey('crossRefs', $action);
	}//end testAnUnknownCrossRefIsDroppedAndTheActionKept()

	/**
	 * Zero days, a field name that is no identifier, a string order and a
	 * non-string closed sentence each drop the key.
	 *
	 * @return void
	 */
	public function testMalformedPartsDropThePlacement(): void {
		$malformed = [
			['crossRef' => 'againstCaseId', 'window' => ['from' => 'decisionSentAt', 'days' => 0]],
			['crossRef' => 'againstCaseId', 'window' => ['from' => 'decision sent', 'days' => 42]],
			['crossRef' => 'againstCaseId', 'window' => 'decisionSentAt'],
			['crossRef' => 'againstCaseId', 'order' => '10'],
			['crossRef' => 'againstCaseId', 'closedText' => ['nl' => 'Voorbij']],
			'againstCaseId',
		];
		foreach ($malformed as $declared) {
			$action = $this->normalised($declared);
			$this->assertNotNull($action);
			$this->assertArrayNotHasKey('onCase', $action, json_encode($declared));
		}
	}//end testMalformedPartsDropThePlacement()

	/**
	 * A placement on a non-create action is dropped.
	 *
	 * @return void
	 */
	public function testAPlacementOnAnUpdateIsDropped(): void {
		$action = $this->normalised(['crossRef' => 'againstCaseId'], 'update');

		$this->assertNotNull($action);
		$this->assertArrayNotHasKey('onCase', $action);
	}//end testAPlacementOnAnUpdateIsDropped()

	/**
	 * dossiq's bezwaar with the given placement, normalised as the registry does.
	 *
	 * @param mixed  $onCase The declared placement.
	 * @param string $type   The action type.
	 *
	 * @return array<string, mixed>|null The normalised action, or null when it was dropped.
	 */
	private function normalised(mixed $onCase, string $type='create'): ?array {
		$manifest = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'createBezwaar',
						'type' => $type,
						'register' => 'dossiq',
						'schema' => 'portaalVerzoek',
						'label' => 'Bezwaar maken',
						'fields' => ['againstCaseId', 'grounds'],
						'crossRefs' => [
							'againstCaseId' => ['register' => 'dossiq', 'schema' => 'zaak', 'scopeField' => 'portalSubject', 'required' => true],
						],
						'onCase' => $onCase,
					],
				],
			]
		);

		return ($manifest['actions'][0] ?? null);
	}//end normalised()
}//end class
