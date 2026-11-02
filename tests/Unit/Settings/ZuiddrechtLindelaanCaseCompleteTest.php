<?php
/**
 * Example data for the dossiq case boards (DqZaak, DqZaakTaken, DqZaakGerelateerd,
 * DqZaakBesluiten): the Lindelaan Woo case carries its priority pair, its legal
 * ground, a location, the actions planned next, the case it waits on, an open
 * advice request and a concept decision letter.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * What the case boards draw on the Lindelaan case is in the fragment.
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-has-sub-cases-a-subject-case-and-objects
 */
class ZuiddrechtLindelaanCaseCompleteTest extends TestCase {

	/**
	 * The fragment's objects, keyed, in file order.
	 *
	 * @return array<string, array<string, mixed>> The objects by key.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return array_column($set['objects'], null, 'key');
	}//end objects()

	/**
	 * The fragment's objects of one schema that point at the Lindelaan case.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The property that names the case.
	 *
	 * @return array<int, array<string, mixed>> The rows' data, in file order.
	 */
	private function onTheCase(string $schema, string $field='case'): array {
		return array_values(
			array_column(
				array_filter(
					$this->objects(),
					static fn (array $object): bool => ($object['schema'] === $schema) && (($object['data'][$field] ?? '') === '{{object:woo-fietspad.id}}')
				),
				'data'
			)
		);
	}//end onTheCase()

	/**
	 * The day offset a `{{date:n}}` placeholder names.
	 *
	 * @param string $value The placeholder.
	 *
	 * @return integer The offset in days.
	 */
	private function offsetOf(string $value): int {
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date:[+-]?\d+\}\}$/', string: $value);

		return (int)preg_replace('/[^0-9+-]/', '', $value);
	}//end offsetOf()

	/**
	 * Impact medium (the schema's default) and urgency high, so the matrix derives Hoog.
	 *
	 * @return void
	 */
	public function testTheCaseIsUrgentAtMediumImpact(): void {
		$woo = $this->objects()['woo-fietspad']['data'];
		$this->assertSame(expected: 'high', actual: $woo['urgency']);
		$this->assertSame(expected: 'medium', actual: ($woo['impact'] ?? 'medium'));
	}//end testTheCaseIsUrgentAtMediumImpact()

	/**
	 * The case names the article it is handled under, for the Termijn en betaling card.
	 *
	 * @return void
	 */
	public function testTheCaseNamesItsLegalGround(): void {
		$this->assertSame(expected: 'Woo art 4.4', actual: $this->objects()['woo-fietspad']['data']['legalBasis']);
	}//end testTheCaseNamesItsLegalGround()

	/**
	 * One location on the case, the stretch of the cycle path, at the case's own point to the two decimals
	 * OpenRegister keeps for a number.
	 *
	 * @return void
	 */
	public function testTheCaseHasOneLocation(): void {
		$locations = $this->onTheCase(schema: 'case-location');
		$this->assertCount(expectedCount: 1, haystack: $locations);
		$this->assertSame(expected: 'Fietspad Lindelaan, tussen nummer 2 en 40', actual: $locations[0]['label']);
		$point = json_decode($this->objects()['woo-fietspad']['data']['geometry'], true)['coordinates'];
		$this->assertSame(expected: [round($point[1], 2), round($point[0], 2)], actual: [$locations[0]['latitude'], $locations[0]['longitude']]);
	}//end testTheCaseHasOneLocation()

	/**
	 * Two actions are planned next: the decision for Pieter, then the term check for the Woo team.
	 *
	 * @return void
	 */
	public function testTwoActionsArePlannedNext(): void {
		$planned = $this->onTheCase(schema: 'plannedAction');
		$this->assertSame(
			expected: [
				['besluit-opstellen', 'Besluit opstellen', '{{cast:pieter.uid}}', 'planned'],
				['termijn-controleren', 'Termijn controleren', '{{cast:woo-en-informatie.gid}}', 'planned'],
			],
			actual: array_map(static fn (array $row): array => [$row['actionType'], $row['label'], self::ownerOf(row: $row), $row['state']], $planned)
		);
		$this->assertGreaterThan(expected: 0, actual: $this->offsetOf(value: $planned[0]['plannedFor']));
		$this->assertGreaterThan(expected: $this->offsetOf(value: $planned[0]['plannedFor']), actual: $this->offsetOf(value: $planned[1]['plannedFor']));
	}//end testTwoActionsArePlannedNext()

	/**
	 * Who a planned action is for, as its row reads it: the person, else the team.
	 *
	 * @param array<string, mixed> $row The planned action's data.
	 *
	 * @return string
	 */
	private static function ownerOf(array $row): string {
		if (($row['owner'] ?? '') !== '') {
			return $row['owner'];
		}

		return $row['ownerGroup'];
	}//end ownerOf()

	/**
	 * The case waits on Lars's advice case, which is written before it.
	 *
	 * @return void
	 */
	public function testTheCaseWaitsOnTheAdviceCase(): void {
		$objects = $this->objects();
		$keys    = array_keys($objects);
		$advice  = $objects['woo-fietspad-adviescase'];
		$this->assertSame(expected: 'case', actual: $advice['schema']);
		$this->assertSame(expected: 'Advies verlichting fietspad', actual: $advice['data']['title']);
		$this->assertSame(expected: '{{cast:lars.uid}}', actual: $advice['data']['assignee']);
		$this->assertSame(expected: 'adviesaanvraag', actual: $advice['lookups']['caseType']['where']['identifier']);
		$this->assertLessThan(expected: array_search('woo-fietspad', $keys, true), actual: array_search('woo-fietspad-adviescase', $keys, true));
		$this->assertSame(expected: ['{{object:woo-fietspad-adviescase.id}}'], actual: $objects['woo-fietspad']['data']['blockingCases']);
	}//end testTheCaseWaitsOnTheAdviceCase()

	/**
	 * The advice of Mobiliteit en verkeer is still open a week after the install, so the notice shows.
	 *
	 * @return void
	 */
	public function testTheAdviceRequestIsStillOpen(): void {
		$advice = $this->objects()['woo-fietspad-advies-verlichting']['data'];
		$this->assertSame(expected: 'requested', actual: $advice['status']);
		$this->assertGreaterThanOrEqual(expected: 7, actual: $this->offsetOf(value: $advice['deadline']));
	}//end testTheAdviceRequestIsStillOpen()

	/**
	 * A concept decision letter from the shipped partly-public template, version 3.
	 *
	 * @return void
	 */
	public function testTheCaseHasAConceptDecisionLetter(): void {
		$letters = $this->onTheCase(schema: 'beschikking', field: 'caseId');
		$this->assertCount(expectedCount: 1, haystack: $letters);
		$this->assertSame(expected: 'draft', actual: $letters[0]['currentStatus']);
		$this->assertSame(expected: ['woo-besluit-deels-openbaar', '3.0.0'], actual: [$letters[0]['templateId'], $letters[0]['templateVersion']]);
		$this->assertSame(expected: '{{cast:sanne.displayName}}', actual: $letters[0]['addressee']['name']);
	}//end testTheCaseHasAConceptDecisionLetter()

	/**
	 * Three flows ran on the case, each once and finished, at the moments the Flows card lists: the
	 * documents were linked and the receipt confirmed on the day it came in, and the status message
	 * failed the next morning, when the case went into handling.
	 *
	 * @return void
	 */
	public function testThreeFinishedFlowRunsOnTheCase(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$flows = array_column(($set['flows'] ?? []), 'data', 'key');
		$runs  = [];
		foreach ($flows as $flow) {
			foreach (($flow['runs'] ?? []) as $run) {
				$this->assertSame(
					expected: ['{{object:woo-fietspad.id}}', 'dossiq', 'case'],
					actual: [$run['subject'], $run['subjectRegister'], $run['subjectSchema']]
				);
				$runs[$flow['name']] = [$run['status'], $run['created']];
			}
		}

		$this->assertSame(
			expected: [
				'Documenten uit dossier koppelen'  => ['completed', '{{datetime:-14 09:20}}'],
				'Ontvangstbevestiging versturen'   => ['completed', '{{datetime:-14 09:34}}'],
				'Statusbericht naar de inwoner'    => ['failed', '{{datetime:-13 10:05}}'],
			],
			actual: $runs
		);
	}//end testThreeFinishedFlowRunsOnTheCase()
}//end class
